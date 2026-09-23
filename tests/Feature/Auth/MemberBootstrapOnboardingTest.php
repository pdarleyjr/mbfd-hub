<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\AccountStatus;
use App\Models\CloudflareUsageBudget;
use App\Models\Employee;
use App\Models\MemberOnboardingInvitation;
use App\Models\MemberOnboardingRosterBinding;
use App\Models\OutboundEmail;
use App\Models\SecurityActionEvent;
use App\Models\User;
use App\Services\Identity\MemberBootstrapSession;
use App\Services\Identity\MemberOnboardingInvitationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class MemberBootstrapOnboardingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('identity.local_login_enabled', true);
        config()->set('communications.cloudflare.account_id', str_repeat('a', 32));
        config()->set('communications.cloudflare.api_token', 'member-onboarding-test-token');
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
                'message_id' => 'member-onboarding-test-message',
                'delivered' => $request->data()['to'],
                'queued' => [],
                'permanent_bounces' => [],
                'suppressed_recipients' => [],
            ],
        ]));
    }

    public function test_an_invitation_is_bound_to_one_member_and_completes_atomic_activation(): void
    {
        [$memberA, $memberB] = [$this->pending('ONBOARD-A'), $this->pending('ONBOARD-B')];
        $token = $this->issueAndExtractToken($memberA);

        $this->from('/login')->post('/login', [
            'employee_id' => $memberB->employee_id,
            'password' => 'Test-Only-Shared-Bootstrap!7824',
        ])->assertRedirect('/login')->assertSessionHasErrors('employee_id');

        $this->post('/member-onboarding/invite', ['token' => $token])->assertRedirect('/member-onboarding');
        $this->get('/member-onboarding')->assertOk()->assertSee($memberA->employee_id)->assertDontSee($memberB->employee_id);
        $this->post('/member-onboarding', [
            'password' => 'New-Private-Password!7824',
            'password_confirmation' => 'New-Private-Password!7824',
        ])->assertRedirect('/');

        $memberA->refresh();
        self::assertSame(AccountStatus::Active, $memberA->account_status);
        self::assertFalse($memberA->bootstrap_onboarding_eligible);
        self::assertNotNull($memberA->bootstrap_onboarding_completed_at);
        self::assertNotNull($memberA->email_verified_at);
        self::assertTrue(Hash::check('New-Private-Password!7824', $memberA->password));
        self::assertSame(AccountStatus::PendingActivation, $memberB->fresh()->account_status);
        self::assertDatabaseHas('member_onboarding_invitations', ['user_id' => $memberA->id, 'delivery_status' => 'consumed']);
        self::assertDatabaseMissing('member_onboarding_invitations', ['user_id' => $memberA->id, 'token_hash' => hash('sha256', $token)]);
        self::assertDatabaseHas('authentication_sessions', ['user_id' => $memberA->id, 'revoked_at' => null]);
        self::assertSame('complete_member_onboarding', SecurityActionEvent::query()->latest('id')->value('action'));
        self::assertSame('[Sensitive account-security message omitted]', OutboundEmail::query()->sole()->text_body);

        $this->post('/logout')->assertRedirect('/login');
        $this->post('/member-onboarding/invite', ['token' => $token])->assertRedirect('/login');
        $this->assertGuest('web');
        $this->post('/login', [
            'employee_id' => $memberA->employee_id,
            'password' => 'New-Private-Password!7824',
        ])->assertRedirect('/');
        $this->get('/account')->assertOk();
        $this->assertAuthenticatedAs($memberA, 'web');
    }

    public function test_replayed_replaced_or_security_stale_invitation_fails_closed(): void
    {
        $member = $this->pending('ONBOARD-REPLAY');
        $first = $this->issueAndExtractToken($member);
        MemberOnboardingInvitation::query()->where('user_id', $member->id)->update(['expires_at' => now()->subSecond()]);
        $second = $this->issueAndExtractToken($member);

        $this->post('/member-onboarding/invite', ['token' => $first])->assertRedirect('/login');
        $member->increment('security_version');
        $this->post('/member-onboarding/invite', ['token' => $second])->assertRedirect('/login');

        $member->refresh();
        self::assertSame(AccountStatus::PendingActivation, $member->account_status);
        self::assertNull($member->bootstrap_onboarding_completed_at);
        $invitation = MemberOnboardingInvitation::query()->sole();
        self::assertNull($invitation->redeemed_at);
        self::assertNotNull($invitation->token_hash);
    }

    public function test_expired_invitation_cannot_be_redeemed(): void
    {
        $startedAt = CarbonImmutable::now();
        CarbonImmutable::setTestNow($startedAt);
        try {
            $member = $this->pending('ONBOARD-EXPIRED');
            $token = $this->issueAndExtractToken($member);
            CarbonImmutable::setTestNow($startedAt->addMinutes(31));

            $this->post('/member-onboarding/invite', ['token' => $token])->assertRedirect('/login');
            self::assertSame(AccountStatus::PendingActivation, $member->fresh()->account_status);
            self::assertNull(MemberOnboardingInvitation::query()->sole()->redeemed_at);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_security_change_after_link_redemption_blocks_password_activation(): void
    {
        $member = $this->pending('ONBOARD-STALE');
        $token = $this->issueAndExtractToken($member);
        $this->post('/member-onboarding/invite', ['token' => $token])->assertRedirect('/member-onboarding');
        $member->increment('security_version');

        $this->post('/member-onboarding', [
            'password' => 'New-Private-Password!7824',
            'password_confirmation' => 'New-Private-Password!7824',
        ])->assertRedirect('/login');

        self::assertSame(AccountStatus::PendingActivation, $member->fresh()->account_status);
        self::assertNotNull(MemberOnboardingInvitation::query()->sole()->redeemed_at);
        self::assertNull(MemberOnboardingInvitation::query()->sole()->consumed_at);
    }

    public function test_missing_authoritative_address_cannot_receive_an_invitation(): void
    {
        $missing = $this->pending('ONBOARD-MISSING', null);

        $service = app(MemberOnboardingInvitationService::class);
        self::assertSame('missing_authoritative_city_email', $service->assess($missing->employeeProfile)['status']);
        self::assertDatabaseCount('member_onboarding_invitations', 0);
        Http::assertNothingSent();
    }

    public function test_city_domain_address_without_approved_roster_binding_cannot_receive_an_invitation(): void
    {
        $member = $this->pending('ONBOARD-UNAPPROVED');
        MemberOnboardingRosterBinding::query()->where('employee_profile_id', $member->employee_profile_id)->delete();

        self::assertSame('unapproved_roster_binding', app(MemberOnboardingInvitationService::class)->assess($member->employeeProfile)['status']);
        self::assertDatabaseCount('member_onboarding_invitations', 0);
        Http::assertNothingSent();
    }

    public function test_an_outstanding_invitation_is_not_replaced_by_a_second_send(): void
    {
        $member = $this->pending('ONBOARD-ONCE');
        $this->issueAndExtractToken($member);
        $originalHash = MemberOnboardingInvitation::query()->sole()->token_hash;

        self::assertSame('already_invited', app(MemberOnboardingInvitationService::class)->assess($member->employeeProfile)['status']);
        self::assertSame('already_invited', app(MemberOnboardingInvitationService::class)->issue($member, CarbonImmutable::now()));
        self::assertSame($originalHash, MemberOnboardingInvitation::query()->sole()->token_hash);
        Http::assertSentCount(1);
    }

    public function test_admin_initiated_invitation_is_attributed_to_the_admin_without_exposing_its_token(): void
    {
        $member = $this->pending('ONBOARD-ADMIN-SEND');
        $admin = User::factory()->create(['account_status' => AccountStatus::Active]);

        self::assertSame('queued', app(MemberOnboardingInvitationService::class)->issue($member, CarbonImmutable::now(), $admin));
        self::assertDatabaseHas('security_action_events', [
            'actor_user_id' => $admin->id,
            'target_user_id' => $member->id,
            'action' => 'member_onboarding_invitation_issued',
        ]);
        self::assertSame($admin->id, OutboundEmail::query()->sole()->initiated_by_user_id);
        self::assertSame('[Sensitive account-security message omitted]', OutboundEmail::query()->sole()->text_body);
    }

    public function test_departed_member_and_colliding_city_address_cannot_receive_invitations(): void
    {
        $departed = $this->pending('ONBOARD-DEPARTED');
        $departed->employeeProfile->forceFill(['roster_status' => 'departed'])->save();
        $collision = $this->pending('ONBOARD-COLLISION');
        User::factory()->create(['email' => $collision->employeeProfile->city_email]);

        $service = app(MemberOnboardingInvitationService::class);
        self::assertSame('not_pending_onboarding', $service->assess($departed->employeeProfile)['status']);
        self::assertSame('email_conflict', $service->assess($collision->employeeProfile)['status']);
        self::assertDatabaseCount('member_onboarding_invitations', 0);
        Http::assertNothingSent();
    }

    public function test_member_a_invitation_cannot_activate_member_b_from_a_restricted_session(): void
    {
        $memberA = $this->pending('ONBOARD-CROSS-A');
        $memberB = $this->pending('ONBOARD-CROSS-B');
        $token = $this->issueAndExtractToken($memberA);
        $this->post('/member-onboarding/invite', ['token' => $token])->assertRedirect('/member-onboarding');
        $context = session(MemberBootstrapSession::KEY);
        $context['user_id'] = $memberB->id;
        $context['employee_profile_id'] = $memberB->employee_profile_id;
        session()->put(MemberBootstrapSession::KEY, $context);

        $this->post('/member-onboarding', [
            'password' => 'New-Private-Password!7824',
            'password_confirmation' => 'New-Private-Password!7824',
        ])->assertRedirect('/login');
        self::assertSame(AccountStatus::PendingActivation, $memberA->fresh()->account_status);
        self::assertSame(AccountStatus::PendingActivation, $memberB->fresh()->account_status);
    }

    public function test_new_member_recovery_requires_the_verified_authoritative_city_address(): void
    {
        $member = $this->pending('ONBOARD-RECOVERY');
        $member->forceFill([
            'account_status' => AccountStatus::Active,
            'bootstrap_onboarding_eligible' => false,
            'bootstrap_onboarding_completed_at' => now(),
            'email_verified_at' => null,
        ])->save();

        $this->post('/forgot-password', ['employee_id' => $member->employee_id])->assertRedirect()->assertSessionHas('status');
        Http::assertNothingSent();
        self::assertDatabaseCount('password_reset_tokens', 0);
    }

    public function test_administrator_assisted_activation_does_not_verify_recovery_email(): void
    {
        $member = $this->pending('ONBOARD-ASSISTED');
        app(\App\Services\Identity\AccountSecurityService::class)->activateWithTemporaryPassword(
            $member,
            Hash::make('One-Time-Temporary!7824'),
            hash('sha256', 'unique-assisted-test-fingerprint'),
            now(),
        );

        $member->refresh();
        self::assertNotNull($member->bootstrap_onboarding_completed_at);
        self::assertNull($member->email_verified_at);
        $this->post('/forgot-password', ['employee_id' => $member->employee_id])->assertRedirect()->assertSessionHas('status');
        Http::assertNothingSent();
        self::assertDatabaseCount('password_reset_tokens', 0);
    }

    private function pending(string $employeeId, ?string $cityEmail = 'member@miamibeachfl.gov'): User
    {
        $employee = Employee::query()->create([
            'employee_id' => $employeeId,
            'name' => 'Onboarding Member '.$employeeId,
            'roster_status' => 'active',
            'city_email' => $cityEmail === null ? null : strtolower($employeeId).'@miamibeachfl.gov',
            'password' => Hash::make('legacy-password'),
        ]);

        if ($cityEmail !== null) {
            MemberOnboardingRosterBinding::query()->create([
                'employee_profile_id' => $employee->id,
                'employee_id' => $employee->employee_id,
                'city_email' => strtolower($employeeId).'@miamibeachfl.gov',
                'source_sha256' => str_repeat('a', 64),
                'approved_at' => now(),
            ]);
        }

        return User::factory()->create([
            'employee_profile_id' => $employee->id,
            'employee_id' => $employee->employee_id,
            'email' => 'pending-'.$employeeId.'@canonical.mbfdhub.invalid',
            'account_status' => AccountStatus::PendingActivation,
            'bootstrap_onboarding_eligible' => true,
            'bootstrap_onboarding_eligible_at' => now(),
            'security_version' => 1,
        ])->fresh('employeeProfile');
    }

    private function issueAndExtractToken(User $member): string
    {
        self::assertSame('queued', app(MemberOnboardingInvitationService::class)->issue($member, CarbonImmutable::now()));
        $request = Http::recorded()->last()[0];
        preg_match('/#([a-f0-9]{64})/', (string) $request->data()['text'], $matches);
        self::assertArrayHasKey(1, $matches);

        return $matches[1];
    }
}
