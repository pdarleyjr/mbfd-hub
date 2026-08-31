<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Models\User;

final class LastCriticalAdministratorGuard
{
    /**
     * @param  list<string>  $proposedRoleNames
     */
    public function allowsRoleSet(User $target, array $proposedRoleNames): bool
    {
        $criticalRoles = array_values((array) config('security.critical_roles', []));

        if (! $target->hasAnyRole($criticalRoles)
            || array_intersect($criticalRoles, $proposedRoleNames) !== []) {
            return true;
        }

        return User::query()
            ->where('users.id', '!=', $target->getKey())
            ->whereHas('roles', fn ($query) => $query->whereIn('name', $criticalRoles))
            ->exists();
    }
}
