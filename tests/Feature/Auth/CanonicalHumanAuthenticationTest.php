<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\AccountStatus;
use App\Models\AuthenticationSession;
use App\Models\Employee;
use App\Models\User;
use App\Services\Identity\AccountSecurityService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class CanonicalHumanAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private const FAILURE_MESSAGE = 'The provided credentials are invalid.';

    public function test_anonymous_human_surfaces_fail_closed_while_the_login_and_trust_page_remain_public(): void
    {
        $this->withoutVite();
        $this->get('/')->assertRedirect('/login');
        $this->get('/daily/stations')->assertRedirect('/login');
        $this->getJson('/api/public/apparatuses')->assertUnauthorized();
        $this->get('/login')->assertOk();
        $this->get('/security-standards')->assertOk();
    }

    public function test_linked_active_user_authenticates_by_employee_id_with_a_regenerated_registered_session(): void
    {
        $user = $this->linkedUser(AccountStatus::Active, 'correct-password');
        $session = $this->app['session.store'];
        $session->setId('pre-authentication-session-id');
        $this->withSession(['pre_authentication_probe' => true]);
        $before = $session->getId();

        $response = $this->post('/login', [
            'employee_id' => $user->employeeProfile->employee_id,
            'password' => 'correct-password',
        ]);

        $response->assertRedirect('/');
        $this->assertAuthenticatedAs($user, 'web');
        $after = $this->app['session.store']->getId();
        $this->assertNotSame($before, $after);
        $this->assertIsInt(session((string) config('security.recent_authentication.session_key')));

        $registered = AuthenticationSession::query()->sole();
        $this->assertSame($user->id, $registered->user_id);
        $this->assertSame($user->security_version, $registered->security_version);
        $this->assertSame('unmanaged_browser', $registered->context_class->value);
        $this->assertSame(
            hash_hmac('sha256', $after, (string) config('app.key')),
            $registered->getRawOriginal('session_id_hash'),
        );
        $this->assertNotSame($after, $registered->getRawOriginal('session_id_hash'));
        $this->assertSame($registered->id, session('auth.canonical_session_id'));
    }

    public function test_canonical_login_session_serves_member_context_without_a_bearer_token_and_logout_revokes_both_surfaces(): void
    {
        $user = $this->linkedUser(AccountStatus::Active, 'correct-password');

        $this->post('/login', [
            'employee_id' => $user->employeeProfile->employee_id,
            'password' => 'correct-password',
        ])->assertRedirect('/');

        $registered = AuthenticationSession::query()->sole();
        $this->withCookie((string) config('session.cookie'), $this->app['session.store']->getId());
        $this->withCredentials();

        $this->getJson(
            '/api/me/context?user_id=999999&employee_id=999999&actor_id=999999',
            $this->sameOriginHeaders(),
        )
            ->assertOk()
            ->assertJsonPath('identity.user_id', $user->id)
            ->assertJsonPath('personnel.employee_profile_id', $user->employeeProfile->id)
            ->assertJsonPath('personnel.employee_number', $user->employeeProfile->employee_id)
            ->assertJsonMissingPath('identity.email');

        $this->assertFalse($this->app['request']->headers->has('Authorization'));
        $context = app(\App\Services\Identity\AuthenticatedMemberContextResolver::class)
            ->resolve($this->app['request']);
        $this->assertSame($user->id, $context->actor()->userId());
        $this->assertTrue($context->actor()->employee()?->is($user->employeeProfile));

        $this->post('/logout')->assertRedirect('/login');
        $this->assertNotNull($registered->fresh()->revoked_at);
        $this->getJson('/api/me/context', $this->sameOriginHeaders())->assertUnauthorized();
    }

    public function test_disabling_a_canonical_user_revokes_the_same_web_and_api_session_without_changing_password(): void
    {
        $user = $this->linkedUser(AccountStatus::Active, 'correct-password');
        $passwordHash = $user->getRawOriginal('password');
        $this->post('/login', [
            'employee_id' => $user->employeeProfile->employee_id,
            'password' => 'correct-password',
        ])->assertRedirect('/');

        app(AccountSecurityService::class)->disable($user, 'D04 test disable', CarbonImmutable::now());

        $this->getJson('/api/me/context', $this->sameOriginHeaders())->assertUnauthorized();
        $this->get('/')->assertRedirect('/login');
        $this->assertGuest('web');
        $this->assertSame($passwordHash, $user->fresh()->getRawOriginal('password'));
    }

    public function test_security_version_advance_cannot_regain_access_by_switching_between_api_and_web_requests(): void
    {
        $user = $this->linkedUser(AccountStatus::Active, 'correct-password');
        $this->post('/login', [
            'employee_id' => $user->employeeProfile->employee_id,
            'password' => 'correct-password',
        ])->assertRedirect('/');

        app(AccountSecurityService::class)->revokeAll($user, 'D04 test security version advance', CarbonImmutable::now());

        $this->getJson('/api/me/context', $this->sameOriginHeaders())->assertUnauthorized();
        $this->get('/')->assertRedirect('/login');
        $this->getJson('/api/me/context', $this->sameOriginHeaders())->assertUnauthorized();
        $this->assertGuest('web');
    }

    public function test_invalid_password_is_generic_and_does_not_establish_or_mutate_identity_state(): void
    {
        $user = $this->linkedUser(AccountStatus::Active, 'correct-password');
        $employeeCount = Employee::query()->count();
        $userCount = User::query()->count();
        $originalUser = (array) DB::table('users')->where('id', $user->id)->first();

        $response = $this->from('/login')->post('/login', [
            'employee_id' => $user->employeeProfile->employee_id,
            'password' => 'wrong-password',
        ]);

        $response->assertRedirect('/login');
        $response->assertSessionHasErrors(['employee_id' => self::FAILURE_MESSAGE]);
        $this->assertGuest('web');
        $this->assertDatabaseCount('authentication_sessions', 0);
        $this->assertSame($employeeCount, Employee::query()->count());
        $this->assertSame($userCount, User::query()->count());
        $this->assertSame($originalUser, (array) DB::table('users')->where('id', $user->id)->first());
    }

    public function test_unknown_employee_id_fails_without_creating_an_identity(): void
    {
        $response = $this->from('/login')->post('/login', [
            'employee_id' => 'UNKNOWN-10010',
            'password' => 'not-relevant',
        ]);

        $response->assertRedirect('/login');
        $response->assertSessionHasErrors(['employee_id' => self::FAILURE_MESSAGE]);
        $this->assertGuest('web');
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('employees', 0);
        $this->assertDatabaseCount('authentication_sessions', 0);
    }

    public function test_unlinked_employee_requires_a_valid_legacy_employee_credential_before_activation(): void
    {
        $employee = $this->employee('UNLINKED-100', 'correct-employee-password');

        $this->from('/login')->post('/login', [
            'employee_id' => $employee->employee_id,
            'password' => 'wrong-password',
        ])->assertRedirect('/login')
            ->assertSessionHasErrors(['employee_id' => self::FAILURE_MESSAGE]);

        $this->assertFalse(session()->has('auth.canonical_activation_intent'));
        $this->assertDatabaseCount('users', 0);
        $this->assertGuest('web');
    }

    public function test_legacy_employee_id_field_without_canonical_profile_link_cannot_authenticate_that_user(): void
    {
        $employee = $this->employee('10010', 'legacy-password');
        User::factory()->create([
            'employee_id' => $employee->employee_id,
            'employee_profile_id' => null,
            'account_status' => AccountStatus::Active,
            'password' => Hash::make('legacy-password'),
        ]);

        $response = $this->from('/login')->post('/login', [
            'employee_id' => $employee->employee_id,
            'password' => 'legacy-password',
        ]);

        $response->assertRedirect('/activate-account');
        $this->assertGuest('web');
        $this->assertTrue(session()->has('auth.canonical_activation_intent'));
        $this->assertDatabaseCount('authentication_sessions', 0);
    }

    public function test_disabled_and_pending_accounts_receive_the_same_generic_denial(): void
    {
        foreach ([AccountStatus::Disabled, AccountStatus::PendingActivation] as $index => $status) {
            $user = $this->linkedUser($status, 'correct-password', 'STATUS-'.$index);

            $response = $this->from('/login')->post('/login', [
                'employee_id' => $user->employeeProfile->employee_id,
                'password' => 'correct-password',
            ]);

            $response->assertSessionHasErrors(['employee_id' => self::FAILURE_MESSAGE]);
            $this->assertGuest('web');
        }

        $this->assertDatabaseCount('authentication_sessions', 0);
    }

    public function test_security_version_mismatch_invalidates_a_canonical_session_on_the_next_request(): void
    {
        $user = $this->linkedUser(AccountStatus::Active, 'correct-password');
        $this->post('/login', [
            'employee_id' => $user->employeeProfile->employee_id,
            'password' => 'correct-password',
        ])->assertRedirect('/');
        $registered = AuthenticationSession::query()->sole();

        DB::table('users')->where('id', $user->id)->increment('security_version');

        $this->get('/')->assertRedirect('/login');
        $this->assertGuest('web');
        $this->assertNotNull($registered->fresh()->revoked_at);
    }

    public function test_password_change_and_disable_each_invalidate_previously_established_access(): void
    {
        foreach (['password', 'disable'] as $index => $action) {
            $user = $this->linkedUser(AccountStatus::Active, 'correct-password', 'REVOKE-'.$index);
            $this->post('/login', [
                'employee_id' => $user->employeeProfile->employee_id,
                'password' => 'correct-password',
            ])->assertRedirect('/');
            $registered = AuthenticationSession::query()->where('user_id', $user->id)->sole();
            $at = CarbonImmutable::now();

            if ($action === 'password') {
                app(AccountSecurityService::class)->recordPasswordChange($user, $at);
            } else {
                app(AccountSecurityService::class)->disable($user, 'test disable', $at);
            }

            $this->get('/')->assertRedirect('/login');
            $this->assertGuest('web');
            $this->assertNotNull($registered->fresh()->revoked_at);
        }
    }

    public function test_logout_revokes_the_current_registry_record_and_invalidates_the_session(): void
    {
        $user = $this->linkedUser(AccountStatus::Active, 'correct-password');
        $this->post('/login', [
            'employee_id' => $user->employeeProfile->employee_id,
            'password' => 'correct-password',
        ])->assertRedirect('/');
        $registered = AuthenticationSession::query()->sole();

        $response = $this->post('/logout');

        $response->assertRedirect('/login');
        $this->assertGuest('web');
        $this->assertNotNull($registered->fresh()->revoked_at);
        $this->assertSame('logout', $registered->fresh()->revoked_reason);
        $this->assertNull(session('auth.canonical_session_id'));
    }

    public function test_rate_limiting_is_isolated_by_exact_employee_id_on_a_shared_ip(): void
    {
        $first = $this->linkedUser(AccountStatus::Active, 'first-password', 'RATE-1');
        $second = $this->linkedUser(AccountStatus::Active, 'second-password', 'RATE-2');

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->from('/login')->post('/login', [
                'employee_id' => $first->employeeProfile->employee_id,
                'password' => 'wrong-password',
            ])->assertSessionHasErrors('employee_id');
        }

        $this->post('/login', [
            'employee_id' => $second->employeeProfile->employee_id,
            'password' => 'second-password',
        ])->assertRedirect('/');
        $this->assertAuthenticatedAs($second, 'web');
    }

    public function test_canonical_page_exists_without_removing_legacy_panel_login_routes(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('Employee ID');
        $this->assertTrue(app('router')->has('filament.admin.auth.login'));
        $this->assertTrue(app('router')->has('filament.employee.auth.login'));
    }

    private function linkedUser(
        AccountStatus $status,
        string $password,
        string $employeeId = '10010',
    ): User {
        $employee = $this->employee($employeeId, 'legacy-'.$password);

        return User::factory()->create([
            'employee_profile_id' => $employee->id,
            'account_status' => $status,
            'password' => Hash::make($password),
        ])->load('employeeProfile');
    }

    private function employee(string $employeeId, string $password): Employee
    {
        return Employee::query()->create([
            'employee_id' => $employeeId,
            'name' => 'Canonical Login Test Employee',
            'rank' => 'Firefighter',
            'password' => $password,
            'must_change_password' => false,
        ]);
    }

    /** @return array<string, string> */
    private function sameOriginHeaders(): array
    {
        return [
            'Origin' => 'http://localhost',
            'Referer' => 'http://localhost/',
        ];
    }
}
