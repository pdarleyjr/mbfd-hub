<?php

declare(strict_types=1);

namespace App\Services\Captain5;

use App\Models\ApparatusDefect;
use App\Models\User;
use App\Services\Defects\DefectOwnership;
use App\Services\Defects\StaleDefectStateException;
use App\Services\Security\Captain5AccessDecision;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * "Clear, this is for Logistics" and "Bring back". Since November 2026 these are real ownership transfers
 * (docs/unified-activity-and-ownership.md section 1): the issue leaves Captain 5's queue and enters Logistics', or
 * the reverse, with an owner_transferred event. The status and the crew are untouched and nobody is enrolled or
 * notified: the receiving queue shows it. DefectOwnership keeps the engine's rules (idempotency key, expected state,
 * closed issues refused) and keeps captain5_cleared_at in step for the previous image.
 */
final class Captain5IssueClearing
{
    public const ACTION_CLEAR = 'send_to_logistics';

    public const ACTION_BRING_BACK = 'bring_back_to_captain5';

    public const ACTIONS = [self::ACTION_CLEAR, self::ACTION_BRING_BACK];

    /** Events written before ownership existed; still rendered in history. */
    public const EVENT_CLEARED = 'cleared_from_captain5';

    public const EVENT_RETURNED = 'returned_to_captain5';

    public function __construct(private readonly DefectOwnership $ownership) {}

    /**
     * @throws AuthorizationException|ValidationException|StaleDefectStateException
     */
    public function perform(User $actor, Captain5AccessDecision $decision, int $defectId, string $action, string $expectedState, string $key, ?string $note): ApparatusDefect
    {
        $context = Captain5ActorContext::from($decision);
        if (! $context->mayPerform($action)) {
            throw new AuthorizationException('You can view this workspace but not act on issues.');
        }
        // The role is not 'captain5': that role subscribes a person to later replies on the issue, and someone
        // handing an issue to Logistics has asked not to follow it. The event label then reads as the plain name.
        $audit = ['role' => 'captain5_clear'] + $context->toAudit();
        $to = $action === self::ACTION_CLEAR ? ApparatusDefect::OWNER_LOGISTICS : ApparatusDefect::OWNER_CAPTAIN5;

        return $this->ownership->transfer($actor, $defectId, $to, $expectedState, $key, $note, $audit);
    }

    /**
     * Reopening already returns an issue to the owner its equipment belongs to (DefectCorrectiveActionService::reopen),
     * which is Captain 5 for anything Captain 5 could reopen. Kept for callers.
     */
    public function forget(ApparatusDefect $defect): ApparatusDefect
    {
        return $defect;
    }

    /** Moved from Captain 5 to Logistics by a person (Captain 5's "sent to Logistics" list). */
    public static function isCleared(ApparatusDefect $defect): bool
    {
        return $defect->captain5_cleared_at !== null && $defect->getAttribute('responsible_owner') !== ApparatusDefect::OWNER_CAPTAIN5;
    }
}
