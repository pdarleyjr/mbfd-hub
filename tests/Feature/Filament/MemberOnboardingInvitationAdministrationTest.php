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
use Illuminate\Support\Facades\DB;
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

    public function test_super_admin_can_review_and_queue_one_approved_pending_member(): void
    {
        $admin = $this->admin();
        $pending = $this->member('PILOT-READY', AccountStatus::PendingActivation, true);
        $other = $this->member('PILOT-OTHER', AccountStatus::PendingActivation, true);
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(ListEmployees::class)
            ->assertTableActionVisible('sendMemberInvitation', $pending->employeeProfile)
            ->mountTableAction('sendMemberInvitation', $pending->employeeProfile)
            ->assertSee($pending->employee_id)
            ->assertSee($pending->employeeProfile->city_email)
            ->set('mountedTableActionsData.0.confirm_member', true)
            ->set('mountedTableActionsData.0.current_password', 'admin-password')
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors();

        Queue::assertPushed(IssueMemberOnboardingInvitation::class, 1);
        Queue::assertPushed(IssueMemberOnboardingInvitation::class, fn (IssueMemberOnboardingInvitation $job): bool => $job->userId === $pending->id && $job->initiatorId === $admin->id);
        Queue::assertNotPushed(IssueMemberOnboardingInvitation::class, fn (IssueMemberOnboardingInvitation $job): bool => $job->userId === $other->id);
        self::assertDatabaseCount('member_onboarding_invitations', 0);
        self::assertDatabaseHas('security_action_events', [
            'actor_user_id' => $admin->id,
            'target_user_id' => $pending->id,
            'action' => 'member_onboarding_invitation_single_requested',
            'result' => 'allowed',
        ]);
    }

    public function test_single_member_action_is_hidden_for_ineligible_accounts(): void
    {
        $admin = $this->admin();
        $active = $this->member('PILOT-ACTIVE', AccountStatus::Active, true);
        $disabled = $this->member('PILOT-DISABLED', AccountStatus::Disabled, true);
        $unapproved = $this->member('PILOT-UNAPPROVED', AccountStatus::PendingActivation, false);
        $inactive = $this->member('PILOT-INACTIVE', AccountStatus::PendingActivation, true);
        $inactive->employeeProfile->forceFill(['roster_status' => 'inactive'])->save();
        $conflicted = $this->member('PILOT-CONFLICT', AccountStatus::PendingActivation, true);
        $alreadyInvited = $this->member('PILOT-INVITED', AccountStatus::PendingActivation, true);
        MemberOnboardingInvitation::query()->create([
            'user_id' => $alreadyInvited->id,
            'employee_profile_id' => $alreadyInvited->employee_profile_id,
            'email' => $alreadyInvited->employeeProfile->city_email,
            'security_version' => $alreadyInvited->security_version,
            'token_hash' => hash('sha256', 'current-pilot-token'),
            'expires_at' => now()->addMinutes(20),
            'delivery_status' => 'queued',
        ]);
        User::factory()->create(['email' => $conflicted->employeeProfile->city_email]);
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $page = Livewire::test(ListEmployees::class);
        foreach ([$active, $disabled, $unapproved, $inactive, $conflicted, $alreadyInvited] as $member) {
            $page->assertTableActionHidden('sendMemberInvitation', $member->employeeProfile);
        }
        Queue::assertNothingPushed();
    }

    public function test_single_member_action_requires_super_admin_password_confirmation_and_unchanged_binding(): void
    {
        $admin = $this->admin();
        $pending = $this->member('PILOT-GUARDED', AccountStatus::PendingActivation, true);
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(ListEmployees::class)
            ->mountTableAction('sendMemberInvitation', $pending->employeeProfile)
            ->set('mountedTableActionsData.0.confirm_member', true)
            ->set('mountedTableActionsData.0.current_password', 'wrong-password')
            ->callMountedTableAction()
            ->assertHasTableActionErrors(['current_password']);
        Queue::assertNothingPushed();

        $page = Livewire::test(ListEmployees::class)->mountTableAction('sendMemberInvitation', $pending->employeeProfile);
        $pending->employeeProfile->forceFill(['city_email' => 'changed@miamibeachfl.gov'])->save();
        $page->set('mountedTableActionsData.0.confirm_member', true)
            ->set('mountedTableActionsData.0.current_password', 'admin-password')
            ->callMountedTableAction()
            ->assertTableActionHidden('sendMemberInvitation', $pending->employeeProfile);
        Queue::assertNothingPushed();
    }

    public function test_single_member_action_is_not_available_to_a_non_super_admin(): void
    {
        $pending = $this->member('PILOT-NONADMIN', AccountStatus::PendingActivation, true);
        $member = User::factory()->create(['account_status' => AccountStatus::Active]);
        $member->givePermissionTo(Permission::findOrCreate('admin.personnel.view', 'web'));
        $this->actingAs($member);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(ListEmployees::class)->assertTableActionHidden('sendMemberInvitation', $pending->employeeProfile);
        Queue::assertNothingPushed();
    }

    public function test_repeated_single_member_submission_queues_only_one_unique_job(): void
    {
        $admin = $this->admin();
        $pending = $this->member('PILOT-DOUBLE', AccountStatus::PendingActivation, true);
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        for ($attempt = 0; $attempt < 2; $attempt++) {
            Livewire::test(ListEmployees::class)
                ->mountTableAction('sendMemberInvitation', $pending->employeeProfile)
                ->set('mountedTableActionsData.0.confirm_member', true)
                ->set('mountedTableActionsData.0.current_password', 'admin-password')
                ->callMountedTableAction()
                ->assertHasNoTableActionErrors();
        }

        Queue::assertPushed(IssueMemberOnboardingInvitation::class, 1);
    }

    public function test_single_member_action_rechecks_eligibility_and_allows_expired_or_failed_retry(): void
    {
        $admin = $this->admin();
        $expired = $this->member('PILOT-EXPIRED', AccountStatus::PendingActivation, true);
        $failed = $this->member('PILOT-FAILED', AccountStatus::PendingActivation, true);
        $activated = $this->member('PILOT-ACTIVATED', AccountStatus::PendingActivation, true);
        foreach ([$expired, $failed] as $member) {
            MemberOnboardingInvitation::query()->create([
                'user_id' => $member->id,
                'employee_profile_id' => $member->employee_profile_id,
                'email' => $member->employeeProfile->city_email,
                'security_version' => $member->security_version,
                'token_hash' => hash('sha256', $member->employee_id),
                'expires_at' => $member->id === $expired->id ? now()->subMinute() : now()->addMinutes(20),
                'delivery_status' => $member->id === $expired->id ? 'queued' : 'failed',
            ]);
        }
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::test(ListEmployees::class)->assertTableActionVisible('sendMemberInvitation', $expired->employeeProfile)
            ->assertTableActionVisible('sendMemberInvitation', $failed->employeeProfile);

        $page = Livewire::test(ListEmployees::class)->mountTableAction('sendMemberInvitation', $expired->employeeProfile);
        MemberOnboardingRosterBinding::query()->where('employee_profile_id', $expired->employee_profile_id)->delete();
        $page->set('mountedTableActionsData.0.confirm_member', true)
            ->set('mountedTableActionsData.0.current_password', 'admin-password')
            ->callMountedTableAction()
            ->assertTableActionHidden('sendMemberInvitation', $expired->employeeProfile);

        $page = Livewire::test(ListEmployees::class)->mountTableAction('sendMemberInvitation', $activated->employeeProfile);
        $activated->forceFill(['account_status' => AccountStatus::Active])->save();
        $page->set('mountedTableActionsData.0.confirm_member', true)
            ->set('mountedTableActionsData.0.current_password', 'admin-password')
            ->callMountedTableAction()
            ->assertTableActionHidden('sendMemberInvitation', $activated->employeeProfile);
        Queue::assertNothingPushed();
    }

    public function test_queued_single_member_job_refuses_changed_account_roster_and_email(): void
    {
        Http::fake();
        $admin = $this->admin();
        $becameActive = $this->member('JOB-ACTIVE', AccountStatus::PendingActivation, true);
        $rosterRevoked = $this->member('JOB-ROSTER', AccountStatus::PendingActivation, true);
        $emailChanged = $this->member('JOB-EMAIL', AccountStatus::PendingActivation, true);
        $jobs = [];
        foreach ([$becameActive, $rosterRevoked, $emailChanged] as $member) {
            $jobs[] = new IssueMemberOnboardingInvitation(
                $member->id,
                $admin->id,
                IssueMemberOnboardingInvitation::bindingHash(
                    $member->id, $member->employee_id, (string) $member->employeeProfile->city_email, $member->security_version,
                ),
            );
        }

        $becameActive->forceFill(['account_status' => AccountStatus::Active])->save();
        MemberOnboardingRosterBinding::query()->where('employee_profile_id', $rosterRevoked->employee_profile_id)->delete();
        $emailChanged->employeeProfile->forceFill(['city_email' => 'changed-job@miamibeachfl.gov'])->save();
        foreach ($jobs as $job) {
            $job->handle(app(MemberOnboardingInvitationService::class));
        }

        self::assertDatabaseCount('member_onboarding_invitations', 0);
        Http::assertNothingSent();
    }

    public function test_batch_assessment_has_bounded_queries_for_227_pending_members(): void
    {
        for ($index = 1; $index <= 227; $index++) {
            $this->member(sprintf('PERF-%04d', $index), AccountStatus::PendingActivation, true);
        }

        $queries = 0;
        DB::listen(static function () use (&$queries): void {
            $queries++;
        });
        $startedAt = hrtime(true);
        $employees = Employee::query()
            ->whereHas('user', fn ($query) => $query->where('account_status', AccountStatus::PendingActivation->value))
            ->with('user')
            ->orderBy('employee_id')
            ->get();
        $assessments = app(MemberOnboardingInvitationService::class)->assessMany($employees);
        $elapsedMs = (hrtime(true) - $startedAt) / 1_000_000;

        self::assertCount(227, $assessments);
        self::assertSame(['ready'], array_values(array_unique(array_column($assessments, 'status'))));
        self::assertLessThanOrEqual(20, $queries, sprintf('Assessment used %d queries in %.1f ms.', $queries, $elapsedMs));
    }

    public function test_batch_assessment_preserves_individual_eligibility_outcomes(): void
    {
        $ready = $this->member('BATCH-READY', AccountStatus::PendingActivation, true);
        $missingEmail = $this->member('BATCH-MISSING', AccountStatus::PendingActivation, true);
        $missingEmail->employeeProfile->forceFill(['city_email' => null])->save();
        $unapproved = $this->member('BATCH-UNAPPROVED', AccountStatus::PendingActivation, false);
        $active = $this->member('BATCH-ACTIVE', AccountStatus::Active, true);
        $conflicted = $this->member('BATCH-CONFLICT', AccountStatus::PendingActivation, true);
        User::factory()->create(['email' => $conflicted->employeeProfile->city_email]);
        $invited = $this->member('BATCH-INVITED', AccountStatus::PendingActivation, true);
        $expired = $this->member('BATCH-EXPIRED', AccountStatus::PendingActivation, true);
        $failed = $this->member('BATCH-FAILED', AccountStatus::PendingActivation, true);
        foreach ([$invited, $expired, $failed] as $member) {
            MemberOnboardingInvitation::query()->create([
                'user_id' => $member->id,
                'employee_profile_id' => $member->employee_profile_id,
                'email' => $member->employeeProfile->city_email,
                'security_version' => $member->security_version,
                'token_hash' => hash('sha256', $member->employee_id),
                'expires_at' => $member->is($expired) ? now()->subMinute() : now()->addMinutes(20),
                'delivery_status' => $member->is($failed) ? 'failed' : 'queued',
            ]);
        }
        $withoutUser = Employee::query()->create([
            'employee_id' => 'BATCH-NO-USER',
            'name' => 'Onboarding Test Member',
            'roster_status' => 'active',
            'city_email' => 'batch-no-user@miamibeachfl.gov',
        ]);
        $employees = Employee::query()->whereIn('id', [
            $ready->employee_profile_id,
            $missingEmail->employee_profile_id,
            $unapproved->employee_profile_id,
            $active->employee_profile_id,
            $conflicted->employee_profile_id,
            $invited->employee_profile_id,
            $expired->employee_profile_id,
            $failed->employee_profile_id,
            $withoutUser->id,
        ])->get();
        $service = app(MemberOnboardingInvitationService::class);
        $assessments = $service->assessMany($employees);

        foreach ($employees as $employee) {
            self::assertSame($service->assess($employee), $assessments[$employee->id]);
        }
    }

    public function test_selected_group_previews_exact_eligible_and_grouped_skips_and_queues_only_selected_once(): void
    {
        $admin = $this->admin();
        $ready = $this->member('SELECT-READY', AccountStatus::PendingActivation, true);
        $second = $this->member('SELECT-SECOND', AccountStatus::PendingActivation, true);
        $active = $this->member('SELECT-ACTIVE', AccountStatus::Active, true);
        $unapproved = $this->member('SELECT-NOROSTER', AccountStatus::PendingActivation, false);
        $unselected = $this->member('SELECT-OUTSIDE', AccountStatus::PendingActivation, true);
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $records = collect([$ready, $second, $active, $unapproved])->map->employeeProfile;

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $page = Livewire::test(ListEmployees::class)
                ->mountTableBulkAction('sendSelectedOnboardingInvitations', $records)
                ->assertSee('2</strong> ready to invite', false)
                ->assertSee('Not in approved roster')
                ->assertSee('Not eligible');
            $cohort = $page->get('selectedInvitationCohort');
            self::assertSame([$ready->id, $second->id], array_column($cohort['ready'], 'user_id'));
            self::assertCount(2, $cohort['skipped']);
            self::assertNotContains($unselected->employee_profile_id, $cohort['selected_ids']);
            $page->set('mountedTableBulkActionData.confirm_recipients', true)
                ->set('mountedTableBulkActionData.current_password', 'admin-password')
                ->callMountedTableBulkAction()
                ->assertHasNoTableBulkActionErrors();
        }
        Queue::assertPushed(IssueMemberOnboardingInvitation::class, 2);
        Queue::assertNotPushed(IssueMemberOnboardingInvitation::class, fn ($job): bool => $job->userId === $unselected->id);
        self::assertDatabaseCount('member_onboarding_invitations', 0);
        self::assertDatabaseHas('security_action_events', ['action' => 'member_onboarding_invitation_selected_requested', 'actor_user_id' => $admin->id]);
    }

    public function test_selected_group_rejects_wrong_password_and_stale_eligibility_or_selection(): void
    {
        $admin = $this->admin();
        $ready = $this->member('SELECT-GUARDED', AccountStatus::PendingActivation, true);
        $other = $this->member('SELECT-OTHER', AccountStatus::PendingActivation, true);
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(ListEmployees::class)
            ->mountTableBulkAction('sendSelectedOnboardingInvitations', [$ready->employeeProfile])
            ->set('mountedTableBulkActionData.confirm_recipients', true)
            ->set('mountedTableBulkActionData.current_password', 'wrong')
            ->callMountedTableBulkAction()
            ->assertHasTableBulkActionErrors(['current_password']);

        $page = Livewire::test(ListEmployees::class)
            ->mountTableBulkAction('sendSelectedOnboardingInvitations', [$ready->employeeProfile]);
        $ready->increment('security_version');
        $page->set('mountedTableBulkActionData.confirm_recipients', true)
            ->set('mountedTableBulkActionData.current_password', 'admin-password')
            ->callMountedTableBulkAction()
            ->assertHasTableBulkActionErrors(['confirm_recipients']);

        Livewire::test(ListEmployees::class)
            ->mountTableBulkAction('sendSelectedOnboardingInvitations', [$ready->employeeProfile])
            ->set('selectedTableRecords', [(string) $other->employee_profile_id])
            ->set('mountedTableBulkActionData.confirm_recipients', true)
            ->set('mountedTableBulkActionData.current_password', 'admin-password')
            ->callMountedTableBulkAction()
            ->assertHasTableBulkActionErrors(['confirm_recipients']);
        Queue::assertNothingPushed();
    }

    public function test_selected_group_action_is_unavailable_to_non_super_admin(): void
    {
        $member = User::factory()->create(['account_status' => AccountStatus::Active]);
        $member->givePermissionTo(Permission::findOrCreate('admin.personnel.view', 'web'));
        $this->actingAs($member);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(ListEmployees::class)->assertTableBulkActionHidden('sendSelectedOnboardingInvitations');
        Queue::assertNothingPushed();
    }

    public function test_selected_cohort_snapshot_cannot_be_replaced_by_client_data(): void
    {
        $this->actingAs($this->admin());
        $member = $this->member('SELECT-LOCKED', AccountStatus::PendingActivation, true);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $page = Livewire::test(ListEmployees::class)
            ->mountTableBulkAction('sendSelectedOnboardingInvitations', [$member->employeeProfile]);

        $this->expectException(\Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException::class);
        $page->set('selectedInvitationCohort.hash', str_repeat('a', 64));
    }

    public function test_selected_group_without_eligible_people_never_queues_mail(): void
    {
        $this->actingAs($this->admin());
        $member = $this->member('SELECT-INELIGIBLE', AccountStatus::Active, true);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::test(ListEmployees::class)
            ->mountTableBulkAction('sendSelectedOnboardingInvitations', [$member->employeeProfile])
            ->assertSee('There are no eligible recipients')
            ->set('mountedTableBulkActionData.confirm_recipients', true)
            ->set('mountedTableBulkActionData.current_password', 'admin-password')
            ->callMountedTableBulkAction()
            ->assertHasTableBulkActionErrors(['confirm_recipients']);
        Queue::assertNothingPushed();
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
