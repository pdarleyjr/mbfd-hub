<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Enums\Security\AccountSecurityAction;
use App\Enums\Security\RecentAuthenticationAction;
use App\Models\User;
use App\Policies\AccountSecurityPolicy;
use App\Policies\RoleAssignmentPolicy;
use App\Services\Security\AccountSecurityService;
use App\Services\Security\LastCriticalAdministratorGuard;
use App\Services\Security\RecentAuthentication;
use App\Services\Security\RoleAssignmentService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class AuthorizationPolicyFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_delegated_actors_cannot_assign_global_roles_through_the_service(): void
    {
        $actor = $this->userWithRole('admin');
        $target = User::factory()->create();

        $this->expectException(AuthorizationException::class);

        app(RoleAssignmentService::class)->sync($actor, $target, ['training_viewer']);
    }

    public function test_workgroup_manager_cannot_assign_global_or_critical_roles(): void
    {
        $actor = $this->userWithRole('workgroup_member');
        $target = User::factory()->create();
        $policy = app(RoleAssignmentPolicy::class);

        self::assertFalse($policy->allows($actor, $target, ['training_viewer']));
        self::assertFalse($policy->allows($actor, $target, ['super_admin']));
    }

    public function test_super_admin_can_delegate_an_existing_lower_role_to_an_ordinary_user(): void
    {
        $actor = $this->userWithRole('super_admin');
        $target = User::factory()->create();
        Role::findOrCreate('training_viewer', 'web');

        app(RoleAssignmentService::class)->sync($actor, $target, ['training_viewer']);

        self::assertSame(['training_viewer'], $target->fresh()->getRoleNames()->all());
        $this->assertDatabaseHas('security_action_events', [
            'actor_user_id' => $actor->id,
            'target_user_id' => $target->id,
            'action' => 'change_role',
            'result' => 'allowed',
        ]);
    }

    public function test_role_sync_rejects_an_actor_disabled_after_their_model_was_loaded(): void
    {
        $actor = $this->userWithRole('super_admin')->load('roles');
        $target = $this->userWithRole('workgroup_member');
        Role::findOrCreate('training_viewer', 'web');
        User::query()->whereKey($actor->id)->update(['account_status' => 'disabled']);

        try {
            app(RoleAssignmentService::class)->sync($actor, $target, ['training_viewer']);
            self::fail('A disabled actor must not delegate through a stale model.');
        } catch (AuthorizationException) {
            self::assertSame(['workgroup_member'], $target->fresh()->getRoleNames()->all());
            $this->assertDatabaseHas('security_action_events', ['actor_user_id' => $actor->id,
                'target_user_id' => $target->id, 'action' => 'change_role', 'result' => 'denied']);
        }
    }

    public function test_role_sync_rejects_an_actor_whose_cached_delegator_role_was_revoked(): void
    {
        $actor = $this->userWithRole('super_admin')->load('roles');
        $target = $this->userWithRole('workgroup_member');
        Role::findOrCreate('training_viewer', 'web');
        $actor->fresh()->syncRoles([]);
        self::assertTrue($actor->hasRole('super_admin'));

        try {
            app(RoleAssignmentService::class)->sync($actor, $target, ['training_viewer']);
            self::fail('A revoked delegator role must not authorize through a cached relation.');
        } catch (AuthorizationException) {
            self::assertSame(['workgroup_member'], $target->fresh()->getRoleNames()->all());
            $this->assertDatabaseHas('security_action_events', ['actor_user_id' => $actor->id,
                'target_user_id' => $target->id, 'action' => 'change_role', 'result' => 'denied']);
        }
    }

    public function test_unknown_role_assignment_fails_closed_without_creating_or_changing_roles(): void
    {
        $actor = $this->userWithRole('super_admin');
        $target = $this->userWithRole('workgroup_member');
        $target->givePermissionTo(Permission::findOrCreate('existing_permission', 'web'));

        try {
            app(RoleAssignmentService::class)->sync($actor, $target, ['unknown_role']);
            self::fail('An unknown role assignment should fail closed.');
        } catch (AuthorizationException) {
            self::assertFalse(Role::query()->where('name', 'unknown_role')->exists());
            self::assertSame(['workgroup_member'], $target->fresh()->getRoleNames()->all());
            self::assertSame(['existing_permission'], $target->fresh()->getDirectPermissions()->pluck('name')->all());
            $this->assertDatabaseHas('security_action_events', [
                'actor_user_id' => $actor->id,
                'target_user_id' => $target->id,
                'action' => 'change_role',
                'result' => 'denied',
            ]);
        }
    }

    public function test_owner_authorized_super_admin_can_manage_critical_roles_but_not_self(): void
    {
        $actor = $this->userWithRole('super_admin');
        $ordinaryTarget = User::factory()->create();
        $criticalTarget = $this->userWithRole('super_admin');
        $policy = app(RoleAssignmentPolicy::class);

        self::assertTrue($policy->allows($actor, $ordinaryTarget, ['super_admin']));
        self::assertTrue($policy->allows($actor, $criticalTarget, ['training_viewer']));
        self::assertFalse($policy->allows($actor, $actor, ['training_viewer']));
    }

    public function test_last_critical_administrator_guard_blocks_removal_but_allows_a_safe_future_path_with_another_critical_admin(): void
    {
        $onlyCriticalAdmin = $this->userWithRole('super_admin');
        $guard = app(LastCriticalAdministratorGuard::class);

        self::assertFalse($guard->allowsRoleSet($onlyCriticalAdmin, []));

        $this->userWithRole('super_admin');

        self::assertTrue($guard->allowsRoleSet($onlyCriticalAdmin, []));
    }

    public function test_administrative_recovery_is_limited_to_owner_authorized_super_admin(): void
    {
        $target = User::factory()->create();
        $policy = app(AccountSecurityPolicy::class);

        self::assertFalse($policy->allows($this->userWithRole('workgroup_member'), $target, AccountSecurityAction::AdministrativeRecovery));
        self::assertFalse($policy->allows($this->userWithRole('admin'), $target, AccountSecurityAction::AdministrativeRecovery));
        self::assertTrue($policy->allows($this->userWithRole('super_admin'), $target, AccountSecurityAction::AdministrativeRecovery));
    }

    public function test_denied_administrative_recovery_is_recorded_without_a_password_value(): void
    {
        $actor = $this->userWithRole('admin');
        $target = User::factory()->create();

        try {
            app(AccountSecurityService::class)->authorize(
                $actor,
                $target,
                AccountSecurityAction::AdministrativeRecovery,
                'case-123',
            );
            self::fail('Administrative recovery should fail closed.');
        } catch (AuthorizationException) {
            $this->assertDatabaseHas('security_action_events', [
                'actor_user_id' => $actor->id,
                'target_user_id' => $target->id,
                'action' => 'administrative_recovery',
                'result' => 'denied',
                'reason' => 'case-123',
            ]);
        }
    }

    public function test_recent_authentication_uses_central_action_thresholds(): void
    {
        $recentAuthentication = app(RecentAuthentication::class);
        $now = CarbonImmutable::parse('2026-08-31T12:00:00Z');

        self::assertTrue($recentAuthentication->isSatisfied(
            $now->subMinutes(4),
            RecentAuthenticationAction::SecurityAdministration,
            $now,
        ));
        self::assertFalse($recentAuthentication->isSatisfied(
            $now->subMinutes(6),
            RecentAuthenticationAction::SecurityAdministration,
            $now,
        ));
        self::assertTrue($recentAuthentication->isSatisfied(
            null,
            RecentAuthenticationAction::OrdinaryNavigation,
            $now,
        ));
    }

    public function test_account_security_service_rejects_an_authorized_actor_without_recent_authentication(): void
    {
        $actor = $this->userWithRole('super_admin');
        $target = User::factory()->create();

        $this->expectException(AuthorizationException::class);

        app(AccountSecurityService::class)->authorize(
            $actor,
            $target,
            AccountSecurityAction::AdministrativeRecovery,
            'stale authentication test',
        );
    }

    public function test_interactive_role_assignment_requires_fresh_password_and_a_reason(): void
    {
        $actor = $this->userWithRole('super_admin');
        $actor->forceFill(['password' => 'Role-change-password!'])->save();
        $target = $this->userWithRole('training_viewer');
        $service = app(\App\Services\Security\RoleAssignmentService::class);
        foreach ([['wrong', 'Approved role change'], ['Role-change-password!', ' ']] as [$password, $reason]) {
            try {
                $service->syncWithAuthorization($actor, $target, ['super_admin'], $password, $reason);
                self::fail('Role changes require a current password and reason.');
            } catch (AuthorizationException) {
                self::assertSame(['training_viewer'], $target->fresh()->getRoleNames()->all());
            }
        }
        $actor->fresh()->forceFill(['password' => 'Replaced-current-password!'])->save();
        try {
            $service->syncWithAuthorization($actor, $target, ['super_admin'], 'Role-change-password!', 'Approved role change');
            self::fail('A stale password cannot authorize roles.');
        } catch (AuthorizationException) {
            self::assertSame(['training_viewer'], $target->fresh()->getRoleNames()->all());
        }
        $service->syncWithAuthorization($actor, $target, ['super_admin'], 'Replaced-current-password!', 'Approved role change');
        self::assertTrue($target->fresh()->hasRole('super_admin'));
        $this->assertDatabaseHas('security_action_events', ['target_user_id' => $target->id, 'action' => 'change_role', 'result' => 'allowed', 'reason' => 'Approved role change']);
    }

    public function test_super_admin_demotion_revokes_canonical_sessions_permanently_across_regrant(): void
    {
        $actor = $this->userWithRole('super_admin');
        $target = $this->userWithRole('super_admin');
        $password = $target->password;
        $at = CarbonImmutable::now();
        $session = app(\App\Services\Identity\SessionRegistry::class)->register($target, 'super-admin-demotion-test', \App\Enums\SessionContextClass::UnmanagedBrowser, $at, $at->addHour(), $at->addDay());
        $service = app(\App\Services\Security\RoleAssignmentService::class);
        $service->sync($actor, $target, []);
        self::assertSame(2, $target->fresh()->security_version);
        self::assertSame(1, $target->fresh()->media_control_security_version);
        $service->sync($actor, $target, ['super_admin']);
        self::assertSame(2, $target->fresh()->security_version);
        self::assertSame($password, $target->fresh()->password);
        self::assertNotNull($session->fresh()->revoked_at);
        self::assertFalse(app(\App\Services\Identity\SessionRegistry::class)->isCurrent($target->fresh(), $session->fresh(), $at->addMinute()));
    }

    private function userWithRole(string $role): User
    {
        Role::findOrCreate($role, 'web');
        $user = User::factory()->create(['account_status' => \App\Enums\AccountStatus::Active]);
        $user->assignRole($role);

        return $user;
    }
}
