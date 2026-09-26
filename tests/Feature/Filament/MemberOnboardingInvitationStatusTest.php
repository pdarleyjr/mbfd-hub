<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Enums\AccountStatus;
use App\Filament\Resources\EmployeeResource\Pages\ListEmployees;
use App\Models\Employee;
use App\Models\MemberOnboardingInvitation;
use App\Models\OutboundEmail;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class MemberOnboardingInvitationStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_invitation_column_distinguishes_current_expired_failed_active_and_unsent_states(): void
    {
        $this->travelTo(now()->startOfSecond());
        $queued = $this->member('INVITE-QUEUED');
        $sending = $this->member('INVITE-SENDING');
        $redeemed = $this->member('INVITE-REDEEMED');
        $expiredSending = $this->member('INVITE-EXPIRED-P');
        $expiredQueued = $this->member('INVITE-EXPIRED-Q');
        $expiredRedeemed = $this->member('INVITE-EXPIRED-R');
        $failed = $this->member('INVITE-FAILED');
        $active = $this->member('INVITE-ACTIVE', AccountStatus::Active);
        $neverSent = $this->member('INVITE-UNSENT');

        foreach ([
            [$queued, 'queued', now()->addMinute()],
            [$sending, 'pending', now()->addMinute()],
            [$redeemed, 'redeemed', now()->addMinute()],
            [$expiredSending, 'pending', now()->subSecond()],
            [$expiredQueued, 'queued', now()->subSecond()],
            [$expiredRedeemed, 'redeemed', now()],
            [$failed, 'failed', null],
            [$active, 'consumed', now()->subSecond()],
        ] as [$user, $deliveryStatus, $expiresAt]) {
            MemberOnboardingInvitation::query()->create([
                'user_id' => $user->id,
                'employee_profile_id' => $user->employee_profile_id,
                'email' => $user->employeeProfile->city_email,
                'security_version' => $user->security_version,
                'token_hash' => hash('sha256', $user->employee_id),
                'expires_at' => $expiresAt,
                'consumed_at' => $user->is($active) ? now()->subSecond() : null,
                'delivery_status' => $deliveryStatus,
            ]);
        }

        $admin = User::factory()->create(['account_status' => AccountStatus::Active]);
        $admin->givePermissionTo(Permission::findOrCreate('admin.personnel.view', 'web'));
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(ListEmployees::class)
            ->assertTableColumnStateSet('invitation_delivery', 'Pending delivery', $queued->employeeProfile)
            ->assertTableColumnStateSet('invitation_delivery', 'Preparing', $sending->employeeProfile)
            ->assertTableColumnStateSet('invitation_status', 'Awaiting activation', $queued->employeeProfile)
            ->assertTableColumnStateSet('invitation_status', 'Opened / redeemed', $redeemed->employeeProfile)
            ->assertTableColumnStateSet('invitation_status', 'Expired — ready to resend', $expiredSending->employeeProfile)
            ->assertTableColumnStateSet('invitation_status', 'Expired — ready to resend', $expiredQueued->employeeProfile)
            ->assertTableColumnStateSet('invitation_status', 'Expired — ready to resend', $expiredRedeemed->employeeProfile)
            ->assertTableColumnStateSet('invitation_delivery', 'Delivery failed', $failed->employeeProfile)
            ->assertTableColumnStateSet('invitation_status', 'Awaiting activation', $failed->employeeProfile)
            ->assertTableColumnStateSet('invitation_status', 'Account activated', $active->employeeProfile)
            ->assertTableColumnStateSet('invitation_delivery', 'Invitation not sent', $neverSent->employeeProfile);
    }

    public function test_confirmed_delivery_does_not_claim_account_activation_and_survives_expiration(): void
    {
        $this->travelTo(now()->startOfSecond());
        $member = $this->member('INVITE-DELIVERED');
        $invitation = MemberOnboardingInvitation::query()->create([
            'user_id' => $member->id, 'employee_profile_id' => $member->employee_profile_id,
            'email' => $member->employeeProfile->city_email, 'security_version' => 1,
            'delivery_status' => 'queued', 'sent_at' => now()->subMinutes(31), 'expires_at' => now()->subMinute(),
        ]);
        $outbound = OutboundEmail::query()->create([
            'source_type' => 'member_onboarding_invitation', 'source_id' => (string) $invitation->id,
            'from_address' => 'hub@example.test', 'to_recipients' => [$invitation->email],
            'recipient_count' => 1, 'chargeable_budget_units' => 1,
            'subject' => 'Set up your account', 'status' => 'delivered', 'delivered_at' => now()->subMinutes(30),
        ]);
        $invitation->update(['outbound_email_id' => $outbound->id]);
        // A different transactional source may have the same numeric source ID.
        OutboundEmail::query()->create([
            'source_type' => 'password_reset', 'source_id' => (string) $invitation->id,
            'from_address' => 'hub@example.test', 'to_recipients' => [$invitation->email],
            'subject' => 'Reset', 'status' => 'failed',
            'recipient_count' => 1, 'chargeable_budget_units' => 1,
        ]);
        $admin = User::factory()->create(['account_status' => AccountStatus::Active]);
        $admin->givePermissionTo(Permission::findOrCreate('admin.personnel.view', 'web'));
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(ListEmployees::class)
            ->assertTableColumnStateSet('invitation_delivery', 'Delivered', $member->employeeProfile)
            ->assertTableColumnStateSet('invitation_status', 'Expired — ready to resend', $member->employeeProfile);
    }

    private function member(string $employeeId, AccountStatus $status = AccountStatus::PendingActivation): User
    {
        $employee = Employee::query()->create([
            'employee_id' => $employeeId,
            'name' => 'Onboarding Test Member',
            'roster_status' => 'active',
            'city_email' => strtolower($employeeId).'@miamibeachfl.gov',
        ]);

        return User::factory()->create([
            'employee_profile_id' => $employee->id,
            'employee_id' => $employeeId,
            'account_status' => $status,
        ])->fresh('employeeProfile');
    }
}
