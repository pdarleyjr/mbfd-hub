<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\AccountStatus;
use App\Exceptions\MemberBootstrapStateChanged;
use App\Jobs\IssueMemberOnboardingInvitation;
use App\Models\CloudflareUsageBudget;
use App\Models\Employee;
use App\Models\EmployeeBidAssignment;
use App\Models\MemberActivationAttempt;
use App\Models\MemberOnboardingInvitation;
use App\Models\MemberOnboardingRosterBinding;
use App\Models\OutboundEmail;
use App\Models\SecurityActionEvent;
use App\Models\User;
use App\Services\Identity\AccountSecurityService;
use App\Services\Identity\CityEmailVerificationService;
use App\Services\Identity\EstablishedAccountIntegritySnapshot;
use App\Services\Identity\MemberAccessEmail;
use App\Services\Identity\MemberSelfServiceActivation;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
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

        $this->withoutVite();
        config()->set('identity.local_login_enabled', true);
        config()->set('identity.self_service_activation.enabled', true);
        config()->set('identity.self_service_activation.email_request_enabled', true);
        config()->set('communications.cloudflare.account_id', str_repeat('a', 32));
        config()->set('communications.cloudflare.api_token', 'member-activation-test-token');
        $now = CarbonImmutable::now();
        CloudflareUsageBudget::query()->create([
            'provider_account_id' => str_repeat('a', 32),
            'cycle_start' => $now->startOfMonth(),
            'cycle_end' => $now->addMonth()->startOfMonth(),
            'provider_chargeable_used' => 0,
            'provider_daily_quota' => 100,
            'provider_daily_used' => 0,
            'hub_safe_ceiling' => 100,
            'worker_request_threshold' => 9_000_000,
            'worker_cpu_ms_threshold' => 27_000_000,
            'reconciled_at' => $now,
            'provider_daily_reconciled_at' => $now,
            'worker_requests_used' => 0,
            'worker_cpu_ms_used' => 0,
        ]);
        Http::fake(fn (Request $request) => Http::response([
            'success' => true,
            'result' => [
                'message_id' => 'member-activation-test-message',
                'delivered' => $request->data()['to'],
                'queued' => [],
                'permanent_bounces' => [],
                'suppressed_recipients' => [],
            ],
        ]));
    }

    public function test_eligible_member_activates_the_existing_account_and_reaches_the_hub(): void
    {
        $member = $this->pending('SELF-OK');
        $other = $this->pending('SELF-OTHER', shift: 'B Shift', unit: 'Rescue 2');
        $userCount = User::query()->count();

        $this->get('/login')->assertOk()->assertSee('Set up or recover your account');
        $this->get('/activate')->assertRedirect('/activate/email');
        $this->get('/activate?method=details')->assertOk()
            ->assertSee('Shift you bid')->assertSee('A Shift')->assertSee('B Shift')
            ->assertSee('Engine 1')->assertSee('Rescue 2')
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->post('/activate', $this->form('SELF-OK'))->assertRedirect('/');
        $this->withCookie((string) config('session.cookie'), $this->app['session.store']->getId());

        $member->refresh();
        self::assertSame($userCount, User::query()->count());
        self::assertSame(AccountStatus::Active, $member->account_status);
        self::assertTrue(Hash::check(self::PASSWORD, $member->password));
        self::assertFalse($member->must_change_password);
        self::assertFalse($member->bootstrap_onboarding_eligible);
        self::assertNotNull($member->bootstrap_onboarding_completed_at);
        self::assertNull($member->email_verified_at, 'Typing an address proves nothing about the mailbox.');
        self::assertSame(2, $member->security_version);
        self::assertSame(AccountStatus::PendingActivation, $other->fresh()->account_status);
        self::assertDatabaseHas('authentication_sessions', ['user_id' => $member->id, 'revoked_at' => null]);
        self::assertDatabaseHas('security_action_events', ['target_user_id' => $member->id, 'action' => 'complete_member_self_service_activation']);
        self::assertNotNull(MemberActivationAttempt::query()->where('employee_profile_id', $member->employee_profile_id)->sole()->activated_at);

        $this->get('/account')->assertOk();
        $this->assertAuthenticatedAs($member, 'web');
        $this->post('/logout')->assertRedirect('/login');
        $this->post('/login', ['employee_id' => 'SELF-OK', 'password' => self::PASSWORD])->assertRedirect('/');
    }

    public function test_the_member_is_told_at_their_city_address_that_the_account_was_activated(): void
    {
        $member = $this->pending('SELF-NOTICE');

        $this->post('/activate', $this->form('SELF-NOTICE', ['email' => '  SELF-NOTICE@MiamiBeachFL.gov ']))->assertRedirect('/');

        $notice = OutboundEmail::query()->where('source_type', 'member_self_service_activation')->sole();
        self::assertSame(['self-notice@miamibeachfl.gov'], $notice->to_recipients);
        self::assertSame((string) $member->id, $notice->source_id);
    }

    public function test_a_failed_notice_never_undoes_or_blocks_the_activation(): void
    {
        Http::fake(fn () => throw new \RuntimeException('Mail provider unavailable'));
        $member = $this->pending('SELF-NOTICE-DOWN');

        $this->post('/activate', $this->form('SELF-NOTICE-DOWN'))->assertRedirect('/');

        self::assertSame(AccountStatus::Active, $member->fresh()->account_status);
    }

    public function test_every_refusal_returns_the_same_response(): void
    {
        $this->pending('SELF-GENERIC');
        $active = $this->pending('SELF-ACTIVE');
        $active->forceFill(['account_status' => AccountStatus::Active, 'bootstrap_onboarding_eligible' => false])->save();
        $noBid = $this->pending('SELF-NOBID', bid: false);
        $noEmail = $this->pending('SELF-NOEMAIL', cityEmail: false);

        $messages = [];
        foreach ([
            $this->form('SELF-GENERIC', ['email' => 'someone.else@miamibeachfl.gov']),
            $this->form('SELF-GENERIC', ['shift' => 'B Shift']),
            $this->form('SELF-GENERIC', ['unit' => 'Rescue 2']),
            $this->form('SELF-GENERIC', ['unit' => 'Not A Real Unit']),
            $this->form('SELF-ACTIVE'),
            $this->form('SELF-NOBID'),
            $this->form('SELF-NOEMAIL'),
            $this->form('SELF-DOES-NOT-EXIST'),
        ] as $attempt) {
            $response = $this->from('/activate')->post('/activate', $attempt);
            $response->assertRedirect('/activate?method=details')->assertSessionHasErrors('activation');
            $messages[] = session('errors')->first('activation');
            $this->assertGuest('web');
            $this->flushSession();
        }

        self::assertCount(1, array_unique($messages));
        self::assertSame(AccountStatus::Active, $active->fresh()->account_status);
        self::assertSame(AccountStatus::PendingActivation, $noBid->fresh()->account_status);
        self::assertSame(AccountStatus::PendingActivation, $noEmail->fresh()->account_status);
    }

    public function test_only_the_latest_published_bid_results_are_accepted(): void
    {
        $member = $this->pending('SELF-LATEST', shift: 'C Shift', unit: 'Engine 4', bidYear: 2025);
        $this->bid($member->employeeProfile, 'A Shift', 'Engine 1', 2026);

        $this->get('/activate?method=details')->assertOk()->assertSee('Engine 1')->assertDontSee('Engine 4');
        $this->post('/activate', $this->form('SELF-LATEST', ['shift' => 'C Shift', 'unit' => 'Engine 4']))
            ->assertSessionHasErrors('activation');
        self::assertSame(AccountStatus::PendingActivation, $member->fresh()->account_status);

        $this->post('/activate', $this->form('SELF-LATEST'))->assertRedirect('/');
        self::assertSame(AccountStatus::Active, $member->fresh()->account_status);
    }

    public function test_the_form_tells_members_to_use_the_new_bid_not_the_previous_one(): void
    {
        $member = $this->pending('SELF-NOTE', shift: 'C Shift', unit: 'Engine 4', bidYear: 2025);
        $this->bid($member->employeeProfile, 'A Shift', 'Engine 1', 2026);

        $this->get('/activate?method=details')->assertOk()
            ->assertSee('Use your new 2026–27 bid.')
            ->assertSee('not the assignment you held under the previous bid', false)
            ->assertSee('Shift you bid (2026–27 bid)');
    }

    public function test_a_member_whose_position_does_not_bid_activates_by_selecting_that_position(): void
    {
        $this->pending('SELF-BIDDER');
        $analyst = $this->pending('SELF-ANALYST', bid: false, rank: 'Plans Analyst #2');
        $this->pending('SELF-MECHANIC', bid: false, rank: 'Mechanic #1');

        $this->get('/activate?method=details')->assertOk()
            ->assertSee('My position does not bid')
            ->assertSee('Plans Analyst #2')->assertSee('Mechanic #1')
            ->assertDontSee('Firefighter');
        $this->post('/activate', $this->positionForm('SELF-ANALYST', 'Mechanic #1'))->assertSessionHasErrors('activation');
        self::assertSame(AccountStatus::PendingActivation, $analyst->fresh()->account_status);

        // Stray shift and unit choices are ignored once a position is selected.
        $this->post('/activate', $this->positionForm('SELF-ANALYST', 'Plans Analyst #2') + ['shift' => 'A Shift', 'unit' => 'Engine 1'])
            ->assertRedirect('/');
        self::assertSame(AccountStatus::Active, $analyst->fresh()->account_status);
    }

    public function test_bidders_and_non_bidders_cannot_use_each_others_answers(): void
    {
        $bidder = $this->pending('SELF-CROSS-BID');
        $staff = $this->pending('SELF-CROSS-STAFF', bid: false, rank: 'Clerk Typist');

        // A bidder may not fall back to the smaller position list.
        $this->post('/activate', $this->positionForm('SELF-CROSS-BID', 'Firefighter'))->assertSessionHasErrors('activation');
        // A non-bidder has no shift and unit to match.
        $this->post('/activate', $this->form('SELF-CROSS-STAFF'))->assertSessionHasErrors('activation');
        // Nothing selected at all is a form error, not an attempt.
        $this->post('/activate', ['shift' => '', 'unit' => ''] + $this->form('SELF-CROSS-STAFF'))->assertSessionHasErrors(['shift', 'unit']);

        self::assertSame(AccountStatus::PendingActivation, $bidder->fresh()->account_status);
        self::assertSame(AccountStatus::PendingActivation, $staff->fresh()->account_status);
        self::assertSame(1, MemberActivationAttempt::query()->where('employee_profile_id', $bidder->employee_profile_id)->sole()->failed_attempts);
    }

    public function test_a_superseded_bid_result_is_not_accepted(): void
    {
        $member = $this->pending('SELF-CORRECTED', shift: 'C Shift', unit: 'Engine 4');
        EmployeeBidAssignment::query()->where('employee_profile_id', $member->employee_profile_id)->first()->update(['superseded_at' => now()]);
        $this->bid($member->employeeProfile, 'A Shift', 'Engine 1', 2026);

        $this->post('/activate', $this->form('SELF-CORRECTED', ['shift' => 'C Shift', 'unit' => 'Engine 4']))
            ->assertSessionHasErrors('activation');
        $this->post('/activate', $this->form('SELF-CORRECTED'))->assertRedirect('/');
    }

    public function test_an_email_without_roster_approval_cannot_be_used(): void
    {
        $member = $this->pending('SELF-UNAPPROVED');
        MemberOnboardingRosterBinding::query()->where('employee_profile_id', $member->employee_profile_id)->delete();

        $this->post('/activate', $this->form('SELF-UNAPPROVED'))->assertSessionHasErrors('activation');
        self::assertSame(AccountStatus::PendingActivation, $member->fresh()->account_status);
    }

    public function test_an_activated_account_cannot_be_claimed_or_reset_again(): void
    {
        $member = $this->pending('SELF-ONCE');
        $this->post('/activate', $this->form('SELF-ONCE'))->assertRedirect('/');
        $this->logoutCanonicalSession();
        $hash = $member->fresh()->getRawOriginal('password');

        $this->from('/activate')->post('/activate', $this->form('SELF-ONCE', [
            'password' => 'Attacker-Password!9911', 'password_confirmation' => 'Attacker-Password!9911',
        ]))->assertRedirect('/activate?method=details')->assertSessionHasErrors('activation');

        self::assertSame($hash, $member->fresh()->getRawOriginal('password'));
        self::assertSame(2, $member->fresh()->security_version);
    }

    public function test_established_accounts_are_untouched_by_activation_attempts(): void
    {
        $established = $this->pending('SELF-ESTABLISHED');
        $established->forceFill([
            'account_status' => AccountStatus::Active,
            'bootstrap_onboarding_eligible' => false,
            'password' => Hash::make('Established-Password!1'),
        ])->save();
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

    public function test_five_wrong_answers_lock_the_member_to_email_activation(): void
    {
        $member = $this->pending('SELF-LOCK');
        $activation = app(MemberSelfServiceActivation::class);
        $email = 'self-lock@miamibeachfl.gov';
        for ($attempt = 0; $attempt < 5; $attempt++) {
            self::assertNull($activation->claim('SELF-LOCK', $email, 'B Shift', 'Engine 1', null, Hash::make(self::PASSWORD), CarbonImmutable::now()));
        }

        $record = MemberActivationAttempt::query()->sole();
        self::assertSame(5, $record->failed_attempts);
        self::assertNotNull($record->locked_at);
        self::assertNull($activation->claim('SELF-LOCK', $email, 'A Shift', 'Engine 1', null, Hash::make(self::PASSWORD), CarbonImmutable::now()));
        self::assertSame(AccountStatus::PendingActivation, $member->fresh()->account_status);
        self::assertSame(5, SecurityActionEvent::query()->where('action', 'member_self_service_activation_denied')->count());
        self::assertSame('verification_locked', SecurityActionEvent::query()->latest('id')->value('reason'));
        self::assertSame('ready', app(\App\Services\Identity\MemberOnboardingInvitationService::class)->assess($member->employeeProfile)['status']);
    }

    public function test_requests_are_rate_limited_per_employee_id(): void
    {
        config()->set('identity.self_service_activation.max_failed_attempts', 50);
        $member = $this->pending('SELF-THROTTLE');
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post('/activate', $this->form('SELF-THROTTLE', ['shift' => 'B Shift']))->assertSessionHasErrors('activation');
        }

        $this->post('/activate', $this->form('SELF-THROTTLE'))->assertSessionHasErrors('activation');
        self::assertStringContainsString('Too many attempts', session('errors')->first('activation'));
        self::assertSame(AccountStatus::PendingActivation, $member->fresh()->account_status);
        self::assertSame(5, MemberActivationAttempt::query()->sole()->failed_attempts);
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
        self::assertDatabaseCount('member_activation_attempts', 0);
    }

    public function test_activation_keeps_approved_email_recovery_without_claiming_mailbox_verification(): void
    {
        $member = $this->pending('SELF-RECOVERY');
        $this->post('/activate', $this->form('SELF-RECOVERY'))->assertRedirect('/');
        $this->withCookie((string) config('session.cookie'), $this->app['session.store']->getId());

        $cityEmail = app(CityEmailVerificationService::class);
        self::assertSame('self-recovery@miamibeachfl.gov', $cityEmail->recoveryAddress($member->fresh()));
        self::assertNull($member->fresh()->email_verified_at);
        self::assertFalse($cityEmail->requiresReview($member->fresh()));
        $this->get('/account')->assertOk();
    }

    public function test_weak_or_mismatched_passwords_are_rejected_before_any_claim(): void
    {
        $member = $this->pending('SELF-WEAK');

        $this->post('/activate', $this->form('SELF-WEAK', ['password' => 'short', 'password_confirmation' => 'short']))
            ->assertSessionHasErrors('password');
        self::assertSame(
            ['employee_id' => 'SELF-WEAK', 'email' => 'self-weak@miamibeachfl.gov', 'shift' => 'A Shift', 'unit' => 'Engine 1'],
            session()->getOldInput(),
        );
        $this->post('/activate', $this->form('SELF-WEAK', ['password_confirmation' => 'Different-Password!1']))
            ->assertSessionHasErrors('password');

        self::assertSame(AccountStatus::PendingActivation, $member->fresh()->account_status);
        self::assertDatabaseCount('member_activation_attempts', 0);
    }

    public function test_disabling_the_feature_hides_it_without_affecting_sign_in(): void
    {
        config()->set('identity.self_service_activation.enabled', false);
        config()->set('identity.self_service_activation.email_request_enabled', false);
        $member = $this->pending('SELF-OFF');

        $this->get('/login')->assertOk()->assertDontSee('Set up or recover your account')->assertSee('personal setup link');
        $this->get('/activate')->assertRedirect('/login');
        $this->get('/activate/email')->assertRedirect('/login');
        $this->post('/activate', $this->form('SELF-OFF'))->assertNotFound();
        $this->post('/activate/email', ['employee_id' => 'SELF-OFF'])->assertNotFound();
        self::assertSame(AccountStatus::PendingActivation, $member->fresh()->account_status);
    }

    public function test_claiming_follows_the_local_login_switch(): void
    {
        config()->set('identity.local_login_enabled', false);
        $member = $this->pending('SELF-NOLOCAL');

        $this->post('/activate', $this->form('SELF-NOLOCAL'))->assertNotFound();
        self::assertSame(AccountStatus::PendingActivation, $member->fresh()->account_status);
    }

    public function test_email_only_mode_sends_members_to_the_email_request_page(): void
    {
        config()->set('identity.self_service_activation.enabled', false);

        $this->get('/login')->assertOk()->assertSee('Set up or recover your account');
        $this->get('/activate')->assertRedirect('/activate/email');
        $this->get('/activate/email')->assertOk()->assertSee('Email my link')->assertDontSee('bid shift and unit');
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
            'target_user_id' => $member->id, 'action' => 'member_access_email_requested', 'reason' => 'setup_queued',
        ]);
    }

    public function test_email_requests_answer_identically_and_send_nothing_for_ineligible_accounts(): void
    {
        config()->set('queue.default', 'database');
        Queue::fake();
        $this->pending('EMAIL-NOBINDING', cityEmail: false);
        $active = $this->pending('EMAIL-ACTIVE');
        $active->forceFill(['account_status' => AccountStatus::Active, 'bootstrap_onboarding_eligible' => false])->save();
        $eligible = $this->pending('EMAIL-ELIGIBLE', bid: false);

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

    public function test_member_requested_email_is_capped_per_member_and_includes_privileged_accounts(): void
    {
        config()->set('queue.default', 'database');
        Queue::fake();
        $member = $this->pending('EMAIL-CAP');
        $admin = $this->pending('EMAIL-ADMIN');
        $admin->assignRole(Role::findOrCreate('admin', 'web'));
        $accessEmail = app(MemberAccessEmail::class);

        for ($attempt = 0; $attempt < 4; $attempt++) {
            $accessEmail->request('EMAIL-CAP');
        }
        // A setup link proves control of the approved City mailbox, exactly as
        // password reset does; it grants nothing, so administrators get one too.
        $accessEmail->request('EMAIL-ADMIN');

        // The job is unique per member, and the daily cap bounds repeats after it runs.
        Queue::assertPushed(IssueMemberOnboardingInvitation::class, 2);
        Queue::assertPushed(fn (IssueMemberOnboardingInvitation $job): bool => $job->userId === $member->id);
        Queue::assertPushed(fn (IssueMemberOnboardingInvitation $job): bool => $job->userId === $admin->id);
        self::assertSame(3, SecurityActionEvent::query()->where('target_user_id', $member->id)->where('result', 'allowed')->count());
        self::assertSame('throttled', SecurityActionEvent::query()->where('target_user_id', $member->id)->latest('id')->value('reason'));
        self::assertSame(['admin'], $admin->fresh()->getRoleNames()->all());
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
            'email' => strtolower($employeeId).'@miamibeachfl.gov',
            'shift' => 'A Shift',
            'unit' => 'Engine 1',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
        ];
    }

    /** @return array<string,string> */
    private function positionForm(string $employeeId, string $position): array
    {
        return [
            'employee_id' => $employeeId,
            'email' => strtolower($employeeId).'@miamibeachfl.gov',
            'position' => $position,
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
        ];
    }

    private function pending(
        string $employeeId,
        bool $cityEmail = true,
        bool $bid = true,
        string $shift = 'A Shift',
        string $unit = 'Engine 1',
        int $bidYear = 2026,
        ?string $rank = null,
    ): User {
        $email = strtolower($employeeId).'@miamibeachfl.gov';
        $employee = Employee::query()->create([
            'employee_id' => $employeeId,
            'name' => 'Activation Member '.$employeeId,
            'rank' => $rank ?? ($bid ? 'Firefighter' : null),
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
        if ($bid) {
            $this->bid($employee, $shift, $unit, $bidYear);
        }

        return User::factory()->create([
            'employee_profile_id' => $employee->id,
            'employee_id' => $employeeId,
            'email' => $cityEmail ? $email : 'pending-'.strtolower($employeeId).'@canonical.mbfdhub.invalid',
            'account_status' => AccountStatus::PendingActivation,
            'bootstrap_onboarding_eligible' => true,
            'bootstrap_onboarding_eligible_at' => now(),
            'security_version' => 1,
        ])->fresh('employeeProfile');
    }

    private function bid(Employee $employee, string $shift, string $unit, int $year): EmployeeBidAssignment
    {
        $key = 'activation-test-'.$employee->id.'-'.$year.'-'.bin2hex(random_bytes(4));

        return EmployeeBidAssignment::query()->create([
            'employee_profile_id' => $employee->id,
            'payload_version' => 2,
            'bid_year' => $year,
            'term_label' => $year.'–'.($year + 1),
            'bid_session_id' => 'session-'.$year,
            'position_id' => 'P-'.$employee->id,
            'rank_label' => 'Firefighter',
            'shift_label' => $shift,
            'station_label' => 'Station 1',
            'unit_label' => $unit,
            'bid_selection_label' => $unit,
            'assignment_source' => 'bid_award',
            'a_day_label' => 'None',
            'idempotency_key' => $key,
            'payload_hash' => hash('sha256', $key),
            'is_forced' => false,
        ]);
    }
}
