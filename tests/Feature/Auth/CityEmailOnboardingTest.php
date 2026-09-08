<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\AccountStatus;
use App\Http\Middleware\EnsureCityEmailReview;
use App\Models\CityEmailVerification;
use App\Models\Employee;
use App\Models\User;
use App\Services\Identity\CityEmailVerificationService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class CityEmailOnboardingTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_sessions_are_not_interrupted_but_fresh_login_requires_city_email_review(): void
    {
        $user = $this->member();
        $this->withoutVite();
        $this->actingAsCanonicalUser($user)->get('/')->assertOk();
        $this->post('/logout')->assertRedirect('/login');

        $this->post('/login', [
            'employee_id' => $user->employee_id,
            'password' => 'city-email-test-password',
        ])->assertRedirect('/');

        $this->get('/')->assertRedirect('/account/city-email');
        $this->assertTrue(session(EnsureCityEmailReview::SESSION_KEY));
        $this->get('/account/city-email')->assertOk()->assertSee('Confirm your city email');
        $this->post('/logout')->assertRedirect('/login');
    }

    public function test_connected_city_and_grandfathered_email_accounts_skip_review_without_becoming_verified_or_sending_mail(): void
    {
        Http::fake();
        $this->withoutVite();
        foreach (['existingmember@miamibeachfl.gov', 'existing.member@gmail.com'] as $index => $email) {
            $user = $this->member('-CONNECTED-'.$index);
            $user->forceFill(['email' => $email, 'email_verified_at' => null])->save();
            $before = $user->fresh()->getRawOriginal();
            $this->post('/login', ['employee_id' => $user->employee_id, 'password' => 'city-email-test-password'])->assertRedirect('/');
            $this->get('/')->assertOk();
            self::assertFalse(session(EnsureCityEmailReview::SESSION_KEY));
            $this->get('/account/city-email')->assertOk()->assertSee('Email already connected')->assertSee($email)
                ->assertDontSee('Confirm your city email')->assertDontSee('Send a new verification link');
            self::assertNull($user->fresh()->email_verified_at);
            self::assertNull(app(CityEmailVerificationService::class)->status($user));
            foreach (['email', 'employee_id', 'employee_profile_id', 'password', 'security_version'] as $field) {
                self::assertSame($before[$field], $user->fresh()->getRawOriginal($field));
            }
            $this->post('/logout')->assertRedirect('/login');
        }
        Http::assertNothingSent();
        $this->assertDatabaseCount('outbound_emails', 0);
    }

    public function test_employee_connected_address_takes_precedence_without_rewriting_differing_account_email(): void
    {
        $user = $this->member('-EMPLOYEE-CONNECTED');
        $user->forceFill(['email' => 'account.member@gmail.com', 'email_verified_at' => null])->save();
        $user->employeeProfile->update(['city_email' => 'employeemember@miamibeachfl.gov']);
        $this->actingAsCanonicalUser($user)->withSession([EnsureCityEmailReview::SESSION_KEY => true]);
        $this->withoutVite();
        $this->get('/')->assertOk();
        $this->get('/account/city-email')->assertSee('Email already connected')->assertSee('employeemember@miamibeachfl.gov');
        self::assertSame('account.member@gmail.com', $user->fresh()->email);
        self::assertNull($user->fresh()->email_verified_at);
        $this->assertDatabaseCount('city_email_verifications', 0);
    }

    public function test_review_is_authenticated_and_pages_do_not_leak_cache_or_referrers(): void
    {
        $this->get('/account/city-email')->assertRedirect('/login');
        $this->actingAsCanonicalUser($this->member());
        $response = $this->get('/account/city-email');
        $response->assertOk()->assertHeader('Referrer-Policy', 'no-referrer');
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_unlinked_legacy_admin_session_is_not_trapped_in_employee_email_onboarding(): void
    {
        $user = User::factory()->create(['employee_profile_id' => null, 'account_status' => AccountStatus::Active]);
        $user->assignRole(Role::findOrCreate('super_admin', 'web'));
        $this->withoutVite();
        $this->actingAsCanonicalUser($user)->withSession([EnsureCityEmailReview::SESSION_KEY => true]);
        $this->get('/admin')->assertOk();
        self::assertFalse(session()->has(EnsureCityEmailReview::SESSION_KEY));
        $this->get('/account/city-email')->assertForbidden();
    }

    public function test_all_panels_enforce_review_for_normal_and_persistent_requests(): void
    {
        foreach (['admin', 'employee', 'training', 'workgroups'] as $panelId) {
            $panel = Filament::getPanel($panelId);
            self::assertContains(EnsureCityEmailReview::class, $panel->getAuthMiddleware());
            Filament::setCurrentPanel($panel);
            Filament::bootCurrentPanel();
            self::assertContains(EnsureCityEmailReview::class, Livewire::getPersistentMiddleware());
        }
    }

    public function test_acknowledgement_requires_password_and_ownership_and_rejects_other_domains(): void
    {
        $user = $this->member();
        $this->actingAsCanonicalUser($user)->withSession([EnsureCityEmailReview::SESSION_KEY => true]);
        $input = ['email' => 'examplemember@miamibeachfl.gov', 'current_password' => 'wrong', 'ownership_confirmed' => '1'];
        $this->from('/account/city-email')->post('/account/city-email', $input)->assertSessionHasErrors('current_password');
        $input['current_password'] = 'city-email-test-password';
        $input['email'] = 'examplemember@example.test';
        $this->post('/account/city-email', $input)->assertSessionHasErrors('email');
        $input['email'] = 'examplemember@miamibeachfl.gov';
        unset($input['ownership_confirmed']);
        $this->post('/account/city-email', $input)->assertSessionHasErrors('ownership_confirmed');
        self::assertNull(app(CityEmailVerificationService::class)->status($user));
        self::assertTrue(session(EnsureCityEmailReview::SESSION_KEY));
    }

    public function test_delivery_failure_preserves_identity_and_allows_ordinary_operations_after_acknowledgement(): void
    {
        Http::fake();
        config()->set('communications.cloudflare.api_token', '');
        $user = $this->member();
        $original = $user->getRawOriginal();
        $this->withoutVite();
        $this->actingAsCanonicalUser($user)->withSession([EnsureCityEmailReview::SESSION_KEY => true]);
        $this->post('/account/city-email', [
            'email' => 'examplemember@miamibeachfl.gov',
            'current_password' => 'city-email-test-password',
            'ownership_confirmed' => '1',
        ])->assertRedirect('/account/city-email')->assertSessionHas('status');

        $verification = app(CityEmailVerificationService::class)->status($user);
        self::assertSame('failed', $verification->delivery_status);
        self::assertNull($verification->verified_at);
        self::assertNull($user->fresh()->employeeProfile->city_email);
        foreach (['email', 'password', 'employee_id', 'employee_profile_id', 'security_version'] as $key) {
            self::assertSame($original[$key], $user->fresh()->getRawOriginal($key));
        }
        $this->get('/')->assertOk();
        $this->get('/account/city-email')->assertSee('Verification pending')->assertSee('Continue to the Hub');
        $this->post('/account/city-email/continue')->assertRedirect('/');
        Http::assertNothingSent();
    }

    public function test_panel_navigation_and_livewire_do_not_bypass_review_but_password_setup_and_pwa_assets_remain_available(): void
    {
        $user = $this->member();
        $user->assignRole(Role::findOrCreate('super_admin', 'web'));
        $this->withoutVite();
        $this->actingAsCanonicalUser($user)->withSession([EnsureCityEmailReview::SESSION_KEY => true]);
        foreach (['admin', 'employee', 'training', 'workgroups'] as $path) {
            $this->get('/'.$path)->assertRedirect('/account/city-email');
        }
        $this->post('/livewire/update', ['components' => []], ['X-Livewire' => 'true'])->assertRedirect('/account/city-email');
        $this->get('/admin/service-worker.js')->assertOk()->assertHeader('Content-Type', 'application/javascript; charset=utf-8');

        $user->forceFill(['must_change_password' => true])->save();
        $this->get('/employee/set-password')->assertOk();
        $this->post('/logout')->assertRedirect('/login');
    }

    public function test_continuation_preserves_internal_handoff_and_rejects_external_or_encoded_paths(): void
    {
        $user = $this->member();
        app(CityEmailVerificationService::class)->acknowledge($user, 'examplemember@miamibeachfl.gov');
        $this->actingAsCanonicalUser($user);
        $handoff = '/auth/bid/authorize?client_id=bid&state=opaque-test-state';
        $this->withSession([EnsureCityEmailReview::RETURN_KEY => $handoff])->post('/account/city-email/continue')->assertRedirect($handoff);
        foreach (['https://evil.example', '//evil.example', '/%2fevil.example', '/%255cevil.example', '/a/../b', '/account/city-email'] as $path) {
            $this->withSession([EnsureCityEmailReview::RETURN_KEY => $path])->post('/account/city-email/continue')->assertRedirect('/');
        }
    }

    public function test_request_and_resend_rate_limit_password_attempts(): void
    {
        $this->actingAsCanonicalUser($this->member());
        for ($attempt = 0; $attempt < 6; $attempt++) {
            $this->post('/account/city-email/resend', ['current_password' => 'wrong', 'ownership_confirmed' => '1'])
                ->assertSessionHasErrors('current_password');
        }
        $this->post('/account/city-email/resend', ['current_password' => 'wrong', 'ownership_confirmed' => '1'])->assertStatus(429);
    }

    public function test_proof_get_is_read_only_and_post_requires_csrf_and_explicit_confirmation(): void
    {
        $user = $this->member();
        $token = bin2hex(random_bytes(32));
        $service = app(CityEmailVerificationService::class);
        $verification = $service->acknowledge($user, 'examplemember@miamibeachfl.gov');
        $verification->forceFill(['token_hash' => hash('sha256', $token), 'token_expires_at' => now()->addMinutes(30), 'delivery_status' => 'queued'])->save();
        $this->actingAsCanonicalUser($user);
        $path = '/account/city-email/verify/'.$token;
        $previous = $verification->refresh()->getRawOriginal();

        $response = $this->get($path);
        $response->assertOk()->assertSee('Opening this page has not changed your account')
            ->assertHeader('Referrer-Policy', 'no-referrer');
        self::assertStringNotContainsString('report-uri', (string) $response->headers->get('Content-Security-Policy'));
        self::assertSame($previous, $verification->fresh()->getRawOriginal());
        self::assertStringNotContainsString($token, (string) session('_previous.url'));

        $this->app->bind(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
            fn ($app) => new class($app, $app['encrypter']) extends \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken
            {
                protected function runningUnitTests(): bool
                {
                    return false;
                }
            });
        $this->post($path, ['confirm_verification' => '1'])->assertStatus(419);
        self::assertNull($verification->fresh()->verified_at);
        $csrf = session()->token();
        $this->post($path, ['_token' => $csrf])->assertSessionHasErrors('confirm_verification');
        self::assertNull($verification->fresh()->verified_at);
        $this->post($path, ['_token' => $csrf, 'confirm_verification' => '1'])->assertRedirect('/account/city-email');
        self::assertNotNull($verification->fresh()->verified_at);
        self::assertSame('examplemember@miamibeachfl.gov', $user->fresh()->email);
        $this->get($path)->assertStatus(422)->assertDontSee($token);
    }

    public function test_other_accounts_cannot_consume_proof_and_logged_out_owner_can_return_through_login(): void
    {
        $user = $this->member();
        $other = $this->member('-OTHER');
        $token = bin2hex(random_bytes(32));
        $verification = app(CityEmailVerificationService::class)->acknowledge($user, 'examplemember@miamibeachfl.gov');
        $verification->forceFill(['token_hash' => hash('sha256', $token), 'token_expires_at' => now()->addMinutes(30), 'delivery_status' => 'queued'])->save();
        $path = '/account/city-email/verify/'.$token;

        $this->actingAsCanonicalUser($other)->get($path)->assertStatus(422)->assertDontSee('examplemember@miamibeachfl.gov');
        $this->post($path, ['confirm_verification' => '1'])->assertSessionHasErrors('verification');
        self::assertNull($verification->fresh()->verified_at);
        $this->post('/logout');
        $this->get($path)->assertRedirect('/login');
        $this->post('/login', ['employee_id' => $user->employee_id, 'password' => 'city-email-test-password'])->assertRedirect($path);
        $this->get($path)->assertOk()->assertSee('examplemember@miamibeachfl.gov');
    }

    public function test_concurrent_identity_change_during_issue_is_a_validation_error_not_a_server_error(): void
    {
        $user = $this->member();
        $savedEvent = 'eloquent.saved: '.CityEmailVerification::class;
        Event::listen($savedEvent, function () use ($user): void {
            DB::table('employees')->where('id', $user->employee_profile_id)->update(['city_email' => 'concurrentedit@miamibeachfl.gov']);
        });
        $this->actingAsCanonicalUser($user)->withSession([EnsureCityEmailReview::SESSION_KEY => true]);
        $input = ['email' => 'examplemember@miamibeachfl.gov', 'current_password' => 'city-email-test-password', 'ownership_confirmed' => '1'];
        $this->from('/account/city-email')->post('/account/city-email', $input)
            ->assertRedirect('/account/city-email')->assertSessionHasErrors('email');
        self::assertTrue(session(EnsureCityEmailReview::SESSION_KEY));
        Event::forget($savedEvent);
        app(CityEmailVerificationService::class)->acknowledge($user, $input['email']);
        self::assertFalse(app(CityEmailVerificationService::class)->requiresReview($user));
        $this->withSession([EnsureCityEmailReview::SESSION_KEY => false]);
        $transactionEvent = \Illuminate\Database\Events\TransactionBeginning::class;
        Event::listen($transactionEvent, function () use ($user): void {
            DB::table('employees')->where('id', $user->employee_profile_id)->update(['city_email' => 'secondconcurrentedit@miamibeachfl.gov']);
        });
        $this->post('/account/city-email/resend', $input)
            ->assertRedirect('/account/city-email')->assertSessionHasErrors('email');
        Event::forget($transactionEvent);
        self::assertNotSame($input['email'], $user->fresh()->email);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('passwordSetupEmailStates')]
    public function test_fresh_required_password_session_cannot_bypass_setup_and_real_livewire_save_then_requires_email_review(bool $connected, ?string $handoff = null): void
    {
        $user = $this->member();
        $user->forceFill(['must_change_password' => true])->save();
        if ($connected) {
            $user->forceFill(['email' => 'setupmember@miamibeachfl.gov'])->save();
        }
        $this->withoutVite();
        $this->actingAsCanonicalUser($user)->withSession([EnsureCityEmailReview::SESSION_KEY => ! $connected]);
        $this->get('/')->assertRedirect('/employee/set-password');
        $this->get('/daily/stations')->assertRedirect('/employee/set-password');
        if ($handoff !== null) {
            $this->get($handoff)->assertRedirect('/employee/set-password');
        }
        $page = $this->get('/employee/set-password')->assertOk();
        preg_match_all('/wire:snapshot="([^"]+)"/', $page->getContent(), $matches);
        $snapshot = collect($matches[1])->map(fn (string $value): string => html_entity_decode($value, ENT_QUOTES))
            ->first(fn (string $value): bool => str_contains((string) data_get(json_decode($value, true), 'memo.name'), 'set-password'));
        self::assertIsString($snapshot);
        $this->postJson('/livewire/update', ['components' => [[
            'snapshot' => $snapshot,
            'updates' => [
                'data.current_password' => 'city-email-test-password',
                'data.password' => 'new-secure-city-email-password-2026',
                'data.password_confirmation' => 'new-secure-city-email-password-2026',
            ],
            'calls' => [['path' => '', 'method' => 'save', 'params' => []]],
        ]]], ['X-Livewire' => 'true'])->assertOk()
            ->assertJsonPath('components.0.effects.redirect', $handoff ?? 'http://localhost/employee/dashboard');
        self::assertFalse($user->fresh()->must_change_password);
        self::assertTrue(Hash::check('new-secure-city-email-password-2026', $user->fresh()->password));
        $this->withCookie((string) config('session.cookie'), session()->getId());
        if ($handoff !== null) {
            if (! $connected) {
                $this->get($handoff)->assertRedirect('/account/city-email');
                app(CityEmailVerificationService::class)->acknowledge($user->fresh(), 'setupmember@miamibeachfl.gov');
                $this->post('/account/city-email/continue')->assertRedirect($handoff);
            }
            // Preservation never authorizes a callback: its own controller still
            // rejects these incomplete query parameters before issuing a code.
            $this->getJson($handoff)->assertUnprocessable();
        }
        if ($connected) {
            $this->get('/')->assertOk();
        } elseif ($handoff === null) {
            $this->get('/')->assertRedirect('/account/city-email');
        }
        $this->post('/logout')->assertRedirect('/login');
        $this->post('/login', ['employee_id' => $user->employee_id, 'password' => 'city-email-test-password'])->assertSessionHasErrors('employee_id');
        $this->assertGuest('web');
        $this->post('/login', ['employee_id' => $user->employee_id, 'password' => 'new-secure-city-email-password-2026'])->assertRedirect('/');
    }

    public static function passwordSetupEmailStates(): array
    {
        return [
            'missing email' => [false], 'existing connected email' => [true],
            'Bid through password and review' => [false, '/auth/bid/authorize?client_id=bid&state=opaque%2Btest%2Fstate'],
            'Media through password with existing email' => [true, '/auth/media-control/authorize?client_id=media-control&state=opaque%2Btest%2Fstate'],
        ];
    }

    public function test_password_gate_rechecks_verified_livewire_component_even_when_outer_update_is_allowed(): void
    {
        $user = $this->member('-PERSISTENT-PASSWORD');
        $user->forceFill(['email' => 'persistentmember@miamibeachfl.gov'])->save();
        $this->withoutVite();
        $this->actingAsCanonicalUser($user);
        $page = $this->get('/employee/dashboard')->assertOk();
        preg_match_all('/wire:snapshot="([^"]+)"/', $page->getContent(), $matches);
        $snapshot = collect($matches[1])->map(fn (string $value): string => html_entity_decode($value, ENT_QUOTES))
            ->first(fn (string $value): bool => str_contains((string) data_get(json_decode($value, true), 'memo.name'), 'employee-dashboard'));
        self::assertIsString($snapshot);
        $user->forceFill(['must_change_password' => true])->save();
        $this->postJson('/livewire/update', ['components' => [[
            'snapshot' => $snapshot, 'updates' => [],
            'calls' => [['path' => '', 'method' => '$refresh', 'params' => []]],
        ]]], ['X-Livewire' => 'true'])->assertRedirect('/employee/set-password');
        self::assertTrue($user->fresh()->must_change_password);
    }

    public function test_connected_email_does_not_bypass_required_password_replacement_anywhere(): void
    {
        $user = $this->member('-PASSWORD');
        $user->forceFill(['email' => 'passwordmember@miamibeachfl.gov', 'must_change_password' => true])->save();
        $this->withoutVite();
        $this->post('/login', ['employee_id' => $user->employee_id, 'password' => 'city-email-test-password'])->assertRedirect('/');
        self::assertFalse(session(EnsureCityEmailReview::SESSION_KEY));
        $this->withCookie((string) config('session.cookie'), session()->getId());
        foreach (['/', '/daily/stations', '/auth/bid/authorize', '/auth/media-control/authorize', '/account/city-email', '/employee'] as $path) {
            $this->get($path)->assertRedirect('/employee/set-password');
        }
        $this->get('/employee/set-password')->assertOk();
        $this->get('/admin/service-worker.js')->assertOk();
        $this->post('/logout')->assertRedirect('/login');
    }

    public function test_required_password_replacement_cannot_reuse_the_current_password(): void
    {
        $user = $this->member('-SAME-PASSWORD');
        $user->forceFill(['must_change_password' => true])->save();
        $version = $user->security_version;
        $this->withoutVite();
        $this->actingAsCanonicalUser($user);
        $page = $this->get('/employee/set-password')->assertOk();
        preg_match_all('/wire:snapshot="([^"]+)"/', $page->getContent(), $matches);
        $snapshot = collect($matches[1])->map(fn (string $value): string => html_entity_decode($value, ENT_QUOTES))
            ->first(fn (string $value): bool => str_contains((string) data_get(json_decode($value, true), 'memo.name'), 'set-password'));
        $response = $this->postJson('/livewire/update', ['components' => [[
            'snapshot' => $snapshot,
            'updates' => [
                'data.current_password' => 'city-email-test-password',
                'data.password' => 'city-email-test-password',
                'data.password_confirmation' => 'city-email-test-password',
            ],
            'calls' => [['path' => '', 'method' => 'save', 'params' => []]],
        ]]], ['X-Livewire' => 'true'])->assertOk();
        $returned = json_decode($response->json('components.0.snapshot'), true);
        self::assertArrayHasKey('data.password', $returned['memo']['errors']);
        self::assertTrue($user->fresh()->must_change_password);
        self::assertSame($version, $user->fresh()->security_version);
    }

    private function member(string $suffix = ''): User
    {
        $employee = Employee::query()->create([
            'employee_id' => 'CITY-EMAIL-HTTP'.$suffix,
            'name' => 'Example Member',
            'rank' => 'Firefighter',
            'password' => Hash::make('city-email-test-password'),
            'must_change_password' => false,
        ]);

        return User::factory()->create([
            'name' => $employee->name,
            'email' => 'employee-'.$employee->id.'@canonical.mbfdhub.invalid',
            'email_verified_at' => null,
            'employee_id' => $employee->employee_id,
            'employee_profile_id' => $employee->id,
            'account_status' => AccountStatus::Active,
            'password' => Hash::make('city-email-test-password'),
            'must_change_password' => false,
        ]);
    }
}
