<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Exceptions\CurrentPasswordMismatch;
use App\Models\User;
use App\Policies\RoleAssignmentPolicy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
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
        $this->syncRoles($actor, $target, $proposedRoleNames);
    }

    /** @param list<string> $proposedRoleNames */
    public function syncWithAuthorization(User $actor, User $target, array $proposedRoleNames, string $currentPassword, string $reason): void
    {
        $this->syncRoles($actor, $target, $proposedRoleNames, $currentPassword, $reason);
    }

    /** @param list<string> $proposedRoleNames */
    private function syncRoles(User $actor, User $target, array $proposedRoleNames, ?string $currentPassword = null, ?string $reason = null): void
    {
        try {
            DB::transaction(function () use ($actor, $target, $proposedRoleNames, $currentPassword, $reason): void {
                $this->lastCriticalAdministratorGuard->lockActiveCriticalAdministrators();
                $lockedUsers = User::query()->whereKey([$actor->getKey(), $target->getKey()])
                    ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                $lockedActor = $lockedUsers->get($actor->getKey());
                $lockedTarget = $lockedUsers->get($target->getKey());
                if (! $lockedActor instanceof User || ! $lockedTarget instanceof User || ! $lockedActor->isAuthenticationAllowed()) {
                    throw new AuthorizationException('An active, currently authorized actor is required for role assignment.');
                }
                $this->authorize($lockedActor, $lockedTarget, $proposedRoleNames);
                if ($currentPassword !== null) {
                    if ($reason === null || trim($reason) === '' || mb_strlen($reason) > 500) {
                        throw new AuthorizationException('A role-change audit reason is required.');
                    }
                    if (! Hash::check($currentPassword, $lockedActor->getAuthPassword())) {
                        throw new CurrentPasswordMismatch('The current password is incorrect.');
                    }
                }

                $wasSuperAdministrator = $lockedTarget->hasRole('super_admin');
                $lockedTarget->syncRoles($proposedRoleNames);
                $this->permissionRegistrar->forgetCachedPermissions();
                if ($wasSuperAdministrator && ! in_array('super_admin', $proposedRoleNames, true)) {
                    $lockedTarget->increment('media_control_security_version');
                    // Super Administrator also grants Bid administration. Its
                    // loss invalidates the target's canonical session version,
                    // including OIDC/Cloud hooks, so regrant cannot revive old JWTs.
                    app(\App\Services\Identity\AccountSecurityService::class)->revokeAll($lockedTarget, 'Super Administrator role removed', now());
                }
                $this->auditRecorder->record($lockedActor, $lockedTarget, 'change_role', 'allowed', $reason === null ? null : trim($reason), [
                    'roles' => $proposedRoleNames,
                ]);
            });
        } catch (\Throwable $exception) {
            $this->auditRecorder->record($actor, $target, 'change_role', $exception instanceof AuthorizationException ? 'denied' : 'failed', $reason === null ? null : mb_substr(trim($reason), 0, 500));

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
