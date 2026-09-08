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
                $lockedUsers = User::query()->whereKey([$actor->getKey(), $target->getKey()])
                    ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                $lockedActor = $lockedUsers->get($actor->getKey());
                $lockedTarget = $lockedUsers->get($target->getKey());
                if (! $lockedActor instanceof User || ! $lockedTarget instanceof User || ! $lockedActor->isAuthenticationAllowed()) {
                    throw new AuthorizationException('An active, currently authorized actor is required for role assignment.');
                }
                $this->authorize($lockedActor, $lockedTarget, $proposedRoleNames);

                $wasSuperAdministrator = $lockedTarget->hasRole('super_admin');
                $lockedTarget->syncRoles($proposedRoleNames);
                $this->permissionRegistrar->forgetCachedPermissions();
                if ($wasSuperAdministrator && ! in_array('super_admin', $proposedRoleNames, true)) {
                    $lockedTarget->increment('media_control_security_version');
                    app(\App\Services\Oidc\OidcSessionRevoker::class)->revoke($lockedTarget);
                    app(\App\Services\Cloud\NextcloudAccountSynchronizer::class)->request($lockedTarget);
                }
                $this->auditRecorder->record($lockedActor, $lockedTarget, 'change_role', 'allowed', null, [
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
