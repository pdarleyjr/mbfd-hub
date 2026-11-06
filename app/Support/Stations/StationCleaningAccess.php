<?php

declare(strict_types=1);

namespace App\Support\Stations;

use App\Models\User;

/**
 * Who may change the station cleaning schedule: an active Super Administrator, or an active admin holding the
 * DIRECT stations.cleaning.manage permission (granted through Employees & Access). Every write checks here on the
 * server; hiding the button is never the control. Reading the schedule needs no extra permission.
 */
final class StationCleaningAccess
{
    public const PERMISSION = 'stations.cleaning.manage';

    public static function canManage(mixed $user): bool
    {
        if (! $user instanceof User || ! $user->isAuthenticationAllowed()) {
            return false;
        }

        return $user->hasRole('super_admin') || $user->hasDirectWebPermission(self::PERMISSION);
    }
}
