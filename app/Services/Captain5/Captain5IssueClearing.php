<?php

declare(strict_types=1);

namespace App\Services\Captain5;

use App\Models\ApparatusDefect;
use App\Models\ApparatusDefectEvent;
use App\Models\User;
use App\Services\Defects\DefectCorrectiveActionService;
use App\Services\Defects\StaleDefectStateException;
use App\Services\Security\Captain5AccessDecision;
use App\Support\Fleet\DefectActionStatus;
use App\Support\Fleet\PostgresLockRetry;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * "Clear, this is for Logistics" and "Bring back". This is a Captain 5 page filter, not a step in the issue's life:
 * the status, the Logistics queues and the crew are untouched, so it writes two columns and one history row and
 * never goes through the corrective-action engine, which would enroll the actor and notify people. It keeps the
 * engine's rules: same idempotency key shape, same expected_state check, same history table.
 */
final class Captain5IssueClearing
{
    public const ACTION_CLEAR = 'send_to_logistics';

    public const ACTION_BRING_BACK = 'bring_back_to_captain5';

    public const ACTIONS = [self::ACTION_CLEAR, self::ACTION_BRING_BACK];

    public const EVENT_CLEARED = 'cleared_from_captain5';

    public const EVENT_RETURNED = 'returned_to_captain5';

    /**
     * @throws AuthorizationException|ValidationException|StaleDefectStateException
     */
    public function perform(User $actor, Captain5AccessDecision $decision, int $defectId, string $action, string $expectedState, string $key, ?string $note): ApparatusDefect
    {
        $context = Captain5ActorContext::from($decision);
        if (! $context->mayPerform($action)) {
            throw new AuthorizationException('You can view this workspace but not act on issues.');
        }
        $storedKey = 'defect:'.$defectId.':'.$action.':'.$key;
        // The role is not 'captain5': that role subscribes a person to later replies on the issue, and someone
        // handing an issue to Logistics has asked not to follow it. The event label then reads as the plain name.
        $audit = ['role' => 'captain5_clear'] + $context->toAudit();

        return PostgresLockRetry::run(fn (): ApparatusDefect => DB::transaction(function () use ($actor, $defectId, $action, $expectedState, $storedKey, $note, $audit): ApparatusDefect {
            $defect = ApparatusDefect::query()->lockForUpdate()->findOrFail($defectId);

            if (ApparatusDefectEvent::query()->where('idempotency_key', $storedKey)->exists()) {
                return $defect; // a retry of an action that already took effect
            }
            if (! hash_equals(DefectCorrectiveActionService::stateToken($defect), $expectedState)) {
                throw new StaleDefectStateException($defect);
            }
            if (DefectActionStatus::isClosed($defect->action_status)) {
                throw ValidationException::withMessages(['defect' => 'This issue is closed. It is under History now.']);
            }
            $clearing = $action === self::ACTION_CLEAR;
            if ($clearing === self::isCleared($defect)) {
                return $defect; // already where this action would put it
            }

            $this->mark($defect, $clearing ? $actor : null);
            $status = (string) ($defect->action_status ?: DefectActionStatus::REPORTED);
            $trimmed = $note === null ? '' : trim($note);
            ApparatusDefectEvent::query()->create([
                'apparatus_defect_id' => $defect->getKey(),
                'apparatus_inspection_id' => null,
                'actor_user_id' => $actor->getKey(),
                'event_type' => $clearing ? self::EVENT_CLEARED : self::EVENT_RETURNED,
                'from_status' => $status,
                'to_status' => $status,
                'member_message' => null, // the crew is never told
                'internal_note' => $trimmed === '' ? null : $trimmed,
                'details' => ['actor_context' => $audit],
                'idempotency_key' => $storedKey,
            ]);

            return $defect->refresh();
        }, 3));
    }

    /** A closed issue that Captain 5 reopens comes back to their own queue: they just asked to work it. */
    public function forget(ApparatusDefect $defect): ApparatusDefect
    {
        if (self::isCleared($defect)) {
            $this->mark($defect, null);
        }

        return $defect;
    }

    public static function isCleared(ApparatusDefect $defect): bool
    {
        return $defect->captain5_cleared_at !== null;
    }

    /** Quiet and without touching updated_at: as far as Logistics and the observers can tell, nothing changed. */
    private function mark(ApparatusDefect $defect, ?User $clearedBy): void
    {
        $defect->timestamps = false;
        $defect->forceFill([
            'captain5_cleared_at' => $clearedBy === null ? null : now(),
            'captain5_cleared_by_user_id' => $clearedBy?->getKey(),
        ])->saveQuietly();
        $defect->timestamps = true;
    }
}
