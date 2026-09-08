<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Exceptions\CurrentPasswordMismatch;
use App\Models\User;
use App\Support\ApplicationAccessRegistry;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

final class ApplicationAccessService
{
    public function __construct(
        private readonly ApplicationAccessRegistry $registry,
        private readonly SecurityAuditRecorder $audit,
        private readonly LastCriticalAdministratorGuard $criticalAdministrators,
        private readonly PermissionRegistrar $permissions,
    ) {}

    /** @param array<array-key, mixed> $applications Untrusted submitted application keys. */
    public function syncApplications(User $actor, User $target, array $applications, string $currentPassword, string $reason): void
    {
        $scope = [];
        foreach ($this->registry->applications() as $key => $application) {
            if ($application['permission'] !== null) {
                $scope[$key] = $application['permission'];
            }
        }
        $this->sync($actor, $target, $applications, $scope, $currentPassword, $reason, 'change_application_access');
    }

    /** @param array<array-key, mixed> $capabilities Untrusted submitted capability keys. */
    public function syncAdministrationCapabilities(User $actor, User $target, array $capabilities, string $currentPassword, string $reason): void
    {
        $keys = array_keys($this->registry->capabilityOptions());
        $this->sync($actor, $target, $capabilities, array_combine($keys, $keys), $currentPassword, $reason, 'change_admin_capabilities');
    }

    /** @param array<array-key, mixed> $applications */
    public function syncApplicationAdministrations(User $actor, User $target, array $applications, string $currentPassword, string $reason): void
    {
        $scope = [];
        foreach (array_keys($this->registry->applicationAdministrationOptions()) as $key) {
            $scope[$key] = 'app.'.$key.'.admin';
        }
        $this->sync($actor, $target, $applications, $scope, $currentPassword, $reason, 'change_application_administration');
    }

    /**
     * @param  array<array-key, mixed>  $selected
     * @param  array<string, string>  $scope
     */
    private function sync(User $actor, User $target, array $selected, array $scope, string $currentPassword, string $reason, string $action): void
    {
        try {
            DB::transaction(function () use ($actor, $target, $selected, $scope, $currentPassword, $reason, $action): void {
                $this->criticalAdministrators->lockActiveCriticalAdministrators();
                $locked = User::query()->whereKey([$actor->getKey(), $target->getKey()])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                $currentActor = $locked->get($actor->getKey());
                $currentTarget = $locked->get($target->getKey());
                if (! $currentActor instanceof User || ! $currentTarget instanceof User
                    || ! $currentActor->isAuthenticationAllowed() || ! $currentActor->hasRole('super_admin')
                    || $currentActor->is($currentTarget) || $currentTarget->hasRole('super_admin')
                    || trim($reason) === '' || mb_strlen($reason) > 500) {
                    throw new AuthorizationException('Application access administration requires an active Super Administrator, another non-super-admin member, a current password, and a reason.');
                }
                foreach ($selected as $key) {
                    if (! is_string($key) || ! array_key_exists($key, $scope)) {
                        throw new AuthorizationException('The requested access is not supported by this control.');
                    }
                }
                $proposed = array_values(array_unique(array_map(fn (string $key): string => $scope[$key], $selected)));
                $known = Permission::query()->where('guard_name', 'web')->whereIn('name', array_values($scope))->pluck('id', 'name');
                if (array_diff($proposed, $known->keys()->all()) !== []) {
                    throw new AuthorizationException('The requested permission has not been provisioned.');
                }
                if (! Hash::check($currentPassword, $currentActor->getAuthPassword())) {
                    throw new CurrentPasswordMismatch('The current password is incorrect.');
                }
                foreach ($selected as $key) {
                    if ($action === 'change_application_administration' && ! $currentTarget->hasDirectWebPermission('app.'.$key.'.access')) {
                        throw \Illuminate\Validation\ValidationException::withMessages(['administrations' => 'Grant application access separately before granting its administrator role.']);
                    }
                }
                $before = $currentTarget->permissions()->where('guard_name', 'web')->whereIn('name', array_values($scope))->orderBy('name')->pluck('name')->all();
                sort($proposed);
                $remove = array_values(array_diff($before, $proposed));
                $add = array_values(array_diff($proposed, $before));
                $dependentRoles = [];
                if ($action === 'change_application_access') {
                    foreach (array_keys($this->registry->applicationAdministrationOptions()) as $application) {
                        $permission = 'app.'.$application.'.admin';
                        if (in_array('app.'.$application.'.access', $remove, true) && $currentTarget->hasDirectWebPermission($permission)) {
                            $dependentRoles[] = $permission;
                        }
                    }
                    $currentTarget->permissions()->detach(Permission::query()->where('guard_name', 'web')->whereIn('name', $dependentRoles)->pluck('id')->all());
                }
                $currentTarget->permissions()->detach($known->only($remove)->values()->all());
                $currentTarget->permissions()->syncWithoutDetaching($known->only($add)->values()->all());
                $this->permissions->forgetCachedPermissions();
                if (array_intersect(['app.media_control.access', 'app.media_control.admin'], [...$remove, ...$dependentRoles]) !== []) {
                    $currentTarget->increment('media_control_security_version');
                }
                if (in_array('app.bid.admin', [...$remove, ...$dependentRoles], true)) {
                    // Bid binds the canonical security version, not a separate
                    // app epoch. Revoke only this member's sessions permanently.
                    $currentTarget = app(\App\Services\Identity\AccountSecurityService::class)
                        ->revokeAll($currentTarget, 'Bid administrator role removed', now());
                }
                foreach (['cmd', 'cloud'] as $application) {
                    if (in_array('app.'.$application.'.access', $remove, true)) {
                        app(\App\Services\Oidc\OidcSessionRevoker::class)->revoke($currentTarget, $application);
                    }
                }
                if (in_array('app.cloud.access', [...$add, ...$remove], true)) {
                    app(\App\Services\Cloud\NextcloudAccountSynchronizer::class)->request($currentTarget);
                }
                $this->audit->record($currentActor, $currentTarget, $action, 'allowed', trim($reason), [
                    'before' => $before, 'after' => $proposed, 'granted' => $add, 'revoked' => [...$remove, ...$dependentRoles],
                ]);
            });
        } catch (AuthorizationException $exception) {
            $this->audit->record($actor, $target, $action, 'denied');
            throw $exception;
        }
    }
}
