<?php

declare(strict_types=1);

namespace App\Services\Captain5;

use App\Models\Captain5\Captain5AccessOverride;
use App\Models\Captain5\Captain5DutyAssignment;
use App\Models\Employee;
use App\Models\User;
use App\Services\Security\Captain5Access;
use App\Services\Security\SecurityAuditRecorder;
use App\Support\Captain5\DutyCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Controlled acting (out-of-class) Captain 5 assignments. The only way anyone other than the
 * on-duty Captain 5 bid holder receives on-duty authority; never inferred from rank.
 */
final class Captain5AssignmentService
{
    public function __construct(
        private readonly Captain5Access $access,
        private readonly DutyCalendar $calendar,
        private readonly SecurityAuditRecorder $audit,
    ) {}

    /**
     * @param  array{employee_profile_id?: mixed, duty_date?: mixed, reason?: mixed, client_request_id?: mixed}  $data
     *
     * @throws AuthorizationException|ValidationException
     */
    public function assign(User $actor, array $data): Captain5DutyAssignment
    {
        $this->authorize($actor);
        $valid = Validator::make($data, [
            'employee_profile_id' => ['required', 'integer', 'min:1'],
            'duty_date' => ['required', 'date_format:Y-m-d'],
            'reason' => ['required', 'string', 'max:500'],
            'client_request_id' => ['nullable', 'string', 'regex:/^[A-Za-z0-9-]{8,64}$/D'],
        ])->validate();

        if (! empty($valid['client_request_id'])
            && ($existing = Captain5DutyAssignment::query()->where('client_request_id', $valid['client_request_id'])->first()) !== null) {
            if ((int) $existing->assigned_by_user_id !== (int) $actor->getKey()) {
                throw new AuthorizationException('That request id belongs to another person.');
            }

            return $existing; // retry of a request that already took effect
        }

        $employee = Employee::query()->find((int) $valid['employee_profile_id']);
        if (! $employee instanceof Employee || $employee->roster_status === 'departed') {
            throw ValidationException::withMessages(['employee_profile_id' => 'Choose a current member.']);
        }
        if ((int) $actor->employee_profile_id === (int) $employee->getKey()) {
            throw ValidationException::withMessages(['employee_profile_id' => 'Someone else assigns you as acting Captain 5.']);
        }
        if (! User::query()->where('employee_profile_id', $employee->getKey())->get()->contains(fn (User $user): bool => $user->isAuthenticationAllowed())) {
            throw ValidationException::withMessages(['employee_profile_id' => 'This member has no active Hub account, so they could not act as Captain 5.']);
        }
        if (Captain5AccessOverride::query()->current()->where('employee_profile_id', $employee->getKey())->where('effect', Captain5AccessOverride::EFFECT_DENY)->exists()) {
            throw ValidationException::withMessages(['employee_profile_id' => 'This member\'s Captain 5 access is denied. Change that first in Captain 5 Operations.']);
        }
        $today = $this->calendar->dutyDate();
        $dutyDate = CarbonImmutable::createFromFormat('!Y-m-d', $valid['duty_date'], $this->calendar->timezone());
        if ($dutyDate->lt($today) || $dutyDate->gt($today->addDays(14))) {
            throw ValidationException::withMessages(['duty_date' => 'Choose today\'s duty day or a duty day in the next two weeks.']);
        }
        [$start, $end] = $this->calendar->window($valid['duty_date']);

        try {
            $assignment = DB::transaction(function () use ($actor, $employee, $valid, $start, $end): Captain5DutyAssignment {
                $current = Captain5DutyAssignment::query()->active()->whereDate('duty_date', $valid['duty_date'])->lockForUpdate()->first();
                if ($current !== null) {
                    throw ValidationException::withMessages([
                        'duty_date' => 'An acting Captain 5 is already assigned for that duty day. End that assignment first.',
                    ]);
                }

                return Captain5DutyAssignment::query()->create([
                    'employee_profile_id' => $employee->getKey(),
                    'duty_date' => $valid['duty_date'],
                    'starts_at' => $start,
                    'ends_at' => $end,
                    'shift_letter' => $this->calendar->shiftFor($valid['duty_date']),
                    'reason' => trim((string) $valid['reason']),
                    'assigned_by_user_id' => $actor->getKey(),
                    'client_request_id' => $valid['client_request_id'] ?? null,
                ]);
            }, 3);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'duty_date' => 'An acting Captain 5 was just assigned for that duty day by someone else. Refresh to see it.',
            ]);
        }

        $this->record($actor, $employee, 'captain5_acting_assigned', [
            'assignment_id' => $assignment->getKey(),
            'duty_date' => $valid['duty_date'],
            'shift' => $assignment->shift_letter,
        ], $assignment->reason);
        $this->access->flush();

        return $assignment;
    }

    /** @throws AuthorizationException|ValidationException */
    public function revoke(User $actor, int $assignmentId, string $reason): Captain5DutyAssignment
    {
        $this->authorize($actor);
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['reason' => 'Give a reason (up to 500 characters).']);
        }

        $assignment = DB::transaction(function () use ($actor, $assignmentId, $reason): Captain5DutyAssignment {
            $assignment = Captain5DutyAssignment::query()->lockForUpdate()->findOrFail($assignmentId);
            if ($assignment->revoked_at !== null) {
                return $assignment; // already ended (idempotent)
            }
            $assignment->forceFill([
                'revoked_at' => now(),
                'revoked_by_user_id' => $actor->getKey(),
                'revocation_reason' => $reason,
            ])->save();

            return $assignment;
        }, 3);

        if ($assignment->revoked_by_user_id === $actor->getKey() && ($employee = $assignment->employee) instanceof Employee) {
            $this->record($actor, $employee, 'captain5_acting_revoked', ['assignment_id' => $assignment->getKey()], $reason);
        }
        $this->access->flush();

        return $assignment;
    }

    private function authorize(User $actor): void
    {
        if (! $this->access->decide($actor)->canManageAssignments) {
            throw new AuthorizationException('Only Captain 5 administrators, the EMS Captain or Division Chiefs can assign an acting Captain 5.');
        }
    }

    /** @param array<string, mixed> $metadata */
    private function record(User $actor, Employee $employee, string $action, array $metadata, ?string $reason): void
    {
        // The assignment row is the primary record; the security audit is an extra trail when the
        // member has an account. Never let the trail break the assignment itself.
        try {
            $target = User::query()->where('employee_profile_id', $employee->getKey())->orderBy('id')->first();
            if ($target instanceof User) {
                $this->audit->record($actor, $target, $action, 'success', $reason, $metadata);
            }
        } catch (\Throwable $exception) {
            Log::warning('Captain 5 assignment audit trail could not be written', ['action' => $action, 'exception' => $exception->getMessage()]);
        }
    }
}
