<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\AccountStatus;
use App\Http\Controllers\Auth\MemberSelfServiceActivationController;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class AccountAccessExperienceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        config()->set('identity.local_login_enabled', true);
        config()->set('identity.credential_authority', 'local');
        config()->set('identity.self_service_activation.enabled', true);
        config()->set('identity.self_service_activation.email_request_enabled', true);
        Queue::fake();
    }

    public function test_email_is_the_primary_setup_and_recovery_path_and_details_are_explicit(): void
    {
        $attempt = str_repeat('a', 64);

        $this->get('/activate?login_attempt='.$attempt)
            ->assertRedirect('/activate/email?login_attempt='.$attempt);
        $this->get('/activate/email?login_attempt='.$attempt)->assertOk()
            ->assertSee('first-time setup and forgotten passwords')
            ->assertSee('Inbox and Junk in your City email')
            ->assertSee('method=details', false)
            ->assertSee('login_attempt='.$attempt, false)
            ->assertDontSee('name="email"', false)
            ->assertDontSee('name="password"', false);
        $this->get('/activate?method=details&login_attempt='.$attempt)->assertOk()
            ->assertSee('Shift you bid')
            ->assertSee('temporary assignment, overtime, or shift exchange')
            ->assertSee('name="login_attempt"', false)
            ->assertSee('name="method" value="details"', false)
            ->assertSee('Already have a password? Sign in');
    }

    public function test_feature_switches_keep_the_available_path_reachable(): void
    {
        config()->set('identity.self_service_activation.email_request_enabled', false);
        $this->get('/activate')->assertOk()->assertSee('Shift you bid');
        $this->get('/activate/email?login_attempt='.str_repeat('b', 64))
            ->assertRedirect('/activate?login_attempt='.str_repeat('b', 64));

        config()->set('identity.self_service_activation.email_request_enabled', true);
        config()->set('identity.self_service_activation.enabled', false);
        $this->get('/activate?method=details')->assertRedirect('/activate/email');
        $this->get('/activate/email')->assertOk()->assertDontSee('method=details', false);
    }

    public function test_email_request_is_honest_generic_and_retains_only_the_employee_id(): void
    {
        $this->post('/activate/email', [
            'employee_id' => "\u{00A0}UNKNOWN-ACCESS\u{2007}",
            'email' => 'requester@example.com',
            'password' => 'never-reflect-this-secret',
        ])->assertRedirect('/activate/email')
            ->assertSessionHas('status', MemberSelfServiceActivationController::EMAIL_STATUS)
            ->assertSessionHasInput('employee_id', 'UNKNOWN-ACCESS');

        $this->get('/activate/email')->assertOk()
            ->assertSee('Request received')
            ->assertSee('Inbox and Junk')
            ->assertSee('the email states when the link expires')
            ->assertSee('email on file')
            ->assertDontSee('within 30 minutes')
            ->assertDontSee('requester@example.com')
            ->assertDontSee('never-reflect-this-secret');
        Queue::assertNothingPushed();
        Http::assertNothingSent();
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('authentication_sessions', 0);
    }

    public function test_signed_in_email_request_does_not_replace_or_modify_a_session(): void
    {
        $member = $this->actingAsCanonicalFixture();
        $before = $member->fresh()->getRawOriginal();
        $registryId = session('auth.canonical_session_id');

        $this->post('/activate/email', ['employee_id' => 'ANOTHER-EMPLOYEE'])->assertRedirect('/');

        $this->assertAuthenticatedAs($member, 'web');
        self::assertSame($registryId, session('auth.canonical_session_id'));
        self::assertSame($before, $member->fresh()->getRawOriginal());
        Queue::assertNothingPushed();
    }

    public function test_existing_account_and_unknown_id_get_the_same_safe_setup_guidance_without_changes(): void
    {
        $employee = Employee::query()->create([
            'employee_id' => 'EXISTING-NOEMAIL', 'name' => 'Private Existing Member',
            'roster_status' => 'active', 'password' => 'legacy-not-used',
        ]);
        $member = User::factory()->create([
            'employee_profile_id' => $employee->id, 'employee_id' => $employee->employee_id,
            'email' => 'private-existing@canonical.mbfdhub.invalid', 'account_status' => AccountStatus::Active,
            'bootstrap_onboarding_eligible' => false, 'security_version' => 1,
        ])->fresh();
        $before = $member->getRawOriginal();
        $messages = [];

        foreach (['EXISTING-NOEMAIL', 'UNKNOWN-ACCOUNT'] as $identifier) {
            $this->post('/activate?method=details', [
                'employee_id' => $identifier, 'email' => 'typed-address@miamibeachfl.gov',
                'position' => 'Firefighter', 'password' => 'New-Private-Password!5729',
                'password_confirmation' => 'New-Private-Password!5729',
            ])->assertRedirect('/activate?method=details')->assertSessionHasErrors('activation');
            $messages[] = session('errors')->first('activation');
            $this->get('/activate?method=details')->assertOk()
                ->assertSee('If you already have a password, sign in.')
                ->assertSee('Get a private email link')
                ->assertDontSee('Private Existing Member')
                ->assertDontSee('private-existing@canonical.mbfdhub.invalid')
                ->assertDontSee('New-Private-Password!5729');
            $this->assertGuest('web');
        }

        self::assertCount(1, array_unique($messages));
        self::assertSame($before, $member->fresh()->getRawOriginal());
        self::assertSame([], $member->fresh()->getRoleNames()->all());
        self::assertSame([], $member->fresh()->getDirectPermissions()->all());
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('authentication_sessions', 0);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_all_auth_pages_protect_private_urls_from_caching_referrers_and_csp_reports(): void
    {
        foreach (['/login', '/activate/email', '/activate?method=details', '/forgot-password',
            '/reset-password/private-reset-token?employee_id=ACCESS-ID', '/member-onboarding/invite'] as $path) {
            $response = $this->get($path)->assertOk()
                ->assertHeader('Cache-Control', 'no-store, private')
                ->assertHeader('Referrer-Policy', 'no-referrer');

            self::assertStringNotContainsString('report-uri', (string) $response->headers->get('Content-Security-Policy'), $path);
            self::assertStringNotContainsString('report-to', (string) $response->headers->get('Content-Security-Policy'), $path);
        }
        self::assertStringContainsString('report-uri /_csp-report', (string) $this->get('/security-standards')->headers->get('Content-Security-Policy'));
    }

    public function test_recovery_pages_offer_sign_in_and_another_private_link_without_new_account_creation(): void
    {
        foreach (['/forgot-password', '/reset-password/expired-token?employee_id=ACCESS-ID'] as $path) {
            $this->get($path)->assertOk()->assertSee('Sign in')
                ->assertSee('email on file')
                ->assertDontSee('Create account');
        }
        $this->get('/login')->assertOk()->assertSee('Set up or recover your account')
            ->assertSee('already have a password');
    }
}
