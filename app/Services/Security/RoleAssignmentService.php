<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Models\User;
use App\Policies\RoleAssignmentPolicy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
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
            DB::transaction(function () use ($actor, $target, $proposedRoleNames): void {
                $this->lastCriticalAdministratorGuard->lockActiveCriticalAdministrators();
                $lockedTarget = User::query()->lockForUpdate()->findOrFail($target->getKey());
                $this->authorize($actor, $lockedTarget, $proposedRoleNames);

                $lockedTarget->syncRoles($proposedRoleNames);
                $this->permissionRegistrar->forgetCachedPermissions();
                $this->auditRecorder->record($actor, $lockedTarget, 'change_role', 'allowed', null, [
                    'roles' => $proposedRoleNames,
                ]);
            });
        } catch (AuthorizationException $exception) {
            $this->auditRecorder->record($actor, $target, 'change_role', 'denied');

            throw $exception;
        }
    }

    /**
     * @param  list<string>  $proposedRoleNames
     *
     * @throws AuthorizationException
     */
    public function authorize(
        User $actor,
        User $target,
        array $proposedRoleNames,
        bool $lockForUpdate = false,
    ): void {
        $uniqueRoleNames = array_values(array_unique($proposedRoleNames));
        $existingRoleCount = Role::query()
            ->where('guard_name', 'web')
            ->whereIn('name', $uniqueRoleNames)
            ->count();

        if ($existingRoleCount !== count($uniqueRoleNames)
            || ! $this->policy->allows($actor, $target, $proposedRoleNames)
            || ! $this->lastCriticalAdministratorGuard->allowsRoleSet(
                $target,
                $proposedRoleNames,
                $lockForUpdate,
            )) {
            throw new AuthorizationException('The requested role assignment is not authorized.');
        }
    }
}
