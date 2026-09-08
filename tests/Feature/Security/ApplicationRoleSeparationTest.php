<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\User;
use App\Services\Security\ApplicationAccessService;
use App\Services\Security\ApplicationRoleResolver;
use App\Support\ApplicationAccessRegistry;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class ApplicationRoleSeparationTest extends TestCase
{
    use RefreshDatabase;

    public function test_access_and_hub_admin_panel_never_implicitly_grant_application_administration(): void
    {
        [$actor, $target] = $this->members();
        app(ApplicationAccessService::class)->syncApplications($actor, $target, ['admin', 'bid', 'media_control'], 'Role-test-password!', 'Ordinary app access');
        $resolver = app(ApplicationRoleResolver::class);
        self::assertSame('member', $resolver->forUser($target->fresh(), 'bid'));
        self::assertSame('user', $resolver->forUser($target->fresh(), 'media_control'));
        self::assertSame([], app(ApplicationAccessRegistry::class)->selectedApplicationAdministrations($target));
    }

    public function test_saved_grant_remains_visible_when_client_is_missing_or_account_is_disabled(): void
    {
        [$actor, $target] = $this->members();
        config(['services.bid.federation_token' => null]);
        app(ApplicationAccessService::class)->syncApplications($actor, $target, ['bid'], 'Role-test-password!', 'Approved pending access');
        $registry = app(ApplicationAccessRegistry::class);
        self::assertSame('Access granted', $registry->states($target)['bid']['grant_status']);
        self::assertFalse($registry->states($target)['bid']['allowed']);
        $target->forceFill(['account_status' => 'disabled'])->save();
        self::assertSame('Access granted', $registry->states($target)['bid']['grant_status']);
        self::assertFalse($registry->states($target)['bid']['allowed']);
        self::assertSame('Not granted', $registry->states($target)['media_control']['grant_status']);
        self::assertSame('Inherited from Super Administrator', $registry->states($actor)['bid']['grant_status']);
    }

    public function test_explicit_administration_is_independent_audited_and_media_downgrade_revokes_the_old_epoch(): void
    {
        [$actor, $target] = $this->members();
        $service = app(ApplicationAccessService::class);
        $service->syncApplications($actor, $target, ['bid', 'media_control'], 'Role-test-password!', 'App access');
        $service->syncApplicationAdministrations($actor, $target, ['bid', 'media_control'], 'Role-test-password!', 'Approved application administration');
        self::assertSame('admin', app(ApplicationRoleResolver::class)->forUser($target->fresh(), 'bid'));
        self::assertSame('platform_admin', app(ApplicationRoleResolver::class)->forUser($target->fresh(), 'media_control'));
        self::assertFalse($target->fresh()->hasCurrentAdminPanelEntitlement());
        $service->syncApplicationAdministrations($actor, $target, ['bid'], 'Role-test-password!', 'Remove Media administration');
        self::assertSame(1, (int) $target->fresh()->media_control_security_version);
        self::assertTrue($target->fresh()->hasCurrentMediaControlEntitlement());
        self::assertSame('user', app(ApplicationRoleResolver::class)->forUser($target->fresh(), 'media_control'));
        $this->assertDatabaseHas('security_action_events', ['target_user_id' => $target->id, 'action' => 'change_application_administration', 'result' => 'allowed']);
    }

    public function test_application_role_requires_access_and_cannot_forge_unsupported_or_cross_scope_roles(): void
    {
        [$actor, $target] = $this->members();
        try {
            app(ApplicationAccessService::class)->syncApplicationAdministrations($actor, $target, ['bid'], 'Role-test-password!', 'Missing prerequisite');
            self::fail('Application access is required first.');
        } catch (\Illuminate\Validation\ValidationException $exception) {
            self::assertArrayHasKey('administrations', $exception->errors());
            self::assertSame([], $target->fresh()->permissions()->pluck('name')->all());
        }
        foreach ([['cmd'], ['cloud'], ['admin.members.security']] as $selection) {
            try {
                app(ApplicationAccessService::class)->syncApplicationAdministrations($actor, $target, $selection, 'Role-test-password!', 'Invalid grant');
                self::fail('Unsupported or access-less administration must fail atomically.');
            } catch (AuthorizationException) {
                self::assertSame([], $target->fresh()->permissions()->pluck('name')->all());
            }
        }
    }

    public function test_access_revocation_removes_dormant_admin_and_regrant_remains_ordinary_access(): void
    {
        [$actor, $target] = $this->members();
        $service = app(ApplicationAccessService::class);
        $service->syncApplications($actor, $target, ['media_control'], 'Role-test-password!', 'Access');
        $service->syncApplicationAdministrations($actor, $target, ['media_control'], 'Role-test-password!', 'Admin');
        $service->syncApplications($actor, $target, [], 'Role-test-password!', 'Revoke access');
        self::assertFalse($target->fresh()->hasDirectWebPermission('app.media_control.admin'));
        self::assertSame(1, (int) $target->fresh()->media_control_security_version);
        $service->syncApplications($actor, $target, ['media_control'], 'Role-test-password!', 'Access only');
        self::assertSame('user', app(ApplicationRoleResolver::class)->forUser($target->fresh(), 'media_control'));
    }

    public function test_super_admin_inherits_roles_but_arbitrary_role_permissions_do_not(): void
    {
        [$actor, $target] = $this->members();
        self::assertSame('admin', app(ApplicationRoleResolver::class)->forUser($actor, 'bid'));
        self::assertSame('platform_admin', app(ApplicationRoleResolver::class)->forUser($actor, 'media_control'));
        $target->givePermissionTo(Permission::findOrCreate('app.bid.access', 'web'));
        $role = Role::findOrCreate('not-an-application-admin', 'web');
        $role->givePermissionTo(Permission::findOrCreate('app.bid.admin', 'web'));
        $target->assignRole($role);
        self::assertSame('member', app(ApplicationRoleResolver::class)->forUser($target->fresh(), 'bid'));
    }

    public function test_bid_admin_downgrade_revokes_only_the_target_canonical_sessions_and_cannot_be_undone_by_regrant(): void
    {
        [$actor, $target] = $this->members();
        $service = app(ApplicationAccessService::class);
        $service->syncApplications($actor, $target, ['bid'], 'Role-test-password!', 'Access');
        $service->syncApplicationAdministrations($actor, $target, ['bid'], 'Role-test-password!', 'Admin');
        $targetSession = \App\Models\AuthenticationSession::factory()->create(['user_id' => $target->id]);
        $actorSession = \App\Models\AuthenticationSession::factory()->create(['user_id' => $actor->id]);
        $version = (int) $target->security_version;
        $service->syncApplicationAdministrations($actor, $target, [], 'Role-test-password!', 'Remove administrator');
        self::assertSame($version + 1, (int) $target->fresh()->security_version);
        self::assertNotNull($targetSession->fresh()->revoked_at);
        self::assertNull($actorSession->fresh()->revoked_at);
        $service->syncApplicationAdministrations($actor, $target, ['bid'], 'Role-test-password!', 'New grant');
        self::assertSame($version + 1, (int) $target->fresh()->security_version);
        self::assertNotNull($targetSession->fresh()->revoked_at);
    }

    public function test_additive_migration_preserves_only_existing_bid_admin_intersection_and_never_media_admin(): void
    {
        [, $legacy] = $this->members();
        [, $ordinary] = $this->members();
        foreach (['admin.access', 'app.bid.access', 'app.media_control.access'] as $permission) {
            $legacy->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        $ordinary->givePermissionTo(Permission::findOrCreate('app.bid.access', 'web'));
        $migration = require database_path('migrations/2026_09_08_190200_separate_application_administration_permissions.php');
        $migration->up();
        $migration->up();
        self::assertTrue($legacy->fresh()->hasDirectWebPermission('app.bid.admin'));
        self::assertFalse($legacy->fresh()->hasDirectWebPermission('app.media_control.admin'));
        self::assertFalse($ordinary->fresh()->hasDirectWebPermission('app.bid.admin'));
        self::assertFalse($ordinary->fresh()->hasCurrentAdminPanelEntitlement());
        $ordinary->givePermissionTo(Permission::findOrCreate('admin.access', 'web'));
        self::assertSame('member', app(ApplicationRoleResolver::class)->forUser($ordinary->fresh(), 'bid'));
    }

    public function test_registry_separates_client_configuration_runtime_verification_and_supported_roles(): void
    {
        [, $target] = $this->members();
        config(['oidc.clients.cmd' => 'nonexistent-client', 'application_access.runtime_verified.cmd' => false]);
        $registry = app(ApplicationAccessRegistry::class);
        self::assertFalse($registry->states($target)['cmd']['operational']);
        $client = \Laravel\Passport\Client::query()->create(['name' => 'Role registry client', 'secret' => 'registry-client-test-only', 'provider' => 'users',
            'redirect_uris' => ['https://cmd.mbfdhub.com/auth/callback'], 'grant_types' => ['authorization_code'], 'revoked' => false]);
        config(['oidc.clients.cmd' => $client->id]);
        $states = $registry->states($target);
        self::assertTrue($states['cmd']['operational']);
        self::assertStringContainsString('not verified', $states['cmd']['runtime_status']);
        self::assertNull($states['cmd']['role']);
        self::assertStringContainsString('No Hub-managed administrator role', $states['cloud']['role_status']);
        self::assertSame(['bid', 'media_control'], array_keys($registry->applicationAdministrationOptions()));
        $client->forceFill(['revoked' => true])->save();
        self::assertFalse($registry->states($target)['cmd']['operational']);
    }

    public function test_super_admin_only_account_security_is_not_an_assignable_capability_and_existing_grants_are_preserved(): void
    {
        [$actor, $target] = $this->members();
        $target->givePermissionTo(Permission::findOrCreate('admin.members.security', 'web'));
        self::assertArrayNotHasKey('admin.members.security', app(ApplicationAccessRegistry::class)->capabilityOptions());
        app(ApplicationAccessService::class)->syncAdministrationCapabilities($actor, $target, [], 'Role-test-password!', 'Clear supported capabilities');
        self::assertTrue($target->fresh()->hasDirectWebPermission('admin.members.security'));
    }

    public function test_inactive_or_password_setup_accounts_never_resolve_an_application_role(): void
    {
        [$actor] = $this->members();
        $resolver = app(ApplicationRoleResolver::class);
        foreach ([['must_change_password' => true], ['must_change_password' => false, 'account_status' => 'disabled']] as $state) {
            $actor->forceFill($state)->save();
            self::assertNull($resolver->forUser($actor->fresh(), 'bid'));
            self::assertNull($resolver->forUser($actor->fresh(), 'media_control'));
        }
    }

    /** @return array{User, User} */
    private function members(): array
    {
        $actor = User::factory()->create(['password' => 'Role-test-password!', 'account_status' => 'active']);
        $actor->assignRole(Role::findOrCreate('super_admin', 'web'));

        return [$actor, User::factory()->create(['account_status' => 'active'])];
    }
}
