<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\AccountStatus;
use App\Filament\Resources\EmployeeResource\Pages\EditEmployee;
use App\Jobs\IssueMemberOnboardingInvitation;
use App\Jobs\SendMemberPasswordLink;
use App\Models\CloudflareUsageBudget;
use App\Models\Employee;
use App\Models\MemberOnboardingRosterBinding;
use App\Models\OutboundEmail;
use App\Models\SecurityActionEvent;
use App\Models\User;
use App\Services\Identity\MemberAccessEmail;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * One Employee ID in, the right email out: setup for an account that never
 * set a password, reset for an established one (administrators included),
 * nothing for anything else, and the same answer every time.
 */
final class MemberAccessEmailTest extends TestCase
{
    use RefreshDatabase;

    private const NEW_PASSWORD = 'Fleet-Manager-Private-2026!';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('identity.local_login_enabled', true);
        config()->set('identity.credential_authority', 'local');
        config()->set('identity.self_service_activation.enabled', true);
        config()->set('identity.self_service_activation.email_request_enabled', true);
        config()->set('communications.cloudflare.account_id', str_repeat('a', 32));
        config()->set('communications.cloudflare.api_token', 'member-access-email-test-token');
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
                'message_id' => 'member-access-email-test-message',
                'delivered' => $request->data()['to'],
                'queued' => [],
                'permanent_bounces' => [],
                'suppressed_recipients' => [],
            ],
        ]));
    }

    public function test_an_administrator_who_never_signed_in_gets_a_setup_link_at_the_address_on_record(): void
    {
        $admin = $this->established('ACCESS-ADMIN', admin: true);
        $rolesBefore = $admin->getRoleNames()->sort()->values()->all();
        $permissionsBefore = $admin->getDirectPermissions()->pluck('name')->sort()->values()->all();

        $this->post('/activate/email', ['employee_id' => 'ACCESS-ADMIN', 'email' => 'attacker@example.com'])
            ->assertRedirect('/activate/email')
            ->assertSessionHas('status', fn (string $status): bool => str_starts_with($status, 'If this Employee ID is eligible'));

        $email = OutboundEmail::query()->sole();
        self::assertSame(['access-admin@miamibeachfl.gov'], $email->to_recipients);
        self::assertSame('password_reset', $email->source_type);
        $sent = (string) Http::recorded()->sole()[0]['text'];
        self::assertStringContainsString('choose your MBFD Hub password and sign in for the first time', $sent);
        self::assertSame('Set up your MBFD Hub password', Http::recorded()->sole()[0]['subject']);

        $this->post('/reset-password', $this->resetForm('ACCESS-ADMIN', $this->tokenFrom($sent)))
            ->assertRedirect('/login')->assertSessionHas('status');
        self::assertTrue(Hash::check(self::NEW_PASSWORD, (string) $admin->fresh()->password));
        self::assertSame($rolesBefore, $admin->fresh()->getRoleNames()->sort()->values()->all());
        self::assertSame($permissionsBefore, $admin->fresh()->getDirectPermissions()->pluck('name')->sort()->values()->all());
        $this->post('/login', ['employee_id' => 'ACCESS-ADMIN', 'password' => self::NEW_PASSWORD])->assertRedirect();
        $this->assertAuthenticatedAs($admin->fresh(), 'web');
        self::assertDatabaseHas('security_action_events', [
            'target_user_id' => $admin->id, 'action' => 'member_access_email_requested', 'result' => 'allowed', 'reason' => 'reset_queued',
        ]);
    }

    public function test_a_member_who_has_signed_in_before_gets_a_reset_link_from_either_page(): void
    {
        $member = $this->established('ACCESS-MEMBER');
        $member->forceFill(['last_login_at' => now()->subDay()])->save();

        $this->post('/forgot-password', ['employee_id' => 'ACCESS-MEMBER'])->assertSessionHas('status');

        self::assertSame('MBFD Hub password reset', Http::recorded()->sole()[0]['subject']);
        self::assertSame(['access-member@miamibeachfl.gov'], OutboundEmail::query()->sole()->to_recipients);
    }

    public function test_a_pending_account_gets_the_setup_invitation_and_privileged_ones_are_included(): void
    {
        config()->set('queue.default', 'database');
        Queue::fake();
        $member = $this->pending('ACCESS-PENDING');
        $admin = $this->pending('ACCESS-PENDING-ADMIN');
        $admin->assignRole(Role::findOrCreate('admin', 'web'));

        $this->post('/activate/email', ['employee_id' => 'ACCESS-PENDING'])->assertSessionHas('status');
        $this->post('/forgot-password', ['employee_id' => 'ACCESS-PENDING-ADMIN'])->assertSessionHas('status');

        Queue::assertPushed(IssueMemberOnboardingInvitation::class, 2);
        Queue::assertPushed(fn (IssueMemberOnboardingInvitation $job): bool => $job->userId === $member->id && $job->initiatorId === null);
        Queue::assertPushed(fn (IssueMemberOnboardingInvitation $job): bool => $job->userId === $admin->id && $job->initiatorId === null);
        Queue::assertNotPushed(SendMemberPasswordLink::class);
    }

    public function test_every_account_state_gets_the_same_answer_and_only_eligible_ones_get_mail(): void
    {
        $this->established('STATE-READY');
        $this->established('STATE-DISABLED')->forceFill(['account_status' => AccountStatus::Disabled])->save();
        $this->established('STATE-NOEMAIL', cityEmail: null, accountEmail: 'pending-noemail@canonical.mbfdhub.invalid');
        Employee::query()->create(['employee_id' => 'STATE-UNBOUND', 'name' => 'Unbound Person', 'roster_status' => 'active',
            'city_email' => 'state-unbound@miamibeachfl.gov', 'password' => 'legacy-not-used']);
        $this->established('STATE-DEPARTED');
        Employee::query()->where('employee_id', 'STATE-DEPARTED')->update(['roster_status' => 'departed']);

        $answers = [];
        foreach (['STATE-DISABLED', 'STATE-NOEMAIL', 'STATE-UNBOUND', 'STATE-DEPARTED', 'STATE-UNKNOWN', 'STATE-READY'] as $employeeId) {
            $response = $this->post('/activate/email', ['employee_id' => $employeeId]);
            $answers[] = [$response->getStatusCode(), $response->headers->get('Location'), session('status')];
        }

        self::assertCount(1, array_unique(array_map('serialize', $answers)));
        self::assertSame(['state-ready@miamibeachfl.gov'], OutboundEmail::query()->sole()->to_recipients);
        self::assertSame(['account_disabled', 'no_email_on_record'], SecurityActionEvent::query()
            ->where('action', 'member_access_email_requested')->where('result', 'denied')->orderBy('id')->pluck('reason')->all());
        $this->assertDatabaseCount('password_reset_tokens', 1);
    }

    public function test_the_reset_link_works_once_and_expires(): void
    {
        $this->established('TOKEN-LIFE');
        $this->post('/forgot-password', ['employee_id' => 'TOKEN-LIFE']);
        $token = $this->tokenFrom((string) Http::recorded()->sole()[0]['text']);
        self::assertNotSame($token, (string) \DB::table('password_reset_tokens')->value('token'), 'Only a hash is stored.');

        $this->travel(61)->minutes();
        $this->post('/reset-password', $this->resetForm('TOKEN-LIFE', $token))->assertSessionHasErrors('employee_id');
        $this->travelBack();

        $this->established('TOKEN-ONCE');
        $this->post('/forgot-password', ['employee_id' => 'TOKEN-ONCE']);
        $token = $this->tokenFrom((string) Http::recorded()[1][0]['text']);
        $this->post('/reset-password', $this->resetForm('TOKEN-LIFE', $token))->assertSessionHasErrors('employee_id');
        $this->post('/reset-password', $this->resetForm('TOKEN-ONCE', $token))->assertRedirect('/login');
        $this->post('/reset-password', $this->resetForm('TOKEN-ONCE', $token))->assertSessionHasErrors('employee_id');
    }

    public function test_self_service_requests_are_capped_per_account_but_administrators_can_resend(): void
    {
        config()->set('queue.default', 'database');
        Queue::fake();
        $member = $this->established('LIMIT-ACCOUNT');
        $service = app(MemberAccessEmail::class);

        $results = [];
        for ($attempt = 0; $attempt < 4; $attempt++) {
            $results[] = $service->request('LIMIT-ACCOUNT');
        }

        self::assertSame(['reset_queued', 'reset_queued', 'reset_queued', 'throttled'], $results);
        $administrator = User::factory()->create(['account_status' => AccountStatus::Active]);
        self::assertSame('reset_queued', $service->request('LIMIT-ACCOUNT', $administrator));
        self::assertDatabaseHas('security_action_events', [
            'actor_user_id' => $administrator->id, 'target_user_id' => $member->id, 'reason' => 'reset_queued',
        ]);
    }

    public function test_the_request_form_is_rate_limited_per_employee_id(): void
    {
        $this->established('LIMIT-FORM');
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->post('/activate/email', ['employee_id' => 'LIMIT-FORM'])->assertSessionHas('status');
        }

        $this->post('/activate/email', ['employee_id' => 'LIMIT-FORM'])->assertSessionHasErrors('employee_id');
    }

    public function test_knowledge_based_activation_still_refuses_privileged_accounts(): void
    {
        $admin = $this->pending('CLAIM-ADMIN');
        $admin->assignRole(Role::findOrCreate('admin', 'web'));

        $this->post('/activate', [
            'employee_id' => 'CLAIM-ADMIN', 'email' => 'claim-admin@miamibeachfl.gov', 'position' => 'Firefighter',
            'password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD,
        ])->assertSessionHasErrors('activation');

        self::assertSame(AccountStatus::PendingActivation, $admin->fresh()->account_status);
    }

    public function test_administrators_see_why_a_member_cannot_get_a_link_and_can_resend_it(): void
    {
        $this->withoutVite();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $actor = User::factory()->create(['account_status' => AccountStatus::Active]);
        $actor->assignRole(Role::findOrCreate('super_admin', 'web'));
        $this->actingAs($actor);
        $ready = $this->established('ADMIN-VIEW-READY');
        $this->established('ADMIN-VIEW-NOEMAIL', cityEmail: null, accountEmail: 'pending-view@canonical.mbfdhub.invalid');

        Livewire::test(EditEmployee::class, ['record' => $ready->employee_profile_id])
            ->assertSee('Can sign in. A password reset link can be emailed to admin-view-ready@miamibeachfl.gov.')
            ->callAction('sendAccessEmail')
            ->assertNotified('Link queued for delivery');
        self::assertSame(['admin-view-ready@miamibeachfl.gov'], OutboundEmail::query()->sole()->to_recipients);

        $noEmail = Employee::query()->where('employee_id', 'ADMIN-VIEW-NOEMAIL')->sole();
        Livewire::test(EditEmployee::class, ['record' => $noEmail->id])
            ->assertSee('Cannot send: there is no deliverable email on record.')
            ->assertActionHidden('sendAccessEmail');
    }

    public function test_approved_roster_address_supports_recovery_without_fabricating_mailbox_verification(): void
    {
        config()->set('queue.default', 'database');
        Queue::fake();
        $member = $this->pending('APPROVED-RECOVERY');
        $member->forceFill(['account_status' => AccountStatus::Active,
            'bootstrap_onboarding_completed_at' => now(), 'email_verified_at' => null])->save();
        $before = $member->fresh()->getRawOriginal();
        self::assertSame('reset_queued', app(MemberAccessEmail::class)->request($member->employee_id));
        self::assertSame($before, $member->fresh()->getRawOriginal());
        Queue::assertPushed(SendMemberPasswordLink::class);

        MemberOnboardingRosterBinding::query()->where('employee_profile_id', $member->employee_profile_id)
            ->update(['city_email' => 'other@miamibeachfl.gov']);
        self::assertFalse(app(MemberAccessEmail::class)->diagnose($member->employeeProfile)['can_send']);
        self::assertSame('email_not_approved', app(MemberAccessEmail::class)->diagnose($member->employeeProfile)['status']);
    }

    public function test_pasted_employee_id_uses_the_same_identity_and_recovery_path(): void
    {
        config()->set('queue.default', 'database');
        Queue::fake();
        $member = $this->established('0019965');
        self::assertSame('reset_queued', app(MemberAccessEmail::class)->request("\u{00a0}0019965\u{202f}"));
        Queue::assertPushed(SendMemberPasswordLink::class, fn ($job): bool => $job->userId === $member->id);
        self::assertSame('unknown_employee_id', app(MemberAccessEmail::class)->request('19965'));
        self::assertSame('unknown_employee_id', app(MemberAccessEmail::class)->request('0019 965'));
    }

    public function test_uncertain_delivery_has_a_bounded_recovery_window(): void
    {
        $member = $this->established('DELIVERY-PENDING');
        OutboundEmail::query()->create([
            'provider' => 'cloudflare', 'source_type' => 'password_reset', 'source_id' => (string) $member->id,
            'from_address' => 'hub@miamibeachfl.gov', 'to_recipients' => [$member->email],
            'subject' => 'Password reset', 'recipient_count' => 1, 'chargeable_budget_units' => 1, 'status' => 'acceptance_unknown',
        ]);
        $service = app(MemberAccessEmail::class);
        self::assertSame('delivery_pending', $service->diagnose($member->employeeProfile)['status']);
        $this->travel(61)->minutes();
        self::assertSame(MemberAccessEmail::RESET_READY, $service->diagnose($member->employeeProfile)['status']);
    }

    private function established(string $employeeId, bool $admin = false, ?string $cityEmail = '', ?string $accountEmail = null): User
    {
        $address = strtolower($employeeId).'@miamibeachfl.gov';
        $employee = Employee::query()->create([
            'employee_id' => $employeeId,
            'name' => 'Access Person '.$employeeId,
            'roster_status' => 'active',
            'city_email' => $cityEmail === '' ? $address : $cityEmail,
            'password' => 'legacy-not-used',
        ]);
        $user = User::factory()->create([
            'employee_profile_id' => $employee->id,
            'employee_id' => $employeeId,
            'email' => $accountEmail ?? $address,
            'account_status' => AccountStatus::Active,
            'password' => Hash::make('unknown-seeded-password-2026'),
            'last_login_at' => null,
            'security_version' => 1,
        ]);
        if ($admin) {
            $user->assignRole(Role::findOrCreate('admin', 'web'));
            $user->givePermissionTo(Permission::findOrCreate('admin.fleet.view', 'web'));
        }

        return $user->fresh();
    }

    private function pending(string $employeeId): User
    {
        $email = strtolower($employeeId).'@miamibeachfl.gov';
        $employee = Employee::query()->create([
            'employee_id' => $employeeId, 'name' => 'Pending Person '.$employeeId, 'rank' => 'Firefighter',
            'roster_status' => 'active', 'city_email' => $email, 'password' => 'legacy-not-used',
        ]);
        MemberOnboardingRosterBinding::query()->create([
            'employee_profile_id' => $employee->id, 'employee_id' => $employeeId, 'city_email' => $email,
            'source_sha256' => str_repeat('a', 64), 'approved_at' => now(),
        ]);

        return User::factory()->create([
            'employee_profile_id' => $employee->id, 'employee_id' => $employeeId, 'email' => $email,
            'account_status' => AccountStatus::PendingActivation, 'bootstrap_onboarding_eligible' => true,
            'bootstrap_onboarding_eligible_at' => now(), 'security_version' => 1,
        ])->fresh();
    }

    private function tokenFrom(string $text): string
    {
        self::assertSame(1, preg_match('#/reset-password/([^?\s]+)\?employee_id=#', $text, $matches));

        return rawurldecode($matches[1]);
    }

    /** @return array<string,string> */
    private function resetForm(string $employeeId, string $token): array
    {
        return [
            'employee_id' => $employeeId,
            'token' => $token,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ];
    }
}
