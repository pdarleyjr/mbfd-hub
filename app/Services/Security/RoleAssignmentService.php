<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Models\User;
use App\Policies\RoleAssignmentPolicy;
use Illuminate\Auth\Access\AuthorizationException;
use Spatie\Permission\PermissionRegistrar;

final class RoleAssignmentService
{
    public function __construct(
        private readonly RoleAssignmentPolicy $policy,
        private readonly LastCriticalAdministratorGuard $lastCriticalAdministratorGuard,
        private readonly PermissionRegistrar $permissionRegistrar,
        private readonly SecurityAuditRecorder $auditRecorder,
    ) {}

    /**
     * @param  list<string>  $proposedRoleNames
     *
     * @throws AuthorizationException
     */
    public function sync(User $actor, User $target, array $proposedRoleNames): void
    {
        try {
            $this->authorize($actor, $target, $proposedRoleNames);
        } catch (AuthorizationException $exception) {
            $this->auditRecorder->record($actor, $target, 'change_role', 'denied');

            throw $exception;
        }

        $target->syncRoles($proposedRoleNames);
        $this->permissionRegistrar->forgetCachedPermissions();
        $this->auditRecorder->record($actor, $target, 'change_role', 'allowed', null, [
            'roles' => array_values($proposedRoleNames),
        ]);
    }

    /**
     * @param  list<string>  $proposedRoleNames
     *
     * @throws AuthorizationException
     */
    public function authorize(User $actor, User $target, array $proposedRoleNames): void
    {
        if (! $this->policy->allows($actor, $target, $proposedRoleNames)
            || ! $this->lastCriticalAdministratorGuard->allowsRoleSet($target, $proposedRoleNames)) {
            throw new AuthorizationException('The requested role assignment is not authorized.');
        }
    }
}
