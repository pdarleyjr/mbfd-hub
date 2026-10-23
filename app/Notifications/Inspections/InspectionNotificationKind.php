<?php

declare(strict_types=1);

namespace App\Notifications\Inspections;

/**
 * Notification kinds for the daily inspection continuity workflow
 * (docs/daily-inspection-continuity.md section 4.5). `observed_again` and
 * `issue_new` deliberately do not exist here: a known unresolved issue never
 * produces a daily repeat alert.
 */
final class InspectionNotificationKind
{
    public const REVIEW_REQUIRED = 'inspection_review_required';

    public const ISSUE_ESCALATED = 'issue_escalated';

    public const ISSUE_MEMBER_UPDATE = 'issue_member_update';

    public const INSPECTION_MESSAGE = 'inspection_message';

    public const CLARIFICATION_REQUESTED = 'clarification_requested';

    public const MEMBER_REPLY = 'member_reply';

    public const ISSUE_RESOLVED = 'issue_resolved';

    public const INSPECTION_DECIDED = 'inspection_decided';

    /** A member reported that a Daily Checkout checklist entry is wrong (to reviewers). */
    public const INVENTORY_CORRECTION_SUBMITTED = 'inventory_correction_submitted';

    /** Logistics asked, replied, decided or reversed an inventory correction (to the reporter). */
    public const INVENTORY_CORRECTION_UPDATE = 'inventory_correction_update';

    /** The reporting member replied on an inventory correction (to reviewers). */
    public const INVENTORY_CORRECTION_REPLY = 'inventory_correction_reply';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::REVIEW_REQUIRED,
            self::ISSUE_ESCALATED,
            self::ISSUE_MEMBER_UPDATE,
            self::INSPECTION_MESSAGE,
            self::CLARIFICATION_REQUESTED,
            self::MEMBER_REPLY,
            self::ISSUE_RESOLVED,
            self::INSPECTION_DECIDED,
            self::INVENTORY_CORRECTION_SUBMITTED,
            self::INVENTORY_CORRECTION_UPDATE,
            self::INVENTORY_CORRECTION_REPLY,
        ];
    }

    /** Kinds addressed to reviewers; every other kind is addressed to a member. */
    public static function isForReviewers(string $kind): bool
    {
        return in_array($kind, [self::REVIEW_REQUIRED, self::ISSUE_ESCALATED, self::MEMBER_REPLY, self::INVENTORY_CORRECTION_SUBMITTED, self::INVENTORY_CORRECTION_REPLY], true);
    }

    public static function icon(string $kind): string
    {
        return match ($kind) {
            self::REVIEW_REQUIRED => 'heroicon-o-clipboard-document-check',
            self::ISSUE_ESCALATED => 'heroicon-o-exclamation-triangle',
            self::CLARIFICATION_REQUESTED => 'heroicon-o-question-mark-circle',
            self::MEMBER_REPLY, self::INSPECTION_MESSAGE, self::ISSUE_MEMBER_UPDATE, self::INVENTORY_CORRECTION_REPLY, self::INVENTORY_CORRECTION_UPDATE => 'heroicon-o-chat-bubble-left-right',
            self::INVENTORY_CORRECTION_SUBMITTED => 'heroicon-o-pencil-square',
            self::ISSUE_RESOLVED => 'heroicon-o-check-circle',
            default => 'heroicon-o-clipboard-document-list',
        };
    }

    public static function color(string $kind): string
    {
        return match ($kind) {
            self::ISSUE_ESCALATED => 'danger',
            self::REVIEW_REQUIRED, self::CLARIFICATION_REQUESTED => 'warning',
            self::ISSUE_RESOLVED => 'success',
            default => 'info',
        };
    }
}
