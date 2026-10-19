<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Exceptions\CurrentPasswordMismatch;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * "Fleet Operations Manager" as one assignment.
 *
 * The role carries the whole set, including preventative-maintenance settings.
 * Admin-panel capability checks only honour direct grants, so assigning the
 * role also grants its admin.* permissions directly; removing it takes back
 * the fleet permissions and keeps panel entry only while another admin
 * capability still needs it.
 */
final class FleetOperationsManagerAssignment
{
    public const ROLE = 'fleet_operations_manager';

    /** Granted directly because the admin capability gate ignores role inheritance. */
    public const DIRECT_PERMISSIONS = ['admin.access', 'admin.fleet.view', 'admin.fleet.manage', 'admin.stations.view'];

    /** Apparatus meter readings and preventative-maintenance tracking. */
    public const MAINTENANCE_PERMISSION = 'manage_pm_settings';

    public function __construct(
        private readonly SecurityAuditRecorder $audit,
        private readonly PermissionRegistrar $permissions,
    ) {}

    /** @return list<string> */
    public static function rolePermissions(): array
    {
        return [...self::DIRECT_PERMISSIONS, self::MAINTENANCE_PERMISSION];
    }

    public static function isAssigned(User $user): bool
    {
        if (! $user->hasRole(self::ROLE)) {
            return false;
        }
        foreach (self::DIRECT_PERMISSIONS as $permission) {
            if (! $user->hasDirectWebPermission($permission)) {
                return false;
            }
        }

        return true;
    }

    /** The administrator path: an active Super Administrator, their current password, and a reason. */
    public function set(User $actor, User $target, bool $enabled, string $currentPassword, string $reason): void
    {
        try {
            DB::transaction(function () use ($actor, $target, $enabled, $currentPassword, $reason): void {
                $locked = User::query()->whereKey([$actor->getKey(), $target->getKey()])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                $currentActor = $locked->get($actor->getKey());
                $currentTarget = $locked->get($target->getKey());
                if (! $currentActor instanceof User || ! $currentTarget instanceof User
                    || ! $currentActor->isAuthenticationAllowed() || ! $currentActor->hasRole('super_admin')
                    || $currentActor->is($currentTarget) || $currentTarget->hasRole('super_admin')
                    || trim($reason) === '' || mb_strlen($reason) > 500) {
                    throw new AuthorizationException('Fleet Operations Manager assignment requires an active Super Administrator, another non-super-admin member, a current password, and a reason.');
                }
                if (! Hash::check($currentPassword, $currentActor->getAuthPassword())) {
                    throw new CurrentPasswordMismatch('The current password is incorrect.');
                }
                $this->apply($currentActor, $currentTarget, $enabled, trim($reason));
            });
        } catch (AuthorizationException $exception) {
            $this->audit->record($actor, $target, 'change_fleet_operations_manager', 'denied');
            throw $exception;
        }
    }

    /**
     * Idempotent and additive when enabling: existing roles and permissions are
     * never removed. Callers are responsible for authorizing the actor.
     *
     * @return array{granted:list<string>,revoked:list<string>}
     */
    public function apply(User $actor, User $target, bool $enabled, string $reason): array
    {
        $known = Permission::query()->where('guard_name', 'web')->whereIn('name', self::rolePermissions())->pluck('id', 'name');
        if ($known->count() !== count(self::rolePermissions())) {
            throw new AuthorizationException('The fleet permissions have not been provisioned.');
        }
        // Provisioned here rather than in a migration: migrations create no roles.
        $role = Role::findOrCreate(self::ROLE, 'web');
        $role->givePermissionTo($known->keys()->all());
        $known = $known->only(self::DIRECT_PERMISSIONS);
        $before = $target->permissions()->where('guard_name', 'web')->whereIn('name', self::DIRECT_PERMISSIONS)->pluck('name')->all();
        $hadRole = $target->hasRole(self::ROLE);

        if ($enabled) {
            $grant = array_values(array_diff(self::DIRECT_PERMISSIONS, $before));
            $target->permissions()->syncWithoutDetaching($known->only($grant)->values()->all());
            if (! $hadRole) {
                $target->assignRole($role);
            }
            $revoke = [];
        } else {
            $revoke = array_values(array_intersect($before, ['admin.fleet.view', 'admin.fleet.manage']));
            if (! $target->hasDirectWebPermission('admin.stations.manage')) {
                $revoke = [...$revoke, ...array_intersect($before, ['admin.stations.view'])];
            }
            $otherCapabilities = $target->permissions()->where('guard_name', 'web')->where('name', 'like', 'admin.%')
                ->whereNotIn('name', [...$revoke, 'admin.access'])->exists();
            if (! $otherCapabilities && in_array('admin.access', $before, true) && ! $target->hasAnyRole(['admin', 'logistics_admin'])) {
                $revoke[] = 'admin.access';
            }
            $target->permissions()->detach($known->only($revoke)->values()->all());
            if ($hadRole) {
                $target->removeRole($role);
            }
            $grant = [];
        }
        $this->permissions->forgetCachedPermissions();
        $this->audit->record($actor, $target, 'change_fleet_operations_manager', 'allowed', $reason, [
            'enabled' => $enabled,
            'role_before' => $hadRole,
            'granted' => $grant,
            'revoked' => array_values($revoke),
        ]);

        return ['granted' => $grant, 'revoked' => array_values($revoke)];
    }
}
