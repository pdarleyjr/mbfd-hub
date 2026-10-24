<?php

declare(strict_types=1);

namespace App\Notifications\Inspections;

use App\Models\ApparatusDefect;
use App\Models\ApparatusInspection;
use App\Models\ApparatusIssueMessage;
use App\Models\User;
use App\Services\Notifications\NotificationCategoryRegistry;
use App\Services\Notifications\NotificationRecipientEvaluator;
use Illuminate\Support\Facades\DB;

/**
 * Which registry category an inspection or Captain 5 notification belongs to FOR ONE RECIPIENT.
 *
 * Most kinds map straight from the kind (InspectionNotificationKind::category). A few Captain 5
 * kinds (issue update, issue resolved, message) reach two audiences: Captain 5 officers working
 * the issue, and the members who own it. A member's update about their own report or Daily
 * Checkout is always a "member request update" (in-app locked on), whatever other access the
 * person has; only officers are held to Captain 5 access.
 */
final class InspectionNotificationCategory
{
    /** Participant enrollments that mean "works the issue" rather than "owns or follows it". */
    private const OFFICER_SOURCES = ['captain5', 'assigned'];

    public static function for(string $kind, User $recipient, ?string $eventKey = null): string
    {
        $default = InspectionNotificationKind::category($kind);
        if ($default !== NotificationCategoryRegistry::CAPTAIN5_EMS || ! InspectionNotificationKind::isSharedWithMembers($kind)) {
            return $default;
        }

        return match (self::party($recipient, $eventKey)) {
            'owner' => User::NOTIFICATION_PREFERENCE_MEMBER_REQUEST_UPDATES,
            'officer' => NotificationCategoryRegistry::CAPTAIN5_EMS,
            // Crew and other followers: Captain 5 alerts only while they hold Captain 5 access.
            default => app(NotificationRecipientEvaluator::class)->isEligible($recipient, NotificationCategoryRegistry::CAPTAIN5_EMS)
                ? NotificationCategoryRegistry::CAPTAIN5_EMS
                : User::NOTIFICATION_PREFERENCE_MEMBER_REQUEST_UPDATES,
        };
    }

    /** @return 'owner'|'officer'|null */
    private static function party(User $recipient, ?string $eventKey): ?string
    {
        $parts = explode(':', (string) $eventKey);
        if ($parts[0] !== 'captain5') {
            return null;
        }

        $defectId = null;
        $inspectionId = null;
        if (($parts[1] ?? '') === 'defect') {
            $defectId = (int) ($parts[2] ?? 0);
        } elseif (($parts[1] ?? '') === 'message') {
            $message = ApparatusIssueMessage::query()->find((int) ($parts[2] ?? 0), ['id', 'apparatus_defect_id', 'apparatus_inspection_id']);
            $defectId = $message?->apparatus_defect_id;
            $inspectionId = $message?->apparatus_inspection_id;
        }

        if ($defectId !== null && $defectId > 0) {
            return self::defectParty($recipient, (int) $defectId);
        }
        if ($inspectionId !== null && self::ownsInspection($recipient, (int) $inspectionId)) {
            return 'owner';
        }

        return null;
    }

    /** @return 'owner'|'officer'|null */
    private static function defectParty(User $recipient, int $defectId): ?string
    {
        $defect = ApparatusDefect::query()->find($defectId, ['id', 'first_reported_by_user_id']);
        if ($defect !== null && (int) $defect->first_reported_by_user_id === (int) $recipient->getKey()) {
            return 'owner';
        }

        $source = DB::table('apparatus_defect_participants')
            ->where('apparatus_defect_id', $defectId)
            ->where('user_id', $recipient->getKey())
            ->value('enrollment_source');

        return match (true) {
            $source === null => null,
            in_array($source, self::OFFICER_SOURCES, true) => 'officer',
            default => 'owner',
        };
    }

    private static function ownsInspection(User $recipient, int $inspectionId): bool
    {
        $inspection = ApparatusInspection::query()->find($inspectionId, ['id', 'actor_user_id', 'employee_id']);
        if ($inspection === null) {
            return false;
        }

        return ($inspection->actor_user_id !== null && (int) $inspection->actor_user_id === (int) $recipient->getKey())
            || ($inspection->employee_id !== null && (int) $inspection->employee_id === (int) $recipient->employee_profile_id);
    }
}
