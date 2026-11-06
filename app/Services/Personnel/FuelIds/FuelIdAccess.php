<?php

declare(strict_types=1);

namespace App\Services\Personnel\FuelIds;

use App\Models\User;

/**
 * Who may see or change Fuel ID Numbers. Every page action, route and service call checks here on the server.
 *
 * Direct web permissions granted through Employees & Access, together with Hub administration access (admin.access);
 * super admins hold both. A general admin role alone does not open fuel PINs.
 */
final class FuelIdAccess
{
    public const VIEW = 'personnel.fuel_ids.view';

    public const MANAGE = 'personnel.fuel_ids.manage';

    public static function canView(?User $user): bool
    {
        return self::has($user, self::VIEW) || self::has($user, self::MANAGE);
    }

    public static function canManage(?User $user): bool
    {
        return self::has($user, self::MANAGE);
    }

    private static function has(?User $user, string $permission): bool
    {
        if (! $user instanceof User || ! $user->isAuthenticationAllowed()) {
            return false;
        }

        // Fuel PINs are an administrative record: the permission only counts alongside Hub administration access.
        return $user->hasRole('super_admin')
            || ($user->hasCurrentAdminPanelEntitlement() && $user->hasDirectWebPermission($permission));
    }
}
