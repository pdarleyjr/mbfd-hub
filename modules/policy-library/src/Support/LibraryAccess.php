<?php

declare(strict_types=1);

namespace Mbfd\PolicyLibrary\Support;

use Illuminate\Contracts\Auth\Authenticatable;

final class LibraryAccess
{
    public static function canManage(?Authenticatable $user): bool
    {
        if ($user === null) {
            return false;
        }
        $isAdmin = method_exists($user, 'hasCurrentAdminPanelEntitlement')
            ? $user->hasCurrentAdminPanelEntitlement()
            : ((method_exists($user, 'hasRole') && $user->hasRole('super_admin')) || $user->can('admin.access'));

        return $isAdmin || $user->can('files.manage');
    }
}
