<?php

declare(strict_types=1);

namespace App\Services\Captain5;

use App\Models\Captain5\Captain5AccessOverride;
use App\Models\Captain5\Captain5ItemClassification;
use App\Models\Employee;
use App\Models\User;
use App\Services\Security\Captain5Access;
use App\Services\Security\SecurityAuditRecorder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Captain 5 configuration decisions that only a Captain 5 administrator (captain5-admin) may
 * make: per-employee allow/deny overrides and medical-scope classification of ambiguous or new
 * checklist items. Every decision is a history row plus a security audit entry.
 */
final class Captain5AdministrationService
{
    /** Effective choices offered to an administrator. `automatic` removes the current override. */
    public const OVERRIDE_CHOICES = ['automatic', 'allow_view', 'allow_act', 'deny'];

    public function __construct(
        private readonly Captain5Access $access,
        private readonly MedicalScope $medical,
        private readonly SecurityAuditRecorder $audit,
    ) {}

    /** @throws AuthorizationException|ValidationException */
    public function setOverride(User $actor, int $employeeProfileId, string $choice, string $reason): ?Captain5AccessOverride
    {
        $this->authorize($actor);
        $reason = trim($reason);
        if (! in_array($choice, self::OVERRIDE_CHOICES, true)) {
            throw ValidationException::withMessages(['choice' => 'Choose automatic, allow (view), allow (act) or deny.']);
        }
        if ($reason === '' || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['reason' => 'Give a reason (up to 500 characters).']);
        }
        $employee = Employee::query()->find($employeeProfileId);
        if (! $employee instanceof Employee) {
            throw ValidationException::withMessages(['employee_profile_id' => 'That member does not exist.']);
        }
        $actorEmployee = $actor->employee_profile_id !== null ? (int) $actor->employee_profile_id : null;
        if ($actorEmployee === $employeeProfileId && $choice === 'deny') {
            throw ValidationException::withMessages(['choice' => 'You cannot deny your own access.']);
        }

        $override = DB::transaction(function () use ($actor, $employee, $choice, $reason): ?Captain5AccessOverride {
            $current = Captain5AccessOverride::query()->current()->where('employee_profile_id', $employee->getKey())->lockForUpdate()->first();
            $wanted = match ($choice) {
                'allow_view' => [Captain5AccessOverride::EFFECT_ALLOW, Captain5AccessOverride::LEVEL_VIEW],
                'allow_act' => [Captain5AccessOverride::EFFECT_ALLOW, Captain5AccessOverride::LEVEL_ACT],
                'deny' => [Captain5AccessOverride::EFFECT_DENY, null],
                default => null,
            };
            if ($current !== null && $wanted !== null && $current->effect === $wanted[0] && $current->level === $wanted[1]) {
                return $current; // already in that state
            }
            if ($current === null && $wanted === null) {
                return null;
            }
            $current?->forceFill(['superseded_at' => now(), 'superseded_by_user_id' => $actor->getKey()])->save();
            if ($wanted === null) {
                return null;
            }

            return Captain5AccessOverride::query()->create([
                'employee_profile_id' => $employee->getKey(),
                'effect' => $wanted[0],
                'level' => $wanted[1],
                'reason' => $reason,
                'set_by_user_id' => $actor->getKey(),
            ]);
        }, 3);

        $this->record($actor, $employee, 'change_captain5_access', ['choice' => $choice, 'override_id' => $override?->getKey()], $reason);
        $this->access->flush();

        return $override;
    }

    /** @throws AuthorizationException|ValidationException */
    public function classify(User $actor, string $checklistType, string $itemKey, string $itemName, string $classification, string $reason): Captain5ItemClassification
    {
        $this->authorize($actor);
        if (! in_array($checklistType, MedicalScope::MEDICAL_ONLY_TYPES, true)) {
            throw ValidationException::withMessages(['checklist_type' => 'Only Engine and Ladder templates have a medical classification.']);
        }
        if (! in_array($classification, [Captain5ItemClassification::MEDICAL, Captain5ItemClassification::NOT_MEDICAL], true)) {
            throw ValidationException::withMessages(['classification' => 'Choose medical or not medical.']);
        }
        $itemKey = trim($itemKey);
        $reason = trim($reason);
        if ($itemKey === '' || mb_strlen($itemKey) > 128) {
            throw ValidationException::withMessages(['item_key' => 'The checklist item is missing.']);
        }
        if ($reason === '' || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['reason' => 'Give a reason (up to 500 characters).']);
        }

        // Append-only: the previous decision stays as history, superseded by this one.
        $row = DB::transaction(function () use ($actor, $checklistType, $itemKey, $itemName, $classification, $reason): Captain5ItemClassification {
            $current = Captain5ItemClassification::query()->current()
                ->where('checklist_type', $checklistType)->where('item_key', $itemKey)->lockForUpdate()->first();
            if ($current !== null && $current->classification === $classification && $current->reason === $reason) {
                return $current; // same decision resubmitted
            }
            $current?->forceFill(['superseded_at' => now(), 'superseded_by_user_id' => $actor->getKey()])->save();

            return Captain5ItemClassification::query()->create([
                'checklist_type' => $checklistType,
                'item_key' => $itemKey,
                'item_name' => mb_substr(trim($itemName) ?: $itemKey, 0, 255),
                'classification' => $classification,
                'reason' => $reason,
                'decided_by_user_id' => $actor->getKey(),
            ]);
        }, 3);
        Log::info('captain5.medical_scope_classified', [
            'actor_user_id' => $actor->getKey(),
            'checklist_type' => $checklistType,
            'item_key' => $itemKey,
            'classification' => $classification,
        ]);
        $this->medical->flush();

        return $row;
    }

    private function authorize(User $actor): void
    {
        if (! $this->access->decide($actor)->canConfigure) {
            throw new AuthorizationException('Only Captain 5 administrators can change Captain 5 configuration.');
        }
    }

    /** @param array<string, mixed> $metadata */
    private function record(User $actor, Employee $employee, string $action, array $metadata, string $reason): void
    {
        try {
            $target = User::query()->where('employee_profile_id', $employee->getKey())->orderBy('id')->first();
            if ($target instanceof User) {
                $this->audit->record($actor, $target, $action, 'success', $reason, $metadata);
            }
        } catch (\Throwable $exception) {
            Log::warning('Captain 5 access audit trail could not be written', ['action' => $action, 'exception' => $exception->getMessage()]);
        }
    }
}
