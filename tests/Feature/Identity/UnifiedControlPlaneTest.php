<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Enums\AccountStatus;
use App\Models\Apparatus;
use App\Models\Employee;
use App\Models\Station;
use App\Models\User;
use App\Services\Identity\AccountSecurityService as IdentityAccountSecurityService;
use App\Services\Identity\CanonicalCityEmailService;
use App\Services\Security\AccountSecurityService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class UnifiedControlPlaneTest extends TestCase
{
    use RefreshDatabase;

    public function test_schema_supports_canonical_identity_entitlements_notifications_and_email_ledgers(): void
    {
        self::assertTrue(Schema::hasColumns('employees', ['city_email', 'roster_status']));
        self::assertTrue(Schema::hasColumns('users', ['last_login_at']));
        self::assertTrue(Schema::hasTable('user_notification_subscriptions'));
        self::assertTrue(Schema::hasTable('outbound_emails'));
        self::assertTrue(Schema::hasTable('inbound_emails'));
        self::assertTrue(Schema::hasTable('inbound_email_nonces'));
        self::assertTrue(Schema::hasTable('cloudflare_usage_budgets'));
    }

    public function test_explicit_application_entitlements_are_independent_from_admin_access(): void
    {
        foreach (['admin.access', 'app.media_control.access', 'app.bid.access'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $mediaOnly = User::factory()->create(['account_status' => AccountStatus::Active]);
        $mediaOnly->givePermissionTo('app.media_control.access');

        self::assertTrue($mediaOnly->hasCurrentMediaControlEntitlement());
        self::assertFalse($mediaOnly->hasCurrentBidEntitlement());
        self::assertFalse($mediaOnly->hasCurrentAdminPanelEntitlement());

        $bidOnly = User::factory()->create(['account_status' => AccountStatus::Active]);
        $bidOnly->givePermissionTo('app.bid.access');

        self::assertFalse($bidOnly->hasCurrentMediaControlEntitlement());
        self::assertTrue($bidOnly->hasCurrentBidEntitlement());
        self::assertFalse($bidOnly->hasCurrentAdminPanelEntitlement());
    }

    public function test_delegated_admin_capabilities_control_model_actions_independently(): void
    {
        foreach (['admin.access', 'admin.fleet.view', 'admin.fleet.manage', 'admin.stations.view'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $fleetViewer = User::factory()->create(['account_status' => AccountStatus::Active]);
        $fleetViewer->givePermissionTo(['admin.access', 'admin.fleet.view']);

        self::assertTrue($fleetViewer->can('viewAny', Apparatus::class));
        self::assertFalse($fleetViewer->can('create', Apparatus::class));
        self::assertFalse($fleetViewer->can('viewAny', Station::class));

        $fleetViewer->givePermissionTo('admin.fleet.manage');

        self::assertTrue($fleetViewer->can('create', Apparatus::class));
    }

    public function test_legacy_claim_password_transition_forces_change_once_and_preserves_authorization(): void
    {
        $employee = Employee::query()->create([
            'employee_id' => 'CLAIM-20731',
            'name' => 'Existing Administrator',
            'password' => 'employee-temporary',
            'must_change_password' => true,
        ]);
        $user = User::factory()->create([
            'account_status' => AccountStatus::PendingActivation,
            'must_change_password' => false,
            'password' => 'existing-password',
        ]);
        $user->assignRole(Role::findOrCreate('admin', 'web'));
        $user->givePermissionTo(Permission::findOrCreate('view_any_user', 'web'));
        $beforeVersion = $user->security_version;

        $first = app(IdentityAccountSecurityService::class)->completeCanonicalLink(
            $user,
            $employee->id,
            $employee->employee_id,
            $employee->getRawOriginal('password'),
            CarbonImmutable::parse('2026-09-04T12:00:00Z'),
        );

        $linked = $user->fresh();
        self::assertTrue($first['changed']);
        self::assertTrue($first['password_changed']);
        self::assertSame($user->id, $linked->id);
        self::assertTrue($linked->must_change_password);
        self::assertSame($beforeVersion + 1, $linked->security_version);
        self::assertTrue(Hash::check('employee-temporary', $linked->password));
        self::assertTrue($linked->hasRole('admin'));
        self::assertTrue($linked->hasDirectPermission('view_any_user'));

        $second = app(IdentityAccountSecurityService::class)->completeCanonicalLink(
            $linked,
            $employee->id,
            $employee->employee_id,
            $employee->getRawOriginal('password'),
            CarbonImmutable::parse('2026-09-04T12:05:00Z'),
        );

        self::assertFalse($second['changed']);
        self::assertSame($beforeVersion + 1, $user->fresh()->security_version);
    }

    public function test_authorized_admin_reset_is_write_only_forces_change_and_revokes_sessions(): void
    {
        session()->put((string) config('security.recent_authentication.session_key'), time());
        config()->set('security.account_security.allowed_administrative_actions', [
            'administrative_recovery',
            'force_password_change',
            'revoke_sessions',
            'disable',
            'enable',
        ]);
        Permission::findOrCreate('admin.members.security', 'web');
        $superRole = Role::findOrCreate('super_admin', 'web');
        $actor = User::factory()->create(['account_status' => AccountStatus::Active]);
        $actor->assignRole($superRole);
        $target = User::factory()->create([
            'account_status' => AccountStatus::Active,
            'must_change_password' => false,
            'password' => 'old-password',
        ]);
        $beforeVersion = $target->security_version;

        app(AccountSecurityService::class)->resetPassword(
            $actor,
            $target,
            'temporary-owner-authorized-password',
            'authorized recovery',
            CarbonImmutable::parse('2026-09-04T13:00:00Z'),
        );

        $reset = $target->fresh();
        self::assertTrue($reset->must_change_password);
        self::assertSame($beforeVersion + 1, $reset->security_version);
        self::assertTrue(Hash::check('temporary-owner-authorized-password', $reset->password));
        $this->assertDatabaseHas('security_action_events', [
            'actor_user_id' => $actor->id,
            'target_user_id' => $target->id,
            'action' => 'administrative_recovery',
            'result' => 'allowed',
        ]);
    }

    public function test_authoritative_city_email_is_normalized_and_synchronized_without_duplicate_user(): void
    {
        $employee = Employee::query()->create([
            'employee_id' => 'CITY-1',
            'name' => 'City Member',
            'password' => 'employee-password',
        ]);
        $user = User::factory()->create(['employee_profile_id' => $employee->id]);

        app(CanonicalCityEmailService::class)->sync($employee, $user, ' City.Member@MiamiBeachFL.gov ');

        self::assertSame('city.member@miamibeachfl.gov', $employee->fresh()->city_email);
        self::assertSame('city.member@miamibeachfl.gov', $user->fresh()->email);
    }
}
