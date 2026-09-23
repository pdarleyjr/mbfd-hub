<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Enums\AccountStatus;
use App\Filament\Resources\EmployeeResource\Pages\ListEmployees;
use App\Jobs\IssueMemberOnboardingInvitation;
use App\Models\Employee;
use App\Models\MemberOnboardingInvitation;
use App\Models\MemberOnboardingRosterBinding;
use App\Models\User;
use App\Services\Identity\MemberOnboardingInvitationService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class MemberOnboardingInvitationAdministrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        config(['queue.default' => 'database']);
        Queue::fake();
    }

    public function test_admin_must_review_and_explicitly_queue_only_unconfirmed_approved_members(): void
    {
        $admin = $this->admin();
        $pending = $this->member('INVITE-READY', AccountStatus::PendingActivation, true);
        $this->member('INVITE-ACTIVE', AccountStatus::Active, true);
        $this->member('INVITE-NO-ROSTER', AccountStatus::PendingActivation, false);
        $alreadyInvited = $this->member('INVITE-ISSUED', AccountStatus::PendingActivation, true);
        MemberOnboardingInvitation::query()->create([
            'user_id' => $alreadyInvited->id,
            'employee_profile_id' => $alreadyInvited->employee_profile_id,
            'email' => $alreadyInvited->employeeProfile->city_email,
            'security_version' => $alreadyInvited->security_version,
            'token_hash' => hash('sha256', 'existing-token'),
            'expires_at' => now()->addMinutes(20),
            'delivery_status' => 'queued',
        ]);

        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $page = Livewire::test(ListEmployees::class)
            ->assertActionExists('sendOnboardingInvitations')
            ->mountAction('sendOnboardingInvitations')
            ->assertSee('ready to invite')
            ->assertSee($pending->employee_id);
        Queue::assertNothingPushed();

        $page->set('mountedActionsData.0.confirm_recipients', true)
            ->set('mountedActionsData.0.current_password', 'admin-password')
            ->callMountedAction()
            ->assertHasNoActionErrors();

        Queue::assertPushed(IssueMemberOnboardingInvitation::class, 1);
        Queue::assertPushed(IssueMemberOnboardingInvitation::class, fn (IssueMemberOnboardingInvitation $job): bool => $job->userId === $pending->id && $job->initiatorId === $admin->id);
        self::assertDatabaseCount('member_onboarding_invitations', 1);
    }

    public function test_non_admin_cannot_access_the_send_action(): void
    {
        $member = User::factory()->create(['account_status' => AccountStatus::Active]);
        $member->givePermissionTo(Permission::findOrCreate('admin.personnel.view', 'web'));
        $this->actingAs($member);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(ListEmployees::class)->assertActionHidden('sendOnboardingInvitations');
        Queue::assertNothingPushed();
    }

    public function test_wrong_password_or_changed_recipient_list_does_not_queue_mail(): void
    {
        $admin = $this->admin();
        $pending = $this->member('INVITE-STALE', AccountStatus::PendingActivation, true);
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(ListEmployees::class)
            ->mountAction('sendOnboardingInvitations')
            ->set('mountedActionsData.0.confirm_recipients', true)
            ->set('mountedActionsData.0.current_password', 'wrong-password')
            ->callMountedAction()
            ->assertHasActionErrors(['current_password']);
        Queue::assertNothingPushed();

        $page = Livewire::test(ListEmployees::class)->mountAction('sendOnboardingInvitations');
        $pending->employeeProfile->forceFill(['city_email' => 'changed@miamibeachfl.gov'])->save();
        $page->set('mountedActionsData.0.confirm_recipients', true)
            ->set('mountedActionsData.0.current_password', 'admin-password')
            ->callMountedAction()
            ->assertHasActionErrors(['confirm_recipients']);
        Queue::assertNothingPushed();
    }

    public function test_queued_job_rechecks_account_status_before_delivery(): void
    {
        Http::fake();
        $admin = $this->admin();
        $pending = $this->member('INVITE-REVOKED', AccountStatus::PendingActivation, true);
        $job = new IssueMemberOnboardingInvitation(
            $pending->id,
            $admin->id,
            IssueMemberOnboardingInvitation::bindingHash(
                $pending->id,
                $pending->employee_id,
                (string) $pending->employeeProfile->city_email,
                $pending->security_version,
            ),
        );
        $pending->forceFill(['account_status' => AccountStatus::Disabled])->save();

        $job->handle(app(MemberOnboardingInvitationService::class));

        self::assertDatabaseCount('member_onboarding_invitations', 0);
        Http::assertNothingSent();
    }

    private function admin(): User
    {
        $admin = User::factory()->create([
            'account_status' => AccountStatus::Active,
            'password' => Hash::make('admin-password'),
        ]);
        $admin->assignRole(Role::findOrCreate('super_admin', 'web'));

        return $admin;
    }

    private function member(string $employeeId, AccountStatus $status, bool $approved): User
    {
        $employee = Employee::query()->create([
            'employee_id' => $employeeId,
            'name' => 'Onboarding Test Member',
            'roster_status' => 'active',
            'city_email' => strtolower($employeeId).'@miamibeachfl.gov',
        ]);
        if ($approved) {
            MemberOnboardingRosterBinding::query()->create([
                'employee_profile_id' => $employee->id,
                'employee_id' => $employeeId,
                'city_email' => $employee->city_email,
                'source_sha256' => str_repeat('a', 64),
                'approved_at' => now(),
            ]);
        }

        return User::factory()->create([
            'employee_profile_id' => $employee->id,
            'employee_id' => $employeeId,
            'email' => 'pending-'.strtolower($employeeId).'@canonical.mbfdhub.invalid',
            'account_status' => $status,
            'bootstrap_onboarding_eligible' => true,
            'bootstrap_onboarding_eligible_at' => now(),
            'security_version' => 1,
        ])->fresh('employeeProfile');
    }
}
