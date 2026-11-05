<?php

declare(strict_types=1);

namespace App\Support\Stations;

use App\Models\StationRequest;
use App\Models\User;

/**
 * Who may mark a station request resolved from the Daily app. Deliberately not a Gate ability: this is a member
 * action, not an admin one, and Gate::before / AdminCapabilityGate would map it onto admin.* permissions and hide
 * it from the very people it is for (floaters, other stations, maintenance workers). Mirrors InventoryCorrectionAccess.
 *
 * The server decides from the signed-in account and the stored request only. A client's claimed role, station or
 * "I am the requester" is never read.
 */
final class StationRequestMemberAccess
{
    /** Shortest note an eligible member who did not submit the request must give. */
    public const MIN_OTHER_NOTE_LENGTH = 5;

    /** Active account with a current employee profile. Training-only logins have no profile and are refused. */
    public static function isEligibleMember(?User $user): bool
    {
        return $user instanceof User
            && $user->employee_profile_id !== null
            && $user->isAuthenticationAllowed();
    }

    /** The member who submitted it (by account, or by the linked employee profile). */
    public static function isRequester(?User $user, StationRequest $request): bool
    {
        if (! $user instanceof User) {
            return false;
        }
        if ($request->actor_user_id !== null && (int) $request->actor_user_id === (int) $user->getKey()) {
            return true;
        }

        return $request->requested_by_employee_id !== null
            && $user->employee_profile_id !== null
            && (int) $request->requested_by_employee_id === (int) $user->employee_profile_id;
    }

    /** Open means not completed, denied or cancelled, and not archived. */
    public static function isOpen(StationRequest $request): bool
    {
        return $request->is_open;
    }

    public static function mayResolve(?User $user, StationRequest $request): bool
    {
        return self::isEligibleMember($user) && self::isOpen($request);
    }

    /** Anyone but the requester must say what they did. */
    public static function noteRequired(?User $user, StationRequest $request): bool
    {
        return ! self::isRequester($user, $request);
    }

    /**
     * The payload shape the station request lists carry for the current member.
     *
     * @return array{eligible: bool, note_required: bool}
     */
    public static function resolutionFor(?User $user, StationRequest $request): array
    {
        $eligible = self::mayResolve($user, $request);

        return [
            'eligible' => $eligible,
            'note_required' => $eligible && self::noteRequired($user, $request),
        ];
    }
}
