<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\AccountStatus;
use App\Models\Employee;
use App\Models\SecurityActionEvent;
use App\Models\User;
use App\Services\Identity\CityEmailVerificationService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class MemberBootstrapOnboardingTest extends TestCase
{
    use RefreshDatabase;

    private const BOOTSTRAP_PASSWORD = 'Test-Only-Bootstrap!7824';

    private const FAILURE_MESSAGE = 'The provided credentials are invalid.';

    private const TEST_SOURCE_IP = '203.0.113.25';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('identity.local_login_enabled', true);
        config()->set(
            'identity.canonical_login_dummy_password_hash',
            '$2y$04$yXUP9UMe6agMm.ynDn8sZew4kgNTtoSKbwk49v4OrnqckhzwA3SRC',
        );
        config()->set('identity.member_bootstrap.enabled', true);
        config()->set('identity.member_bootstrap.password_hash', Hash::make(self::BOOTSTRAP_PASSWORD));
        config()->set('identity.member_bootstrap.session_ttl_seconds', 900);
        config()->set('security.member_bootstrap.max_attempts', 5);
        config()->set('security.member_bootstrap.global_max_attempts', 30);
        config()->set('security.member_bootstrap.decay_seconds', 60);
        RateLimiter::clear($this->bootstrapThrottleKey(self::TEST_SOURCE_IP));
    }

    public function test_only_explicit_bootstrap_pending_member_enters_restricted_onboarding_with_session_rotation(): void
    {
        $pending = $this->linkedUser(AccountStatus::PendingActivation, true, 'PENDING-100');
        $passwordHashBefore = $pending->getAuthPassword();
        $session = $this->app['session.store'];
        $session->setId('pre-bootstrap-session-id');
        $before = $session->getId();

        $this->post('/login', [
            'employee_id' => $pending->employee_id,
            'password' => self::BOOTSTRAP_PASSWORD,
        ])->assertRedirect('/member-onboarding');

        $this->assertGuest('web');
        $this->assertNotSame($before, $this->app['session.store']->getId());
        $this->assertIsArray(session('auth.member_bootstrap'));
        $this->assertDatabaseCount('authentication_sessions', 0);
        $this->assertSame($passwordHashBefore, $pending->fresh()->getAuthPassword());
        $this->assertSame(0, RateLimiter::attempts($this->bootstrapThrottleKey(self::TEST_SOURCE_IP)));
    }

    public function test_distinct_established_users_from_one_source_never_consume_bootstrap_capacity(): void
    {
        config()->set('security.member_bootstrap.global_max_attempts', 2);
        $users = [
            $this->linkedUser(AccountStatus::Active, false, 'ESTABLISHED-SOURCE-100', 'private-password-100'),
            $this->linkedUser(AccountStatus::Active, false, 'ESTABLISHED-SOURCE-200', 'private-password-200'),
            $this->linkedUser(AccountStatus::Active, false, 'ESTABLISHED-SOURCE-300', 'private-password-300'),
        ];

        foreach ($users as $index => $user) {
            $response = $this->withServerVariables(['REMOTE_ADDR' => self::TEST_SOURCE_IP])
                ->from('/login')
                ->post('/login', [
                    'employee_id' => $user->employee_id,
                    'password' => 'private-password-'.(($index + 1) * 100),
                ]);
            $this->assertSame(url('/'), $response->headers->get('Location'), $user->employee_id);
            $this->assertAuthenticatedAs($user, 'web');
            $this->post('/logout')->assertRedirect('/login');
        }

        $this->assertSame(0, RateLimiter::attempts($this->bootstrapThrottleKey(self::TEST_SOURCE_IP)));
    }

    public function test_successful_bootstrap_logins_do_not_consume_the_failure_bucket(): void
    {
        config()->set('security.member_bootstrap.global_max_attempts', 2);
        $users = [
            $this->linkedUser(AccountStatus::PendingActivation, true, 'BOOTSTRAP-SOURCE-100'),
            $this->linkedUser(AccountStatus::PendingActivation, true, 'BOOTSTRAP-SOURCE-200'),
            $this->linkedUser(AccountStatus::PendingActivation, true, 'BOOTSTRAP-SOURCE-300'),
        ];

        foreach ($users as $user) {
            $this->bootstrapLogin($user);
            $this->post('/member-onboarding/cancel')->assertRedirect('/login');
        }

        $this->assertSame(0, RateLimiter::attempts($this->bootstrapThrottleKey(self::TEST_SOURCE_IP)));
    }

    public function test_established_login_succeeds_after_bootstrap_source_limit_is_exhausted(): void
    {
        config()->set('security.member_bootstrap.global_max_attempts', 2);
        $firstPending = $this->linkedUser(AccountStatus::PendingActivation, true, 'EXHAUST-BOOTSTRAP-100');
        $secondPending = $this->linkedUser(AccountStatus::PendingActivation, true, 'EXHAUST-BOOTSTRAP-200');
        $thirdPending = $this->linkedUser(AccountStatus::PendingActivation, true, 'EXHAUST-BOOTSTRAP-300');
        $established = $this->linkedUser(
            AccountStatus::Active,
            false,
            'ESTABLISHED-AFTER-EXHAUSTION',
            'established-private-password',
        );

        foreach ([$firstPending, $secondPending] as $pending) {
            $this->withServerVariables(['REMOTE_ADDR' => self::TEST_SOURCE_IP])
                ->from('/login')
                ->post('/login', [
                    'employee_id' => $pending->employee_id,
                    'password' => 'wrong-bootstrap-password',
                ])->assertRedirect('/login')
                ->assertSessionHasErrors(['employee_id' => self::FAILURE_MESSAGE]);
        }

        $bootstrapKey = $this->bootstrapThrottleKey(self::TEST_SOURCE_IP);
        $this->assertTrue(RateLimiter::tooManyAttempts($bootstrapKey, 2));
        $this->withServerVariables(['REMOTE_ADDR' => self::TEST_SOURCE_IP])
            ->from('/login')
            ->post('/login', [
                'employee_id' => $thirdPending->employee_id,
                'password' => self::BOOTSTRAP_PASSWORD,
            ])->assertRedirect('/login')
            ->assertSessionHasErrors(['employee_id' => self::FAILURE_MESSAGE]);

        $this->withServerVariables(['REMOTE_ADDR' => self::TEST_SOURCE_IP])
            ->post('/login', [
                'employee_id' => $established->employee_id,
                'password' => 'established-private-password',
            ])->assertRedirect('/');
        $this->assertAuthenticatedAs($established, 'web');
    }

    public function test_unknown_login_uses_dummy_verification_without_generating_a_password_hash(): void
    {
        $dummyHashInfo = password_get_info((string) config('identity.canonical_login_dummy_password_hash'));
        $this->assertSame('bcrypt', $dummyHashInfo['algoName']);
        $this->assertSame((int) env('BCRYPT_ROUNDS', 12), $dummyHashInfo['options']['cost']);

        $instrumentedHasher = new class(Hash::driver()) implements Hasher
        {
            public int $checkCalls = 0;

            public int $makeCalls = 0;

            public function __construct(private readonly Hasher $inner) {}

            public function info($hashedValue): array
            {
                return $this->inner->info($hashedValue);
            }

            public function make(#[\SensitiveParameter] $value, array $options = []): string
            {
                $this->makeCalls++;

                return $this->inner->make($value, $options);
            }

            public function check(#[\SensitiveParameter] $value, $hashedValue, array $options = []): bool
            {
                $this->checkCalls++;

                return $this->inner->check($value, $hashedValue, $options);
            }

            public function needsRehash($hashedValue, array $options = []): bool
            {
                return $this->inner->needsRehash($hashedValue, $options);
            }
        };
        $this->app->instance('hash', $instrumentedHasher);
        Hash::clearResolvedInstance('hash');

        $this->from('/login')->post('/login', [
            'employee_id' => 'UNKNOWN-DUMMY-HASH-100',
            'password' => 'wrong-password',
        ])->assertRedirect('/login')
            ->assertSessionHasErrors(['employee_id' => self::FAILURE_MESSAGE]);

        $this->assertGuest('web');
        $this->assertSame(0, $instrumentedHasher->makeCalls);
        $this->assertSame(2, $instrumentedHasher->checkCalls);
    }

    public function test_wrong_unknown_ineligible_established_and_admin_attempts_share_generic_denial(): void
    {
        $wrong = $this->linkedUser(AccountStatus::PendingActivation, true, 'WRONG-100');
        $ineligible = $this->linkedUser(AccountStatus::PendingActivation, false, 'INELIGIBLE-100');
        $established = $this->linkedUser(AccountStatus::Active, false, 'ESTABLISHED-100', 'private-password');
        $admin = $this->linkedUser(AccountStatus::Active, false, 'ADMIN-100', 'admin-private-password');
        $admin->assignRole(Role::findOrCreate('super_admin', 'web'));

        foreach ([
            ['employee_id' => $wrong->employee_id, 'password' => 'wrong-bootstrap'],
            ['employee_id' => 'UNKNOWN-100', 'password' => self::BOOTSTRAP_PASSWORD],
            ['employee_id' => $ineligible->employee_id, 'password' => self::BOOTSTRAP_PASSWORD],
            ['employee_id' => $established->employee_id, 'password' => self::BOOTSTRAP_PASSWORD],
            ['employee_id' => $admin->employee_id, 'password' => self::BOOTSTRAP_PASSWORD],
        ] as $credentials) {
            $this->from('/login')->post('/login', $credentials)
                ->assertRedirect('/login')
                ->assertSessionHasErrors(['employee_id' => self::FAILURE_MESSAGE]);
            $this->assertGuest('web');
        }

        $this->post('/login', [
            'employee_id' => $established->employee_id,
            'password' => 'private-password',
        ])->assertRedirect('/');
        $this->assertAuthenticatedAs($established, 'web');
    }

    public function test_bootstrap_context_can_only_access_onboarding_and_cancel(): void
    {
        $pending = $this->linkedUser(AccountStatus::PendingActivation, true, 'BOUNDARY-100');
        $this->bootstrapLogin($pending);
        $this->assertIsArray(session('auth.member_bootstrap'));
        $this->assertTrue($pending->fresh()->isBootstrapOnboardingPending());
        $context = session('auth.member_bootstrap');

        $this->withSession(['auth.member_bootstrap' => $context])->get('/member-onboarding')->assertOk();
        foreach (['/', '/admin', '/employee/dashboard', '/training', '/workgroups', '/auth/bid/authorize', '/auth/media-control/authorize'] as $path) {
            $response = $this->withSession(['auth.member_bootstrap' => $context])->get($path);
            self::assertSame(url('/member-onboarding'), $response->headers->get('Location'), $path);
        }
        $this->withSession(['auth.member_bootstrap' => $context])
            ->getJson('/api/me/context')->assertUnauthorized();

        $this->withSession(['auth.member_bootstrap' => $context])
            ->post('/member-onboarding/cancel')->assertRedirect('/login');
        $this->assertFalse(session()->has('auth.member_bootstrap'));
        $this->assertGuest('web');
    }

    public function test_onboarding_prefills_authoritative_city_email_without_marking_it_verified(): void
    {
        $pending = $this->linkedUser(AccountStatus::PendingActivation, true, 'EMAIL-100');
        $pending->employeeProfile->forceFill(['city_email' => 'member@miamibeachfl.gov'])->save();
        $this->bootstrapLogin($pending);

        $this->get('/member-onboarding')
            ->assertOk()
            ->assertSee('Complete your MBFD Hub account')
            ->assertSee('member@miamibeachfl.gov')
            ->assertSee($pending->employee_id)
            ->assertDontSee($pending->employeeProfile->getRawOriginal('password'));

        $this->assertNull($pending->fresh()->email_verified_at);
    }

    public function test_city_email_validation_and_collision_are_safe_and_atomic(): void
    {
        $pending = $this->linkedUser(AccountStatus::PendingActivation, true, 'VALIDATE-100');
        $other = $this->linkedUser(AccountStatus::Active, false, 'VALIDATE-200', 'other-password');
        $other->employeeProfile->forceFill(['city_email' => 'taken@miamibeachfl.gov'])->save();
        $other->forceFill(['email' => 'taken@miamibeachfl.gov'])->save();
        $before = $pending->fresh()->getAttributes();
        $employeeBefore = $pending->employeeProfile->fresh()->getAttributes();
        $this->bootstrapLogin($pending);

        $this->post('/member-onboarding', $this->completionPayload('member@example.com'))
            ->assertSessionHasErrors('city_email');
        $this->post('/member-onboarding', $this->completionPayload('TAKEN@miamibeachfl.gov'))
            ->assertSessionHasErrors('city_email');

        $this->assertSame($before, $pending->fresh()->getAttributes());
        $this->assertSame($employeeBefore, $pending->employeeProfile->fresh()->getAttributes());
        $this->assertGuest('web');
    }

    public function test_completion_atomically_syncs_email_activates_and_permanently_consumes_eligibility(): void
    {
        $pending = $this->linkedUser(AccountStatus::PendingActivation, true, 'COMPLETE-100');
        $this->bootstrapLogin($pending);

        $this->post('/member-onboarding', $this->completionPayload('Corrected@MIAMIBEACHFL.GOV'))
            ->assertRedirect('/');

        $pending->refresh();
        $pending->employeeProfile->refresh();
        $this->assertSame(AccountStatus::Active, $pending->account_status);
        $this->assertFalse($pending->bootstrap_onboarding_eligible);
        $this->assertNotNull($pending->bootstrap_onboarding_completed_at);
        $this->assertFalse($pending->must_change_password);
        $this->assertNotNull($pending->password_changed_at);
        $this->assertSame('corrected@miamibeachfl.gov', $pending->email);
        $this->assertSame('corrected@miamibeachfl.gov', $pending->employeeProfile->city_email);
        $this->assertSame(
            'corrected@miamibeachfl.gov',
            app(CityEmailVerificationService::class)->connectedEmail($pending),
        );
        $this->assertNull($pending->email_verified_at);
        $this->assertTrue(Hash::check('New-Private-Password!7824', $pending->getAuthPassword()));
        $this->assertAuthenticatedAs($pending, 'web');
        $this->assertDatabaseHas('authentication_sessions', ['user_id' => $pending->id, 'revoked_at' => null]);
        $this->assertFalse(session()->has('auth.member_bootstrap'));
        $audit = SecurityActionEvent::query()->where('action', 'complete_member_bootstrap')->sole();
        $this->assertSame($pending->id, $audit->actor_user_id);
        $this->assertSame($pending->id, $audit->target_user_id);
        $this->assertStringNotContainsString(self::BOOTSTRAP_PASSWORD, $audit->toJson());
        $this->assertStringNotContainsString('New-Private-Password!7824', $audit->toJson());

        $this->post('/logout')->assertRedirect('/login');
        $this->from('/login')->post('/login', [
            'employee_id' => $pending->employee_id,
            'password' => self::BOOTSTRAP_PASSWORD,
        ])->assertSessionHasErrors(['employee_id' => self::FAILURE_MESSAGE]);
        $this->post('/login', [
            'employee_id' => $pending->employee_id,
            'password' => 'New-Private-Password!7824',
        ])->assertRedirect('/');
    }

    public function test_shared_bootstrap_credential_cannot_be_selected_as_permanent_password(): void
    {
        $pending = $this->linkedUser(AccountStatus::PendingActivation, true, 'REUSE-100');
        $this->bootstrapLogin($pending);

        $this->post('/member-onboarding', $this->completionPayload(
            'reuse@miamibeachfl.gov',
            self::BOOTSTRAP_PASSWORD,
        ))->assertSessionHasErrors('password');

        $this->assertSame(AccountStatus::PendingActivation, $pending->fresh()->account_status);
        $this->assertTrue($pending->fresh()->bootstrap_onboarding_eligible);
        $this->assertGuest('web');
    }

    public function test_expired_or_stale_context_cannot_overwrite_concurrent_security_changes(): void
    {
        CarbonImmutable::setTestNow('2026-09-10 12:00:00');
        $expired = $this->linkedUser(AccountStatus::PendingActivation, true, 'EXPIRED-100');
        $this->bootstrapLogin($expired);
        CarbonImmutable::setTestNow('2026-09-10 12:16:00');
        $this->get('/member-onboarding')->assertRedirect('/login');
        $this->assertFalse(session()->has('auth.member_bootstrap'));

        CarbonImmutable::setTestNow('2026-09-10 13:00:00');
        $stale = $this->linkedUser(AccountStatus::PendingActivation, true, 'STALE-100');
        $this->bootstrapLogin($stale);
        $stale->forceFill([
            'account_status' => AccountStatus::Disabled,
            'security_version' => $stale->security_version + 1,
        ])->save();

        $this->post('/member-onboarding', $this->completionPayload('stale@miamibeachfl.gov'))
            ->assertRedirect('/login');
        $stale->refresh();
        $this->assertSame(AccountStatus::Disabled, $stale->account_status);
        $this->assertTrue($stale->bootstrap_onboarding_eligible);
        $this->assertFalse(Hash::check('New-Private-Password!7824', $stale->getAuthPassword()));
        $this->assertGuest('web');
    }

    public function test_kill_switch_and_missing_hash_fail_closed(): void
    {
        $pending = $this->linkedUser(AccountStatus::PendingActivation, true, 'SWITCH-100');

        foreach ([
            ['enabled' => false, 'password_hash' => Hash::make(self::BOOTSTRAP_PASSWORD)],
            ['enabled' => true, 'password_hash' => null],
            ['enabled' => true, 'password_hash' => 'not-a-password-hash'],
        ] as $configuration) {
            config()->set('identity.member_bootstrap', $configuration + ['session_ttl_seconds' => 900]);
            $this->from('/login')->post('/login', [
                'employee_id' => $pending->employee_id,
                'password' => self::BOOTSTRAP_PASSWORD,
            ])->assertSessionHasErrors(['employee_id' => self::FAILURE_MESSAGE]);
            $this->assertGuest('web');
        }
    }

    public function test_bootstrap_attempts_are_rate_limited_without_identity_disclosure(): void
    {
        config()->set('security.member_bootstrap.global_max_attempts', 2);
        $pending = $this->linkedUser(AccountStatus::PendingActivation, true, 'RATE-BOOTSTRAP-100');
        $second = $this->linkedUser(AccountStatus::PendingActivation, true, 'RATE-BOOTSTRAP-200');
        $third = $this->linkedUser(AccountStatus::PendingActivation, true, 'RATE-BOOTSTRAP-300');

        $responses = [
            $this->from('/login')->post('/login', ['employee_id' => $pending->employee_id, 'password' => 'wrong-bootstrap']),
            $this->from('/login')->post('/login', ['employee_id' => $second->employee_id, 'password' => 'wrong-bootstrap']),
            $this->from('/login')->post('/login', ['employee_id' => $third->employee_id, 'password' => self::BOOTSTRAP_PASSWORD]),
        ];

        foreach ($responses as $response) {
            $response->assertRedirect('/login')->assertSessionHasErrors([
                'employee_id' => self::FAILURE_MESSAGE,
            ]);
        }
        $this->assertGuest('web');
    }

    public function test_onboarding_completion_retains_real_csrf_protection(): void
    {
        $this->app->bind(ValidateCsrfToken::class,
            fn ($app) => new class($app, $app['encrypter']) extends ValidateCsrfToken
            {
                protected function runningUnitTests(): bool
                {
                    return false;
                }
            });
        $pending = $this->linkedUser(AccountStatus::PendingActivation, true, 'CSRF-100');
        $this->withSession(['_token' => 'login-csrf-token'])->post('/login', [
            '_token' => 'login-csrf-token',
            'employee_id' => $pending->employee_id,
            'password' => self::BOOTSTRAP_PASSWORD,
        ])->assertRedirect('/member-onboarding');
        $context = session('auth.member_bootstrap');
        $csrf = session()->token();

        $this->withSession(['auth.member_bootstrap' => $context, '_token' => $csrf])
            ->post('/member-onboarding', $this->completionPayload('csrf@miamibeachfl.gov'))
            ->assertStatus(303)
            ->assertRedirect('/login?session_expired=1');

        $pending->refresh();
        $this->assertSame(AccountStatus::PendingActivation, $pending->account_status);
        $this->assertTrue($pending->bootstrap_onboarding_eligible);
    }

    private function linkedUser(
        AccountStatus $status,
        bool $eligible,
        string $employeeId,
        string $password = 'unrecoverable-placeholder',
    ): User {
        $employee = Employee::query()->create([
            'employee_id' => $employeeId,
            'name' => 'Member Bootstrap Test',
            'rank' => 'Firefighter',
            'roster_status' => 'active',
            'password' => Hash::make('legacy-placeholder'),
            'must_change_password' => false,
        ]);

        return User::factory()->create([
            'employee_profile_id' => $employee->id,
            'employee_id' => $employee->employee_id,
            'email' => strtolower($employeeId).'@canonical.mbfdhub.invalid',
            'account_status' => $status,
            'password' => Hash::make($password),
            'must_change_password' => $status === AccountStatus::PendingActivation,
            'bootstrap_onboarding_eligible' => $eligible,
            'bootstrap_onboarding_completed_at' => null,
        ])->load('employeeProfile');
    }

    private function bootstrapLogin(User $user): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => self::TEST_SOURCE_IP])
            ->post('/login', [
                'employee_id' => $user->employee_id,
                'password' => self::BOOTSTRAP_PASSWORD,
            ])->assertRedirect('/member-onboarding');
        $this->withSession([
            'auth.member_bootstrap' => session('auth.member_bootstrap'),
        ]);
    }

    /** @return array<string, mixed> */
    private function completionPayload(
        string $email,
        string $password = 'New-Private-Password!7824',
    ): array {
        return [
            'city_email' => $email,
            'city_email_confirmed' => '1',
            'password' => $password,
            'password_confirmation' => $password,
        ];
    }

    private function bootstrapThrottleKey(string $ip): string
    {
        return 'member-bootstrap-source:'.hash_hmac('sha256', $ip, (string) config('app.key'));
    }
}
