<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\Employee;
use App\Models\User;
use App\Services\Identity\AccountSecurityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class AuthentikCanarySecurityBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_markerless_browser_session_is_revoked_while_guest_identity_routes_remain_available(): void
    {
        $this->withoutVite();
        $user = User::factory()->create(['account_status' => 'active']);

        $this->actingAs($user, 'web')->get('/')->assertRedirect('/login');
        $this->assertGuest('web');

        $this->get('/login')->assertOk();
        $this->get('/forgot-password')->assertOk();
    }

    public function test_legacy_admin_role_without_application_entitlement_is_denied(): void
    {
        Route::middleware(['web', 'auth', 'admin.capability:admin.system.view'])
            ->get('/_test/admin-boundary', static fn () => response()->json(['ok' => true]));
        foreach (['admin.access', 'admin.system.view'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $user = User::factory()->create(['account_status' => 'active']);
        $user->assignRole(Role::findOrCreate('logistics_admin', 'web'));
        $user->givePermissionTo('admin.system.view');

        $this->actingAsCanonicalUser($user)
            ->getJson('/_test/admin-boundary')
            ->assertForbidden();
    }

    public function test_admin_entitlement_without_required_capability_is_denied_and_scoped_capability_is_allowed(): void
    {
        Route::middleware(['web', 'auth', 'admin.capability:admin.system.view'])
            ->get('/_test/admin-capability', static fn () => response()->json(['ok' => true]));
        foreach (['admin.access', 'admin.system.view'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $user = User::factory()->create(['account_status' => 'active']);
        $user->givePermissionTo('admin.access');

        $this->actingAsCanonicalUser($user)->getJson('/_test/admin-capability')->assertForbidden();

        $user->givePermissionTo('admin.system.view');
        $this->getJson('/_test/admin-capability')->assertOk()->assertExactJson(['ok' => true]);
    }

    public function test_departed_employee_is_denied_even_when_user_state_and_historical_grants_remain_active(): void
    {
        $employee = Employee::query()->create([
            'employee_id' => 'DEPARTED-SSO-1',
            'name' => 'Departed Member',
            'roster_status' => 'departed',
            'password' => 'test-only-password',
        ]);
        $user = User::factory()->create([
            'account_status' => 'active',
            'employee_profile_id' => $employee->id,
            'employee_id' => $employee->employee_id,
        ]);

        self::assertFalse($user->isAuthenticationAllowed());
        $this->actingAs($user, 'web')->get('/')->assertRedirect('/login');
        $this->assertGuest('web');
    }

    public function test_personal_access_token_issuance_is_disabled(): void
    {
        $user = User::factory()->create();

        $this->expectException(\LogicException::class);
        $user->createToken('unsupported');
    }

    public function test_first_party_member_api_rejects_markerless_and_token_authentication(): void
    {
        Route::middleware(['web', 'auth:sanctum', 'canonical.api'])
            ->get('/_test/member-api-boundary', static fn () => response()->json(['ok' => true]));
        $user = User::factory()->create(['account_status' => 'active']);

        $this->actingAs($user, 'web')
            ->getJson('/_test/member-api-boundary')
            ->assertUnauthorized();

        DB::table('personal_access_tokens')->insert([
            'id' => 991,
            'tokenable_type' => User::class,
            'tokenable_id' => $user->getKey(),
            'name' => 'legacy-test',
            'token' => hash('sha256', 'legacy-test-token'),
            'abilities' => '["*"]',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->withToken('991|legacy-test-token')
            ->getJson('/_test/member-api-boundary')
            ->assertUnauthorized();
    }

    public function test_first_party_member_api_accepts_a_current_canonical_cookie_session(): void
    {
        Route::middleware(['web', 'auth:sanctum', 'canonical.api'])
            ->get('/_test/current-member-api', static fn () => response()->json(['ok' => true]));
        $user = User::factory()->create(['account_status' => 'active']);

        $this->actingAsCanonicalUser($user)
            ->getJson('/_test/current-member-api')
            ->assertOk()
            ->assertExactJson(['ok' => true]);
    }

    public function test_security_revocation_removes_any_preexisting_personal_access_tokens(): void
    {
        $user = User::factory()->create();
        DB::table('personal_access_tokens')->insert([
            'tokenable_type' => User::class,
            'tokenable_id' => $user->id,
            'name' => 'legacy',
            'token' => hash('sha256', 'legacy-token'),
            'abilities' => '["*"]',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app(AccountSecurityService::class)->revokeAll($user, 'test revocation', now());

        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_type' => User::class,
            'tokenable_id' => $user->id,
        ]);
    }

    public function test_upstream_security_reset_is_queued_with_session_revocation(): void
    {
        Queue::fake();
        $user = User::factory()->create(['account_status' => 'active']);
        $user->identityLinks()->create([
            'provider' => 'authentik',
            'subject' => '5c7363f4-94e3-4b37-bab0-6f9feea7e778',
            'provider_user_id' => '44',
            'status' => 'active',
        ]);

        $synchronization = app(\App\Services\Identity\IdentitySynchronizationService::class)
            ->request($user, revokeSessions: true, resetMfa: true);

        self::assertTrue($synchronization?->revoke_sessions);
        self::assertTrue($synchronization?->reset_mfa);
        Queue::assertPushed(\App\Jobs\SynchronizeIdentity::class);
    }
}
