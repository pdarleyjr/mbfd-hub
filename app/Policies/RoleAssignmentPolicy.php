<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

final class RoleAssignmentPolicy
{
    /**
     * @param  list<string>  $proposedRoleNames
     */
    public function allows(User $actor, User $target, array $proposedRoleNames): bool
    {
        $proposedRoleNames = array_values(array_unique($proposedRoleNames));
        $criticalRoles = $this->criticalRoles();

        if ($actor->is($target)) {
            return false;
        }

        if (($target->hasAnyRole($criticalRoles) || array_intersect($criticalRoles, $proposedRoleNames) !== [])
            && ! (bool) config('security.role_assignment.allow_critical_role_changes', false)) {
            return false;
        }

        return $this->canDelegateAny($actor);
    }

    public function canDelegateAny(User $actor): bool
    {
        return $actor->hasAnyRole((array) config('security.role_assignment.delegator_roles', []));
    }

    /** @return list<string> */
    private function criticalRoles(): array
    {
        return array_values((array) config('security.critical_roles', []));
    }
}
