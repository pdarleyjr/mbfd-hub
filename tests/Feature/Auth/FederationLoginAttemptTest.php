<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\AccountStatus;
use App\Models\AuthenticationSession;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class FederationLoginAttemptTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->bind(ValidateCsrfToken::class,
            fn ($app) => new class($app, $app['encrypter']) extends ValidateCsrfToken
            {
                protected function runningUnitTests(): bool
                {
                    return false;
                }
            });
        $this->withCredentials();
    }

    public function test_two_tabs_keep_their_own_destination_and_second_tab_uses_existing_login(): void
    {
        $user = $this->member();
        $first = '/auth/media-control/authorize?client_id=media-control&state=first%2Bstate';
        $second = '/auth/bid/authorize?client_id=bid&state=second%2Fstate';
        $firstLogin = $this->begin($first);
        $secondLogin = $this->begin($second);
        self::assertNotSame($firstLogin, $secondLogin);
        $this->get('/admin')->assertRedirect('/login');
        $this->get($firstLogin)->assertOk()->assertSee($firstLogin, false);
        $this->post($firstLogin, ['_token' => session()->token(), 'employee_id' => $user->employee_id, 'password' => 'correct-password'])
            ->assertRedirect($first);
        $this->withCookie((string) config('session.cookie'), session()->getId());

        $this->get($secondLogin)->assertRedirect($second);
        $this->get($firstLogin)->assertStatus(409)->assertSee('Return to the application');
        self::assertDatabaseCount('authentication_sessions', 1);
    }

    public function test_real_csrf_failure_retains_only_cookie_bound_attempt_query_and_never_replays_input(): void
    {
        $user = $this->member();
        $handoff = '/auth/media-control/authorize?state=own-attempt';
        $login = $this->begin($handoff);
        $this->get($login)->assertOk();
        session()->invalidate();
        session()->regenerateToken();
        $this->withCookie((string) config('session.cookie'), session()->getId());
        $response = $this->withHeader('Referer', 'https://attacker.invalid/steal')->post($login, [
            '_token' => 'stale-token', 'employee_id' => $user->employee_id, 'password' => 'private-password',
            'return_to' => 'https://attacker.invalid/steal',
        ]);
        $response->assertStatus(303)->assertRedirect($login.'&session_expired=1');
        $response->assertDontSee('private-password')->assertDontSee('attacker.invalid');
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertNull(session('_old_input'));
        $this->assertGuest('web');
        $this->get($login.'&session_expired=1')->assertOk()->assertSee('Unsaved changes were not submitted');
        $this->post($login, ['_token' => session()->token(), 'employee_id' => $user->employee_id, 'password' => 'correct-password'])
            ->assertRedirect($handoff);
    }

    public function test_attempt_requires_its_own_browser_cookie_and_rejects_arbitrary_contexts(): void
    {
        $login = $this->begin('/auth/media-control/authorize?state=private-target');
        $this->defaultCookies = [];
        $this->get($login)->assertStatus(409)->assertDontSee('private-target');
        foreach (['https://attacker.invalid/steal', str_repeat('a', 64), ['nested']] as $invalid) {
            $this->get('/login?'.http_build_query(['login_attempt' => $invalid]))->assertStatus(409)->assertDontSee('attacker.invalid');
        }
    }

    public function test_expired_attempt_requires_explicit_restart_instead_of_home_fallback(): void
    {
        $this->member();
        $login = $this->begin('/auth/media-control/authorize?state=expired-attempt');
        $this->travel(301)->seconds();
        $this->get($login)->assertStatus(409)->assertSee('Return to the application');
        $this->post($login, ['_token' => session()->token(), 'employee_id' => 'ATTEMPT-TEST', 'password' => 'correct-password'])
            ->assertStatus(409);
        $this->assertGuest('web');
    }

    public function test_wrong_password_keeps_only_the_same_attempt_and_never_flashes_password(): void
    {
        $this->member();
        $login = $this->begin('/auth/media-control/authorize?state=retry');
        $this->get($login)->assertOk();
        $this->from('https://attacker.invalid/return')->post($login, [
            '_token' => session()->token(), 'employee_id' => 'ATTEMPT-TEST', 'password' => 'wrong-password',
        ])->assertRedirect($login)->assertSessionHasErrors('employee_id');
        self::assertNull(session('_old_input.password'));
        $this->get($login)->assertOk();
    }

    public function test_attempts_are_bounded_and_cookies_are_host_only_http_only_and_lax(): void
    {
        for ($i = 0; $i < 8; $i++) {
            $this->begin('/auth/media-control/authorize?state=attempt-'.$i);
        }
        $this->get('/auth/media-control/authorize?state=ninth')->assertStatus(409)->assertSee('Return to the application');
    }

    public function test_attempt_issuance_stays_host_only_with_parent_domain_session_configuration(): void
    {
        config(['session.domain' => '.mbfdhub.com']);
        Cookie::setDefaultPathAndDomain('/', '.mbfdhub.com', true, 'lax');

        $this->begin('/auth/media-control/authorize?state=host-only-issuance');
        self::assertSame('.mbfdhub.com', Cookie::make('ordinary-cookie', 'ordinary')->getDomain());
    }

    public function test_attempt_deletion_stays_host_only_with_parent_domain_session_configuration(): void
    {
        $user = $this->member();
        $handoff = '/auth/media-control/authorize?state=host-only-deletion';
        $login = $this->begin($handoff);
        config(['session.domain' => '.mbfdhub.com']);
        Cookie::setDefaultPathAndDomain('/', '.mbfdhub.com', true, 'lax');
        $this->get($login)->assertOk();

        $response = $this->post($login, [
            '_token' => session()->token(), 'employee_id' => $user->employee_id, 'password' => 'correct-password',
        ])->assertRedirect($handoff);
        $cookies = array_values(array_filter($response->headers->getCookies(),
            static fn ($cookie): bool => str_starts_with($cookie->getName(), 'hub_login_attempt_')));
        self::assertCount(1, $cookies);
        self::assertNull($cookies[0]->getDomain());
        self::assertSame('/', $cookies[0]->getPath());
        self::assertLessThan(time(), $cookies[0]->getExpiresTime());
        self::assertSame('.mbfdhub.com', $response->getCookie((string) config('session.cookie'))->getDomain());
    }

    public function test_destination_copy_is_fixed_and_never_reflects_client_supplied_names(): void
    {
        foreach ([
            '/auth/media-control/authorize' => 'Media Control',
            '/auth/bid/authorize' => 'MBFD Bid',
            '/oauth/authorize' => 'your MBFD application',
        ] as $path => $label) {
            $login = $this->begin($path.'?client_id=attacker-label&state=opaque');
            $this->get($login)->assertOk()->assertSee('Sign in to continue to '.$label)->assertDontSee('attacker-label');
        }
        $this->get('/login')->assertOk()->assertDontSee('Sign in to continue to');
    }

    public function test_419_never_accepts_an_attempt_from_body_or_foreign_cookie(): void
    {
        $login = $this->begin('/auth/media-control/authorize?state=own');
        parse_str((string) parse_url($login, PHP_URL_QUERY), $query);
        $this->post('/login', ['_token' => 'invalid', 'login_attempt' => $query['login_attempt']])
            ->assertStatus(303)->assertRedirect('/login?session_expired=1');
        $this->defaultCookies = [];
        $this->post($login, ['_token' => 'invalid', 'password' => 'never-store'])
            ->assertStatus(303)->assertRedirect('/login?login_attempt=expired&session_expired=1');
        $this->get('/login?login_attempt=expired&session_expired=1')->assertStatus(409);
        self::assertNull(session('_old_input'));
    }

    public function test_attempt_is_not_authority_to_use_an_arbitrary_callback_or_bypass_current_entitlement(): void
    {
        $user = $this->member();
        $handoff = '/auth/media-control/authorize?client_id=media-control&redirect_uri=https%3A%2F%2Fattacker.invalid%2Fcallback&state=opaque';
        $login = $this->begin($handoff);
        $this->get($login)->assertOk();
        $this->post($login, ['_token' => session()->token(), 'employee_id' => $user->employee_id, 'password' => 'correct-password'])
            ->assertRedirect($handoff);
        $this->withCookie((string) config('session.cookie'), session()->getId());
        $this->getJson($handoff)->assertUnprocessable();
        self::assertSame(0, $user->fresh()->permissions()->count());
    }

    public function test_server_context_contains_only_navigation_expiry_and_hashed_binding(): void
    {
        $this->member();
        $login = $this->begin('/auth/media-control/authorize?state=opaque');
        parse_str((string) parse_url($login, PHP_URL_QUERY), $query);
        $record = Cache::get('canonical-federation-login:'.$query['login_attempt']);
        self::assertIsArray($record);
        self::assertSame(['destination', 'binding_hash', 'expires_at'], array_keys($record));
        self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $record['binding_hash']);
    }

    public function test_cookie_omission_cannot_evade_issuance_budget_and_window_resets(): void
    {
        $this->freezeTime();
        for ($i = 0; $i < 120; $i++) {
            $this->defaultCookies = [];
            $this->get('/auth/media-control/authorize?state=attempt-'.$i)->assertRedirect();
        }
        $response = $this->get('/auth/media-control/authorize?state=blocked')->assertStatus(429)->assertHeader('Retry-After', '60');
        self::assertCount(0, array_filter($response->headers->getCookies(), static fn ($cookie): bool => str_starts_with($cookie->getName(), 'hub_login_attempt_')));
        $this->travel(59)->seconds();
        $this->get('/auth/media-control/authorize?state=still-blocked')->assertStatus(429)->assertHeader('Retry-After', '1');
        $this->travel(2)->seconds();
        $this->get('/auth/media-control/authorize?state=new-window')->assertRedirect();
    }

    public function test_validation_failure_retains_the_attempt_without_flashing_unrelated_input(): void
    {
        $login = $this->begin('/auth/media-control/authorize?state=validation-retry');
        $this->get($login)->assertOk();
        $this->from('https://attacker.invalid/return')->post($login, [
            '_token' => session()->token(), 'password' => 'private-password', 'profile' => 'private-profile',
        ])->assertRedirect($login)->assertSessionHasErrors('employee_id');
        self::assertNull(session('_old_input.password'));
        self::assertNull(session('_old_input.profile'));
    }

    public function test_first_login_retains_attempt_through_activation_and_validation_does_not_flash_credentials(): void
    {
        Employee::query()->create([
            'employee_id' => 'NEW-ATTEMPT', 'name' => 'New Attempt Member', 'rank' => 'Firefighter',
            'password' => Hash::make('legacy-password'), 'must_change_password' => false,
        ]);
        $handoff = '/auth/media-control/authorize?state=first-login';
        $login = $this->begin($handoff);
        $this->get($login)->assertOk();
        $activation = $this->post($login, ['_token' => session()->token(), 'employee_id' => 'NEW-ATTEMPT', 'password' => 'legacy-password'])
            ->assertRedirect()->headers->get('Location');
        self::assertIsString($activation);
        self::assertStringContainsString('/activate-account?login_attempt=', $activation);
        $this->withCookie((string) config('session.cookie'), session()->getId());
        $page = $this->get($activation)->assertOk();
        $this->from('https://attacker.invalid/return')->post($activation, [
            '_token' => session()->token(), 'nonce' => $page->viewData('nonce'), 'path' => 'invalid',
            'legacy_password' => 'never-retain-legacy', 'profile' => 'private-profile',
        ])->assertRedirect($activation)->assertSessionHasErrors('path');
        self::assertNull(session('_old_input.legacy_password'));
        self::assertNull(session('_old_input.profile'));
        $page = $this->get($activation)->assertOk();
        $this->post($activation, [
            '_token' => session()->token(), 'nonce' => $page->viewData('nonce'),
            'path' => 'no_existing_user', 'no_legacy_account_assertion' => '1',
        ])->assertRedirect($handoff);
        self::assertSame(1, User::query()->where('employee_id', 'NEW-ATTEMPT')->count());
    }

    public function test_activation_session_loss_requires_fresh_credentials_without_losing_its_bound_attempt(): void
    {
        $login = $this->begin('/auth/media-control/authorize?state=activation-retry');
        $activation = str_replace('/login?', '/activate-account?', $login);
        $this->get($activation)->assertRedirect($login);
        $this->post($activation, ['_token' => 'stale', 'legacy_password' => 'never-retain'])
            ->assertStatus(303)->assertRedirect($login.'&session_expired=1');
        self::assertNull(session('_old_input'));
        $this->post($activation, [
            '_token' => session()->token(), 'nonce' => str_repeat('a', 64),
            'path' => 'no_existing_user', 'no_legacy_account_assertion' => '1',
        ])->assertRedirect($login);
        self::assertSame(0, User::query()->count());
    }

    public function test_revocation_while_login_tab_is_open_preserves_only_its_bound_context(): void
    {
        $user = $this->member();
        $handoff = '/auth/media-control/authorize?state=stale-open-tab';
        $login = $this->begin($handoff);
        $this->actingAsCanonicalUser($user);
        AuthenticationSession::query()->sole()->forceFill(['revoked_at' => now()])->save();
        $this->get($login)->assertRedirect($login.'&session_expired=1');
        $this->assertGuest('web');
        $this->withCookie((string) config('session.cookie'), session()->getId());
        $this->get($login)->assertOk();
        $this->post($login, ['_token' => session()->token(), 'employee_id' => $user->employee_id, 'password' => 'correct-password'])
            ->assertRedirect($handoff);
    }

    private function begin(string $handoff): string
    {
        $response = $this->get($handoff)->assertRedirect();
        $login = (string) $response->headers->get('Location');
        self::assertMatchesRegularExpression('#^/login\?login_attempt=[a-f0-9]{64}$#', $login);
        foreach ($response->headers->getCookies() as $cookie) {
            if (str_starts_with($cookie->getName(), 'hub_login_attempt_')) {
                self::assertTrue($cookie->isHttpOnly());
                self::assertSame('lax', $cookie->getSameSite());
                self::assertNull($cookie->getDomain());
                self::assertSame('/', $cookie->getPath());
                $this->withCookie($cookie->getName(), $response->getCookie($cookie->getName())->getValue());
            }
        }
        $this->withCookie((string) config('session.cookie'), session()->getId());

        return $login;
    }

    private function member(): User
    {
        $employee = Employee::query()->create([
            'employee_id' => 'ATTEMPT-TEST', 'name' => 'Attempt Test Member', 'rank' => 'Firefighter',
            'city_email' => 'attempttest@miamibeachfl.gov', 'password' => Hash::make('legacy-password'), 'must_change_password' => false,
        ]);

        return User::factory()->create([
            'account_status' => AccountStatus::Active, 'employee_id' => $employee->employee_id,
            'employee_profile_id' => $employee->id, 'email' => $employee->city_email,
            'password' => Hash::make('correct-password'), 'must_change_password' => false,
        ]);
    }
}
