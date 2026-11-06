<?php

declare(strict_types=1);

namespace App\Support\MemberReplies;

use App\Models\ApparatusServiceTicket;
use App\Models\StationRequest;
use App\Models\StationSupplyRequest;
use App\Models\User;
use App\Support\Stations\StationRequestMemberAccess;
use Illuminate\Database\Eloquent\Model;

/**
 * Who may read and write the conversation on a station request, station supply request or apparatus service
 * ticket (docs/member-replies.md section 3). Deliberately not Gate abilities, like InventoryCorrectionAccess:
 * Gate::before / AdminCapabilityGate would map a member ability onto admin.* permissions and hide it from the
 * member it is for.
 *
 * Participants are decided from the stored row and the signed-in account only, never from the request:
 *  - station request: the member who submitted it (account, or linked employee profile);
 *  - supply request: the member who submitted it (account, or linked employee profile);
 *  - service ticket: the member it was requested by (linked employee profile).
 * Staff are the people who may already manage that record in the admin console.
 */
final class SubmissionConversationAccess
{
    public static function isParticipant(?User $user, string $type, Model $subject): bool
    {
        if (! $user instanceof User || ! $user->isAuthenticationAllowed()) {
            return false;
        }

        return match (true) {
            $type === ReplySubject::STATION_REQUEST && $subject instanceof StationRequest => StationRequestMemberAccess::isRequester($user, $subject),
            $type === ReplySubject::STATION_SUPPLY_REQUEST && $subject instanceof StationSupplyRequest => self::sameAccountOrProfile($user, $subject->getAttribute('actor_user_id'), $subject->getAttribute('actor_employee_id')),
            $type === ReplySubject::APPARATUS_SERVICE_TICKET && $subject instanceof ApparatusServiceTicket => self::ticketRequester($user, $subject),
            default => false,
        };
    }

    /** May answer the member from the admin console. */
    public static function canStaffReply(?User $user, string $type, Model $subject): bool
    {
        if (! $user instanceof User || ! $user->isAuthenticationAllowed()) {
            return false;
        }

        return match ($type) {
            ReplySubject::STATION_REQUEST, ReplySubject::STATION_SUPPLY_REQUEST => $user->can('admin.stations.manage'),
            ReplySubject::APPARATUS_SERVICE_TICKET => $user->can('update', $subject),
            default => false,
        };
    }

    /** May read the conversation from the admin console. */
    public static function canStaffRead(?User $user, string $type, Model $subject): bool
    {
        if (! $user instanceof User || ! $user->isAuthenticationAllowed()) {
            return false;
        }

        return match ($type) {
            ReplySubject::STATION_REQUEST, ReplySubject::STATION_SUPPLY_REQUEST => $user->can('admin.stations.view'),
            ReplySubject::APPARATUS_SERVICE_TICKET => $user->can('view', $subject),
            default => false,
        };
    }

    private static function ticketRequester(User $user, ApparatusServiceTicket $ticket): bool
    {
        // Only a station-filed ticket has a member requester; a Fleet-created ticket's creator is staff.
        $employeeId = $ticket->getAttribute('requested_by_employee_id');

        return $employeeId !== null && $user->employee_profile_id !== null && (int) $employeeId === (int) $user->employee_profile_id;
    }

    private static function sameAccountOrProfile(User $user, mixed $userId, mixed $employeeId): bool
    {
        if ($userId !== null && (int) $userId === (int) $user->getKey()) {
            return true;
        }

        return $employeeId !== null && $user->employee_profile_id !== null && (int) $employeeId === (int) $user->employee_profile_id;
    }
}
