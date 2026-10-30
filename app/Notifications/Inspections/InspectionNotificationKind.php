<?php

declare(strict_types=1);

namespace App\Notifications\Inspections;

use App\Models\User;
use App\Services\Captain5\Captain5Notifier;
use App\Services\Narcotics\NarcoticsNotifier;
use App\Services\Notifications\NotificationCategoryRegistry;
use Illuminate\Support\Facades\Log;

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

    /**
     * THE kind -> registry category table. Adding a kind is one line here.
     * Captain 5 kinds are sent by Captain5Notifier (docs/captain5-ems-operations-center.md section 7).
     *
     * @return array<string, string>
     */
    public static function categoryTable(): array
    {
        $reviews = User::NOTIFICATION_PREFERENCE_VEHICLE_INSPECTIONS;
        $own = User::NOTIFICATION_PREFERENCE_MEMBER_REQUEST_UPDATES;
        $captain5 = NotificationCategoryRegistry::CAPTAIN5_EMS;

        return [
            self::REVIEW_REQUIRED => $reviews,
            self::ISSUE_ESCALATED => $reviews,
            self::MEMBER_REPLY => $reviews,
            self::INVENTORY_CORRECTION_SUBMITTED => $reviews,
            self::INVENTORY_CORRECTION_REPLY => $reviews,
            self::ISSUE_MEMBER_UPDATE => $own,
            self::INSPECTION_MESSAGE => $own,
            self::CLARIFICATION_REQUESTED => $own,
            self::ISSUE_RESOLVED => $own,
            self::INSPECTION_DECIDED => $own,
            self::INVENTORY_CORRECTION_UPDATE => $own,
            Captain5Notifier::KIND_NEW_FINDINGS => $captain5,
            Captain5Notifier::KIND_ESCALATED => $captain5,
            Captain5Notifier::KIND_ISSUE_UPDATE => $captain5,
            Captain5Notifier::KIND_ISSUE_RESOLVED => $captain5,
            Captain5Notifier::KIND_MESSAGE => $captain5,
            Captain5Notifier::KIND_MEMBER_REPLY => $captain5,
            Captain5Notifier::KIND_COMMUNICATION => $captain5,
            // Narcotics check (docs/narcotics-check.md): Captain 5 reviewers hear of a new check; the member hears the decision.
            NarcoticsNotifier::KIND_SUBMITTED => $captain5,
            NarcoticsNotifier::KIND_DECISION => $own,
            // Sent to whoever may approve acting Captain 5 assignments; they need not have workspace access.
            'captain5_acting_request' => NotificationCategoryRegistry::CAPTAIN5_ASSIGNMENT_REQUESTS,
            // Tells the requesting member about their own request.
            'captain5_acting_decision' => $own,
        ];
    }

    /**
     * Captain 5 kinds whose recipients include members who follow the issue (the
     * reporter, the crew, people who replied), not only Captain 5 officers.
     */
    public static function isSharedWithMembers(string $kind): bool
    {
        return in_array($kind, [Captain5Notifier::KIND_ISSUE_UPDATE, Captain5Notifier::KIND_ISSUE_RESOLVED, Captain5Notifier::KIND_MESSAGE], true);
    }

    /** @var array<string, true> kinds already warned about in this process */
    private static array $warnedUnknown = [];

    /**
     * The registry category a kind belongs to when the recipient has no special
     * relationship to the subject. A kind missing from the table (a sender added
     * before its mapping) is never dropped and never widened: it keeps the category
     * the code used before the table existed (reviewer kinds -> Daily Checkout
     * reviews, anything else -> an update about the member's own request), and a
     * warning is logged once. The sender already chose its recipients.
     * For kinds shared with members use InspectionNotificationCategory::for().
     */
    public static function category(string $kind): string
    {
        $mapped = self::categoryTable()[$kind] ?? null;
        if ($mapped !== null) {
            return $mapped;
        }
        if (! isset(self::$warnedUnknown[$kind])) {
            self::$warnedUnknown[$kind] = true;
            Log::warning('Inspection notification kind has no category mapping; using the legacy category', ['kind' => $kind]);
        }

        return self::isForReviewers($kind)
            ? User::NOTIFICATION_PREFERENCE_VEHICLE_INSPECTIONS
            : User::NOTIFICATION_PREFERENCE_MEMBER_REQUEST_UPDATES;
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
