<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Enums\AccountStatus;
use App\Models\User;

final class LastCriticalAdministratorGuard
{
    public function lockActiveCriticalAdministrators(): void
    {
        User::query()
            ->where('account_status', AccountStatus::Active->value)
            ->whereHas('roles', fn ($query) => $query->whereIn(
                'name',
                array_values((array) config('security.critical_roles', [])),
            ))
            ->orderBy('users.id')
            ->lockForUpdate()
            ->get();
    }

    public function allowsDisable(User $target, bool $lockForUpdate = false): bool
    {
        $criticalRoles = array_values((array) config('security.critical_roles', []));
        if (! $target->hasAnyRole($criticalRoles)) {
            return true;
        }

        $query = User::query()
            ->where('account_status', AccountStatus::Active->value)
            ->whereHas('roles', fn ($query) => $query->whereIn('name', $criticalRoles))
            ->orderBy('users.id');

        if ($lockForUpdate) {
            return $query->lockForUpdate()->get()->contains(
                fn (User $user): bool => ! $user->is($target),
            );
        }

        return (clone $query)->where('users.id', '!=', $target->getKey())->exists();
    }

    /**
     * @param  list<string>  $proposedRoleNames
     */
    public function allowsRoleSet(User $target, array $proposedRoleNames, bool $lockForUpdate = false): bool
    {
        $criticalRoles = array_values((array) config('security.critical_roles', []));

        if (! $target->hasAnyRole($criticalRoles)
            || $target->getRawOriginal('account_status') !== AccountStatus::Active->value
            || array_intersect($criticalRoles, $proposedRoleNames) !== []) {
            return true;
        }

        $query = User::query()
            ->where('account_status', AccountStatus::Active->value)
            ->whereHas('roles', fn ($query) => $query->whereIn('name', $criticalRoles))
            ->orderBy('users.id');

        if ($lockForUpdate) {
            return $query->lockForUpdate()->get()->contains(
                fn (User $user): bool => ! $user->is($target),
            );
        }

        return (clone $query)->where('users.id', '!=', $target->getKey())->exists();
    }
}
