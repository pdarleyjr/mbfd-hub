<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Enums\AccountStatus;
use App\Enums\SessionContextClass;
use App\Models\Employee;
use App\Models\User;
use App\Models\Workgroup;
use App\Models\WorkgroupMember;
use App\Services\Identity\SessionRegistry;
use App\Services\Security\EmployeeIdentityService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class EmployeeIdentitySecurityTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Employee-identity-password!';

    public function test_correction_preserves_people_credentials_history_and_pending_status_but_revokes_sessions(): void
    {
        $actor = $this->actor();
        [$employee, $target] = $this->linked('10001');
        $session = $this->registeredSession($target);
        $target->forceFill(['account_status' => AccountStatus::PendingActivation])->save();
        $group = Workgroup::create(['name' => 'Preserved workgroup', 'created_by' => $actor->id]);
        $membership = WorkgroupMember::create(['workgroup_id' => $group->id, 'user_id' => $target->id, 'role' => 'member', 'is_active' => true]);
        $before = [$employee->password, $target->password, $target->id, $employee->id, $membership->fresh()->getAttributes()];

        app(EmployeeIdentityService::class)->correctEmployeeId($actor, $employee, '10002', self::PASSWORD, 'Correct authoritative roster identifier');

        self::assertSame('10002', $employee->fresh()->employee_id);
        self::assertSame('10002', $target->fresh()->employee_id);
        self::assertSame($before, [$employee->fresh()->password, $target->fresh()->password, $target->fresh()->id, $employee->fresh()->id, $membership->fresh()->getAttributes()]);
        self::assertSame(AccountStatus::PendingActivation, $target->fresh()->account_status);
        self::assertSame(2, $target->fresh()->security_version);
        self::assertNotNull($session->fresh()->revoked_at);
        $this->assertDatabaseHas('employee_profile_events', ['employee_id' => $employee->id, 'action' => 'correct_employee_id', 'result' => 'allowed']);
        $this->assertDatabaseHas('security_action_events', ['target_user_id' => $target->id, 'action' => 'correct_employee_id', 'result' => 'allowed']);
    }

    public function test_roster_only_correction_has_an_employee_audit_without_creating_a_login(): void
    {
        $actor = $this->actor();
        $employee = $this->employee('11001');
        app(EmployeeIdentityService::class)->correctEmployeeId($actor, $employee, '11002', self::PASSWORD, 'Verified roster correction');
        self::assertSame('11002', $employee->fresh()->employee_id);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseHas('employee_profile_events', ['employee_id' => $employee->id, 'target_user_id' => null, 'action' => 'correct_employee_id', 'result' => 'allowed']);
    }

    public function test_collisions_cannot_partially_rewrite_either_identity(): void
    {
        $actor = $this->actor();
        [$employee, $target] = $this->linked('12001');
        $this->employee('12002');
        User::factory()->create(['employee_id' => '12003']);
        foreach (['12002', '12003'] as $collision) {
            try {
                app(EmployeeIdentityService::class)->correctEmployeeId($actor, $employee, $collision, self::PASSWORD, 'Rejected collision');
                self::fail('A collision must be rejected before writes.');
            } catch (ValidationException) {
                self::assertSame('12001', $employee->fresh()->employee_id);
                self::assertSame('12001', $target->fresh()->employee_id);
                self::assertSame(1, $target->fresh()->security_version);
            }
        }
    }

    public function test_explicit_link_keeps_local_ids_password_and_roles_and_does_not_activate_pending_account(): void
    {
        $actor = $this->actor();
        $employee = $this->employee('13001');
        $target = User::factory()->create(['employee_id' => null, 'employee_profile_id' => null, 'account_status' => AccountStatus::PendingActivation]);
        $target->assignRole(Role::findOrCreate('training_viewer', 'web'));
        $password = $target->password;
        app(EmployeeIdentityService::class)->link($actor, $target, $employee, self::PASSWORD, 'Explicitly approved existing identities');
        self::assertSame($employee->id, $target->fresh()->employee_profile_id);
        self::assertSame('13001', $target->fresh()->employee_id);
        self::assertSame($password, $target->fresh()->password);
        self::assertSame(AccountStatus::PendingActivation, $target->fresh()->account_status);
        self::assertSame(['training_viewer'], $target->fresh()->getRoleNames()->all());
        self::assertSame(2, $target->fresh()->security_version);
    }

    public function test_existing_links_foreign_employee_ids_and_already_claimed_employees_cannot_be_reassigned(): void
    {
        $actor = $this->actor();
        [$employee, $target] = $this->linked('14001');
        $otherEmployee = $this->employee('14002');
        $unlinked = User::factory()->create(['employee_id' => null]);
        $foreign = User::factory()->create(['employee_id' => '14003']);
        foreach ([[$target, $otherEmployee], [$unlinked, $employee], [$foreign, $otherEmployee]] as [$account, $profile]) {
            try {
                app(EmployeeIdentityService::class)->link($actor, $account, $profile, self::PASSWORD, 'Reject identity reassignment');
                self::fail('Existing identity ownership must be preserved.');
            } catch (ValidationException) {
                self::assertSame($employee->id, $target->fresh()->employee_profile_id);
                self::assertNull($unlinked->fresh()->employee_profile_id);
                self::assertNull($foreign->fresh()->employee_profile_id);
            }
        }
    }

    public function test_stale_actor_self_and_missing_reason_or_password_are_denied(): void
    {
        $actor = $this->actor();
        [$employee, $target] = $this->linked('15001');
        foreach ([['wrong', 'Verified correction'], [self::PASSWORD, ' ']] as [$password, $reason]) {
            try {
                app(EmployeeIdentityService::class)->correctEmployeeId($actor, $employee, '15002', $password, $reason);
                self::fail('Fresh authentication and a reason are required.');
            } catch (AuthorizationException) {
                self::assertSame('15001', $employee->fresh()->employee_id);
            }
        }
        DB::table('users')->where('id', $actor->id)->update(['account_status' => 'disabled']);
        try {
            app(EmployeeIdentityService::class)->correctEmployeeId($actor, $employee, '15002', self::PASSWORD, 'Stale actor');
            self::fail('A disabled actor must not change identities.');
        } catch (AuthorizationException) {
            self::assertSame('15001', $target->fresh()->employee_id);
        }
    }

    public function test_departure_disables_and_revokes_but_returning_to_roster_does_not_reenable(): void
    {
        $actor = $this->actor();
        [$employee, $target] = $this->linked('16001');
        $session = $this->registeredSession($target);
        $service = app(EmployeeIdentityService::class);
        $service->changeEmploymentStatus($actor, $employee, 'departed', self::PASSWORD, 'Employment departure');
        self::assertSame('departed', $employee->fresh()->roster_status);
        self::assertSame(AccountStatus::Disabled, $target->fresh()->account_status);
        self::assertNotNull($session->fresh()->revoked_at);
        $service->changeEmploymentStatus($actor, $employee, 'active', self::PASSWORD, 'Employment return');
        self::assertSame('active', $employee->fresh()->roster_status);
        self::assertSame(AccountStatus::Disabled, $target->fresh()->account_status);
    }

    public function test_reactivating_a_roster_only_employee_creates_a_pending_member_without_a_human_credential(): void
    {
        $actor = $this->actor();
        $employee = $this->employee('16002');
        $employee->forceFill(['roster_status' => 'departed'])->save();

        app(EmployeeIdentityService::class)->changeEmploymentStatus(
            $actor,
            $employee,
            'active',
            self::PASSWORD,
            'Authoritative roster return',
        );

        $user = $employee->fresh()->user()->sole();
        self::assertSame(AccountStatus::PendingActivation, $user->account_status);
        self::assertTrue($user->must_change_password);
        self::assertSame(['member'], $user->getRoleNames()->all());
        self::assertNull($user->temporary_credential_fingerprint);
    }

    public function test_self_identity_changes_and_unsupported_employment_status_are_denied(): void
    {
        $actor = $this->actor();
        $employee = $this->employee('17001');
        $actor->forceFill(['employee_id' => '17001', 'employee_profile_id' => $employee->id])->save();
        $service = app(EmployeeIdentityService::class);
        foreach (['correct', 'depart'] as $action) {
            try {
                if ($action === 'correct') {
                    $service->correctEmployeeId($actor, $employee, '17002', self::PASSWORD, 'Denied self correction');
                } else {
                    $service->changeEmploymentStatus($actor, $employee, 'departed', self::PASSWORD, 'Denied self departure');
                }
                self::fail('Self security changes are denied.');
            } catch (AuthorizationException) {
                self::assertSame('17001', $employee->fresh()->employee_id);
                self::assertSame('active', $employee->fresh()->roster_status);
            }
        }
        $other = $this->employee('17003');
        try {
            $service->changeEmploymentStatus($actor, $other, 'deleted', self::PASSWORD, 'Invalid lifecycle');
            self::fail('Unknown lifecycle values are denied.');
        } catch (AuthorizationException) {
            self::assertSame('active', $other->fresh()->roster_status);
        }
    }

    public function test_failure_after_employee_update_rolls_back_both_records_and_audits_only_failure(): void
    {
        $actor = $this->actor();
        [$employee, $target] = $this->linked('18001');
        DB::unprepared("CREATE TRIGGER deny_identity_test_update BEFORE UPDATE OF employee_id ON users BEGIN SELECT RAISE(ABORT, 'synthetic failure'); END");
        try {
            app(EmployeeIdentityService::class)->correctEmployeeId($actor, $employee, '18002', self::PASSWORD, 'Test atomic correction');
            self::fail('The injected User write failure must propagate.');
        } catch (\Illuminate\Database\QueryException) {
            self::assertSame('18001', $employee->fresh()->employee_id);
            self::assertSame('18001', $target->fresh()->employee_id);
            self::assertSame(1, $target->fresh()->security_version);
            $this->assertDatabaseMissing('employee_profile_events', ['result' => 'allowed']);
            $this->assertDatabaseHas('employee_profile_events', ['employee_id' => $employee->id, 'result' => 'failed']);
            $this->assertDatabaseMissing('security_action_events', ['result' => 'allowed']);
        } finally {
            DB::unprepared('DROP TRIGGER deny_identity_test_update');
        }
    }

    public function test_explicit_nonemployee_conversion_is_audited_and_departed_profiles_cannot_be_linked(): void
    {
        $actor = $this->actor();
        $employee = $this->employee('19001');
        $employee->forceFill(['roster_status' => 'departed'])->save();
        $target = User::factory()->create(['employee_id' => null, 'account_classification' => 'approved_nonemployee']);
        $service = app(EmployeeIdentityService::class);
        try {
            $service->link($actor, $target, $employee, self::PASSWORD, 'Explicit conversion review');
            self::fail('Departed profiles must not acquire linked access.');
        } catch (ValidationException) {
            self::assertNull($target->fresh()->employee_profile_id);
            self::assertSame('approved_nonemployee', $target->fresh()->getRawOriginal('account_classification'));
        }
        $employee->forceFill(['roster_status' => 'active'])->save();
        $service->link($actor, $target, $employee, self::PASSWORD, 'Explicit conversion review');
        self::assertSame('unresolved', $target->fresh()->getRawOriginal('account_classification'));
        $event = \App\Models\EmployeeProfileEvent::query()->where('action', 'link_employee')->where('result', 'allowed')->firstOrFail();
        self::assertSame('approved_nonemployee', $event->metadata['before_account_classification']);
    }

    private function actor(): User
    {
        $actor = User::factory()->create(['account_status' => AccountStatus::Active, 'password' => self::PASSWORD]);
        $actor->assignRole(Role::findOrCreate('super_admin', 'web'));

        return $actor;
    }

    private function employee(string $employeeId): Employee
    {
        return Employee::create(['employee_id' => $employeeId, 'name' => 'Synthetic Employee', 'password' => 'Unchanged-legacy-password!', 'roster_status' => 'active']);
    }

    private function linked(string $employeeId): array
    {
        $employee = $this->employee($employeeId);
        $user = User::factory()->create(['employee_id' => $employeeId, 'employee_profile_id' => $employee->id, 'account_status' => AccountStatus::Active]);

        return [$employee, $user];
    }

    private function registeredSession(User $target): \App\Models\AuthenticationSession
    {
        $at = CarbonImmutable::now();

        return app(SessionRegistry::class)->register($target, 'employee-identity-test-session', SessionContextClass::UnmanagedBrowser, $at, $at->addHour(), $at->addDay());
    }
}
