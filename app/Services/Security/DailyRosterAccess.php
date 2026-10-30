<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Models\User;

/**
 * Who may read the Daily Roster: a current member with a linked personnel profile (the same
 * entitlement as the Employee Portal), or an administrator. The roster holds employee IDs and
 * leave codes, so a bare login is not enough. Deliberately not a Gate ability: Gate::before would
 * skip the account checks here. Editing the roster does not exist; it comes from TeleStaff.
 */
final class DailyRosterAccess
{
    public function __construct(private readonly EmployeeLogisticsMessagingAccess $members) {}

    public function canView(?User $user): bool
    {
        if (! $user instanceof User) {
            return false;
        }
        if ($this->members->canUseMemberInbox($user)) {
            return true;
        }

        return $user->isAuthenticationAllowed()
            && ! $user->must_change_password
            && $user->hasCurrentAdminPanelEntitlement();
    }
}
