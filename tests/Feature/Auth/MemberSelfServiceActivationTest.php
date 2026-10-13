<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\AccountStatus;
use App\Exceptions\MemberBootstrapStateChanged;
use App\Jobs\IssueMemberOnboardingInvitation;
use App\Models\Employee;
use App\Models\MemberActivationVerifier;
use App\Models\MemberOnboardingInvitation;
use App\Models\MemberOnboardingRosterBinding;
use App\Models\SecurityActionEvent;
use App\Models\User;
use App\Services\Identity\AccountSecurityService;
use App\Services\Identity\CityEmailVerificationService;
use App\Services\Identity\EstablishedAccountIntegritySnapshot;
use App\Services\Identity\MemberActivationVerifierDigest;
use App\Services\Identity\MemberSelfServiceActivation;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class MemberSelfServiceActivationTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'New-Private-Password!7824';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('identity.local_login_enabled', true);
        config()->set('identity.self_service_activation.enabled', true);
        config()->set('identity.self_service_activation.email_request_enabled', true);
    }

    public function test_eligible_member_activates_the_existing_account_and_reaches_the_hub(): void
    {
        $member = $this->pending('SELF-OK');
        $other = $this->pending('SELF-OTHER');
        $userCount = User::query()->count();

        $this->get('/login')->assertOk()->assertSee('Activate Your Account');
        $this->get('/activate')->assertOk()->assertSee('Date of birth')->assertHeader('Cache-Control', 'no-store, private');
        $this->post('/activate', $this->form('SELF-OK'))->assertRedirect('/');
        $this->withCookie((string) config('session.cookie'), $this->app['session.store']->getId());

        $member->refresh();
        self::assertSame($userCount, User::query()->count());
        self::assertSame(AccountStatus::Active, $member->account_status);
        self::assertTrue(Hash::check(self::PASSWORD, $member->password));
        self::assertFalse($member->must_change_password);
        self::assertFalse($member->bootstrap_onboarding_eligible);
        self::assertNotNull($member->bootstrap_onboarding_completed_at);
        self::assertNull($member->email_verified_at, 'No mailbox was proven, so the email must stay unverified.');
        self::assertSame(2, $member->security_version);
        self::assertSame(AccountStatus::PendingActivation, $other->fresh()->account_status);
        self::assertDatabaseHas('authentication_sessions', ['user_id' => $member->id, 'revoked_at' => null]);
        self::assertSame('complete_member_self_service_activation', SecurityActionEvent::query()->latest('id')->value('action'));
        $verifier = MemberActivationVerifier::query()->where('employee_profile_id', $member->employee_profile_id)->sole();
        self::assertNotNull($verifier->consumed_at);
        self::assertNull($verifier->dob_digest);
        self::assertNull($verifier->phone_digest);

        $this->get('/account')->assertOk();
        $this->assertAuthenticatedAs($member, 'web');
        $this->post('/logout')->assertRedirect('/login');
        $this->post('/login', ['employee_id' => 'SELF-OK', 'password' => self::PASSWORD])->assertRedirect('/');
    }

    public function test_phone_formatting_is_normalized_but_a_different_number_is_refused(): void
    {
        $member = $this->pending('SELF-PHONE');

        $this->post('/activate', $this->form('SELF-PHONE', ['phone' => '305-555-0199']))
            ->assertSessionHasErrors('activation');
        self::assertSame(AccountStatus::PendingActivation, $member->fresh()->account_status);

        $this->post('/activate', $this->form('SELF-PHONE', ['phone' => '+1 (305) 555-0142']))->assertRedirect('/');
        self::assertSame(AccountStatus::Active, $member->fresh()->account_status);
        self::assertNull($member->fresh()->phone, 'The submitted phone must never be written to the profile.');
    }

    public function test_every_refusal_returns_the_same_response(): void
    {
        $this->pending('SELF-GENERIC');
        $active = $this->pending('SELF-ACTIVE');
        $active->forceFill(['account_status' => AccountStatus::Active, 'bootstrap_onboarding_eligible' => false])->save();
        $noRecords = $this->pending('SELF-NORECORDS', verifier: false);

        $messages = [];
        foreach ([
            $this->form('SELF-GENERIC', ['dob_day' => '15']),
            $this->form('SELF-GENERIC', ['phone' => '3055550100']),
            $this->form('SELF-GENERIC', ['phone' => '12']),
            $this->form('SELF-GENERIC', ['dob_month' => '2', 'dob_day' => '31']),
            $this->form('SELF-ACTIVE'),
            $this->form('SELF-NORECORDS'),
            $this->form('SELF-DOES-NOT-EXIST'),
        ] as $attempt) {
            $response = $this->from('/activate')->post('/activate', $attempt);
            $response->assertRedirect('/activate')->assertSessionHasErrors('activation');
            $messages[] = session('errors')->first('activation');
            $this->assertGuest('web');
            $this->flushSession();
        }

        self::assertCount(1, array_unique($messages));
        self::assertSame(AccountStatus::Active, $active->fresh()->account_status);
        self::assertSame(AccountStatus::PendingActivation, $noRecords->fresh()->account_status);
    }

    public function test_an_activated_account_cannot_be_claimed_or_reset_again(): void
    {
        $member = $this->pending('SELF-ONCE');
        $this->post('/activate', $this->form('SELF-ONCE'))->assertRedirect('/');
        $this->logoutCanonicalSession();
        $hash = $member->fresh()->getRawOriginal('password');

        $this->from('/activate')->post('/activate', $this->form('SELF-ONCE', [
            'password' => 'Attacker-Password!9911', 'password_confirmation' => 'Attacker-Password!9911',
        ]))->assertRedirect('/activate')->assertSessionHasErrors('activation');

        self::assertSame($hash, $member->fresh()->getRawOriginal('password'));
        self::assertSame(2, $member->fresh()->security_version);
    }

    public function test_established_accounts_are_untouched_by_activation_attempts(): void
    {
        $established = $this->pending('SELF-ESTABLISHED', verifier: false);
        $established->forceFill([
            'account_status' => AccountStatus::Active,
            'bootstrap_onboarding_eligible' => false,
            'password' => Hash::make('Established-Password!1'),
        ])->save();
        // Even a stray proof row must never let an active account be claimed.
        $this->verifier($established->employeeProfile);
        $this->pending('SELF-NEW');
        $snapshots = app(EstablishedAccountIntegritySnapshot::class);
        $before = $snapshots->capture();

        $this->post('/activate', $this->form('SELF-ESTABLISHED'))->assertSessionHasErrors('activation');
        $this->post('/activate', $this->form('SELF-NEW'))->assertRedirect('/');

        $comparison = $snapshots->compare($before, $snapshots->capture());
        self::assertSame(0, $comparison['unexpected_changes']);
        self::assertSame(0, $comparison['password_hashes_changed']);
        self::assertSame(1, $comparison['newly_established_accounts']);
    }

    public function test_disabled_departed_privileged_and_unlinked_accounts_are_refused(): void
    {
        $disabled = $this->pending('SELF-DISABLED');
        $disabled->forceFill(['account_status' => AccountStatus::Disabled, 'bootstrap_onboarding_eligible' => false])->save();
        $departed = $this->pending('SELF-DEPARTED');
        $departed->employeeProfile->forceFill(['roster_status' => 'departed'])->save();
        $admin = $this->pending('SELF-ADMIN');
        $admin->assignRole(Role::findOrCreate('admin', 'web'));
        $permitted = $this->pending('SELF-PERMITTED');
        $permitted->givePermissionTo(Permission::findOrCreate('admin.access', 'web'));
        $mismatched = $this->pending('SELF-MISMATCH');
        User::query()->whereKey($mismatched->id)->update(['employee_id' => 'SELF-OTHER-ID']);

        foreach (['SELF-DISABLED', 'SELF-DEPARTED', 'SELF-ADMIN', 'SELF-PERMITTED', 'SELF-MISMATCH'] as $employeeId) {
            $this->post('/activate', $this->form($employeeId))->assertSessionHasErrors('activation');
            $this->assertGuest('web');
            $this->flushSession();
        }
        self::assertSame(AccountStatus::Disabled, $disabled->fresh()->account_status);
        foreach ([$departed, $admin, $permitted, $mismatched] as $user) {
            self::assertSame(AccountStatus::PendingActivation, $user->fresh()->account_status);
        }
    }

    public function test_repeated_wrong_answers_lock_the_proof_until_it_is_reapproved(): void
    {
        config()->set('identity.self_service_activation.max_failed_attempts', 3);
        $member = $this->pending('SELF-LOCK');
        $activation = app(MemberSelfServiceActivation::class);
        $wrong = CarbonImmutable::create(1985, 6, 15);
        for ($attempt = 0; $attempt < 3; $attempt++) {
            self::assertNull($activation->claim('SELF-LOCK', $wrong, '3055550142', Hash::make(self::PASSWORD), CarbonImmutable::now()));
        }

        $verifier = MemberActivationVerifier::query()->sole();
        self::assertSame(3, $verifier->failed_attempts);
        self::assertNotNull($verifier->locked_at);
        self::assertNull($activation->claim('SELF-LOCK', CarbonImmutable::create(1985, 6, 14), '3055550142', Hash::make(self::PASSWORD), CarbonImmutable::now()));
        self::assertSame(AccountStatus::PendingActivation, $member->fresh()->account_status);
        self::assertSame(3, SecurityActionEvent::query()->where('action', 'member_self_service_activation_denied')->count());
        self::assertSame('verification_locked', SecurityActionEvent::query()->latest('id')->value('reason'));
    }

    public function test_requests_are_rate_limited_per_employee_id(): void
    {
        $member = $this->pending('SELF-THROTTLE');
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post('/activate', $this->form('SELF-THROTTLE', ['dob_year' => '1990']))->assertSessionHasErrors('activation');
        }

        $this->post('/activate', $this->form('SELF-THROTTLE'))->assertSessionHasErrors('activation');
        self::assertStringContainsString('Too many attempts', session('errors')->first('activation'));
        self::assertSame(AccountStatus::PendingActivation, $member->fresh()->account_status);
        self::assertSame(5, MemberActivationVerifier::query()->sole()->failed_attempts);
    }

    public function test_only_one_of_two_competing_claims_can_win(): void
    {
        $member = $this->pending('SELF-RACE');
        $security = app(AccountSecurityService::class);
        $security->completeSelfServiceActivation($member->id, $member->employee_profile_id, 1, Hash::make(self::PASSWORD), CarbonImmutable::now());

        $this->expectException(MemberBootstrapStateChanged::class);
        $security->completeSelfServiceActivation($member->id, $member->employee_profile_id, 1, Hash::make('Second-Password!1'), CarbonImmutable::now());
    }

    public function test_self_service_activation_retires_an_outstanding_email_invitation(): void
    {
        $member = $this->pending('SELF-VS-EMAIL');
        $token = bin2hex(random_bytes(32));
        MemberOnboardingInvitation::query()->create([
            'user_id' => $member->id,
            'employee_profile_id' => $member->employee_profile_id,
            'email' => $member->employeeProfile->city_email,
            'security_version' => 1,
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addMinutes(30),
            'delivery_status' => 'queued',
        ]);

        $this->post('/activate', $this->form('SELF-VS-EMAIL'))->assertRedirect('/');
        $this->logoutCanonicalSession();
        $hash = $member->fresh()->getRawOriginal('password');

        self::assertNull(MemberOnboardingInvitation::query()->sole()->token_hash);
        $this->post('/member-onboarding/invite', ['token' => $token])->assertRedirect('/login');
        self::assertSame($hash, $member->fresh()->getRawOriginal('password'));
    }

    public function test_email_activation_closes_the_self_service_path(): void
    {
        $member = $this->pending('EMAIL-FIRST');
        $member->forceFill([
            'account_status' => AccountStatus::Active,
            'bootstrap_onboarding_eligible' => false,
            'bootstrap_onboarding_completed_at' => now(),
            'password' => Hash::make('Email-Chosen-Password!1'),
            'security_version' => 2,
        ])->save();
        $hash = $member->fresh()->getRawOriginal('password');

        $this->post('/activate', $this->form('EMAIL-FIRST'))->assertSessionHasErrors('activation');

        self::assertSame($hash, $member->fresh()->getRawOriginal('password'));
        self::assertNull(MemberActivationVerifier::query()->sole()->consumed_at);
    }

    public function test_activated_member_without_a_city_email_is_not_forced_into_email_review(): void
    {
        $member = $this->pending('SELF-NOEMAIL', cityEmail: false);
        self::assertTrue(app(CityEmailVerificationService::class)->requiresReview($member));

        $this->post('/activate', $this->form('SELF-NOEMAIL'))->assertRedirect('/');
        $this->withCookie((string) config('session.cookie'), $this->app['session.store']->getId());

        self::assertFalse(app(CityEmailVerificationService::class)->requiresReview($member->fresh()));
        $this->get('/account')->assertOk();
    }

    public function test_self_service_activation_does_not_grant_email_recovery(): void
    {
        $member = $this->pending('SELF-RECOVERY');
        $this->post('/activate', $this->form('SELF-RECOVERY'))->assertRedirect('/');

        self::assertNull(app(CityEmailVerificationService::class)->recoveryAddress($member->fresh()));
    }

    public function test_weak_or_mismatched_passwords_are_rejected_before_any_claim(): void
    {
        $member = $this->pending('SELF-WEAK');

        $this->post('/activate', $this->form('SELF-WEAK', ['password' => 'short', 'password_confirmation' => 'short']))
            ->assertSessionHasErrors('password');
        $this->post('/activate', $this->form('SELF-WEAK', ['password_confirmation' => 'Different-Password!1']))
            ->assertSessionHasErrors('password');

        self::assertSame(AccountStatus::PendingActivation, $member->fresh()->account_status);
        self::assertSame(0, MemberActivationVerifier::query()->sole()->failed_attempts);
    }

    public function test_disabling_the_feature_hides_it_without_affecting_sign_in(): void
    {
        config()->set('identity.self_service_activation.enabled', false);
        config()->set('identity.self_service_activation.email_request_enabled', false);
        $member = $this->pending('SELF-OFF');

        $this->get('/login')->assertOk()->assertDontSee('Activate Your Account')->assertSee('personal setup link');
        $this->get('/activate')->assertRedirect('/login');
        $this->get('/activate/email')->assertRedirect('/login');
        $this->post('/activate', $this->form('SELF-OFF'))->assertNotFound();
        $this->post('/activate/email', ['employee_id' => 'SELF-OFF'])->assertNotFound();
        self::assertSame(AccountStatus::PendingActivation, $member->fresh()->account_status);
    }

    public function test_email_only_mode_sends_members_to_the_email_request_page(): void
    {
        config()->set('identity.self_service_activation.enabled', false);

        $this->get('/login')->assertOk()->assertSee('Activate Your Account');
        $this->get('/activate')->assertRedirect('/activate/email');
        $this->get('/activate/email')->assertOk()->assertSee('Email my setup link')->assertDontSee('date of birth and phone');
        $this->post('/activate', $this->form('ANY'))->assertNotFound();
    }

    public function test_member_can_request_the_existing_invitation_at_the_approved_address_only(): void
    {
        config()->set('queue.default', 'database');
        Queue::fake();
        $member = $this->pending('EMAIL-REQ');

        $this->post('/activate/email', ['employee_id' => 'EMAIL-REQ', 'email' => 'attacker@example.com'])
            ->assertRedirect('/activate/email')->assertSessionHas('status');

        Queue::assertPushed(IssueMemberOnboardingInvitation::class, 1);
        Queue::assertPushed(fn (IssueMemberOnboardingInvitation $job): bool => $job->userId === $member->id
            && $job->initiatorId === null
            && hash_equals($job->expectedBindingHash, IssueMemberOnboardingInvitation::bindingHash(
                $member->id, 'EMAIL-REQ', 'email-req@miamibeachfl.gov', 1,
            )));
        self::assertDatabaseHas('security_action_events', [
            'target_user_id' => $member->id, 'action' => 'member_onboarding_invitation_self_requested',
        ]);
    }

    public function test_email_requests_answer_identically_and_send_nothing_for_ineligible_accounts(): void
    {
        config()->set('queue.default', 'database');
        Queue::fake();
        $this->pending('EMAIL-NOBINDING', cityEmail: false);
        $active = $this->pending('EMAIL-ACTIVE');
        $active->forceFill(['account_status' => AccountStatus::Active, 'bootstrap_onboarding_eligible' => false])->save();
        $eligible = $this->pending('EMAIL-ELIGIBLE');

        $statuses = [];
        foreach (['EMAIL-NOBINDING', 'EMAIL-ACTIVE', 'EMAIL-UNKNOWN', 'EMAIL-ELIGIBLE'] as $employeeId) {
            $this->post('/activate/email', ['employee_id' => $employeeId])->assertRedirect('/activate/email');
            $statuses[] = session('status');
        }

        self::assertCount(1, array_unique($statuses));
        Queue::assertPushed(IssueMemberOnboardingInvitation::class, 1);
        Queue::assertPushed(fn (IssueMemberOnboardingInvitation $job): bool => $job->userId === $eligible->id);
    }

    public function test_email_requests_are_rate_limited_per_employee_id(): void
    {
        config()->set('queue.default', 'database');
        Queue::fake();
        $this->pending('EMAIL-LIMIT');
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->post('/activate/email', ['employee_id' => 'EMAIL-LIMIT'])->assertSessionHas('status');
        }

        $this->post('/activate/email', ['employee_id' => 'EMAIL-LIMIT'])->assertSessionHasErrors('employee_id');
    }

    public function test_date_of_birth_and_phone_are_never_flashed_into_the_session(): void
    {
        $this->pending('SELF-FLASH');

        $this->post('/activate', $this->form('SELF-FLASH', ['password' => 'short', 'password_confirmation' => 'short']))
            ->assertSessionHasErrors('password');
        self::assertSame(['employee_id' => 'SELF-FLASH'], session()->getOldInput());

        $this->post('/activate', $this->form('SELF-FLASH', ['dob_year' => '1990']))->assertSessionHasErrors('activation');
        self::assertSame(['employee_id' => 'SELF-FLASH'], session()->getOldInput());
    }

    public function test_claiming_follows_the_local_login_switch(): void
    {
        config()->set('identity.local_login_enabled', false);
        $member = $this->pending('SELF-NOLOCAL');

        $this->post('/activate', $this->form('SELF-NOLOCAL'))->assertNotFound();
        self::assertSame(AccountStatus::PendingActivation, $member->fresh()->account_status);
    }

    public function test_member_requested_email_is_capped_per_member_and_refused_for_privileged_accounts(): void
    {
        config()->set('queue.default', 'database');
        Queue::fake();
        $member = $this->pending('EMAIL-CAP');
        $admin = $this->pending('EMAIL-ADMIN');
        $admin->assignRole(Role::findOrCreate('admin', 'web'));
        $activation = app(MemberSelfServiceActivation::class);

        for ($attempt = 0; $attempt < 4; $attempt++) {
            $activation->requestEmailInvitation('EMAIL-CAP');
        }
        $activation->requestEmailInvitation('EMAIL-ADMIN');

        // The job is unique per member, and the daily cap bounds repeats after it runs.
        Queue::assertPushed(IssueMemberOnboardingInvitation::class, 1);
        Queue::assertPushed(fn (IssueMemberOnboardingInvitation $job): bool => $job->userId === $member->id);
        self::assertSame(2, SecurityActionEvent::query()->where('action', 'member_onboarding_invitation_self_requested')->count());
    }

    public function test_activation_continues_a_pending_connected_application_sign_in(): void
    {
        $member = $this->pending('SELF-FEDERATED');
        $location = $this->federationLogin('/auth/bid/authorize?client_id=bid&redirect_uri=https%3A%2F%2Fstaging.bid.mbfdhub.com%2Fapi%2Fauth%2Fcallback&state=uQxS6x3Mki8aUHsi_vB1m2zY9kt_P4DSxMZ0nNfw2-I');
        $attempt = substr($location, strlen('/login?login_attempt='));

        $this->get($location)->assertOk()->assertSee('/activate?login_attempt='.$attempt, false);
        $response = $this->post('/activate?login_attempt='.$attempt, $this->form('SELF-FEDERATED'));

        $response->assertRedirect();
        self::assertStringStartsWith('/auth/bid/authorize', (string) $response->headers->get('Location'));
        self::assertSame(AccountStatus::Active, $member->fresh()->account_status);
    }

    public function test_signed_in_users_are_sent_home(): void
    {
        $this->actingAsCanonicalFixture();

        $this->get('/activate')->assertRedirect('/');
        $this->get('/activate/email')->assertRedirect('/');
    }

    /**
     * @param  array<string,string>  $overrides
     * @return array<string,string>
     */
    private function form(string $employeeId, array $overrides = []): array
    {
        return $overrides + [
            'employee_id' => $employeeId,
            'dob_month' => '6',
            'dob_day' => '14',
            'dob_year' => '1985',
            'phone' => '(305) 555-0142',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
        ];
    }

    private function pending(string $employeeId, bool $verifier = true, bool $cityEmail = true): User
    {
        $email = strtolower($employeeId).'@miamibeachfl.gov';
        $employee = Employee::query()->create([
            'employee_id' => $employeeId,
            'name' => 'Activation Member '.$employeeId,
            'roster_status' => 'active',
            'city_email' => $cityEmail ? $email : null,
            'password' => Hash::make('legacy-password'),
        ]);
        if ($cityEmail) {
            MemberOnboardingRosterBinding::query()->create([
                'employee_profile_id' => $employee->id,
                'employee_id' => $employeeId,
                'city_email' => $email,
                'source_sha256' => str_repeat('a', 64),
                'approved_at' => now(),
            ]);
        }
        if ($verifier) {
            $this->verifier($employee);
        }

        return User::factory()->create([
            'employee_profile_id' => $employee->id,
            'employee_id' => $employeeId,
            'email' => 'pending-'.strtolower($employeeId).'@canonical.mbfdhub.invalid',
            'account_status' => AccountStatus::PendingActivation,
            'bootstrap_onboarding_eligible' => true,
            'bootstrap_onboarding_eligible_at' => now(),
            'security_version' => 1,
        ])->fresh('employeeProfile');
    }

    private function verifier(Employee $employee): MemberActivationVerifier
    {
        $digests = app(MemberActivationVerifierDigest::class);

        return MemberActivationVerifier::query()->create([
            'employee_profile_id' => $employee->id,
            'employee_id' => $employee->employee_id,
            'dob_digest' => $digests->dateOfBirth($employee->id, CarbonImmutable::create(1985, 6, 14)),
            'phone_digest' => $digests->phone($employee->id, '3055550142'),
            'source_sha256' => str_repeat('b', 64),
            'approved_at' => now(),
        ]);
    }
}
