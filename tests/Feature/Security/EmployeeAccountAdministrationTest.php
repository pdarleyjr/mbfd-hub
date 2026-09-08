<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Exceptions\CurrentPasswordMismatch;
use App\Models\AuthenticationSession;
use App\Models\CityEmailVerification;
use App\Models\Employee;
use App\Models\EmployeeProfileEvent;
use App\Models\SecurityActionEvent;
use App\Models\User;
use App\Services\Security\EmployeeAccountAdministration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class EmployeeAccountAdministrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_wrong_password_for_creation_records_failure_without_creating_or_changing_credentials(): void
    {
        $actor = $this->administrator();
        $employee = $this->employee();
        $hash = $employee->getRawOriginal('password');
        try {
            app(EmployeeAccountAdministration::class)->createForEmployee($actor, $employee, 'temporary-password', 'wrong', 'Approved onboarding');
            self::fail('Wrong administrator password must fail.');
        } catch (CurrentPasswordMismatch) {
            self::assertSame(1, User::query()->count());
            self::assertSame($hash, $employee->fresh()->getRawOriginal('password'));
            $event = EmployeeProfileEvent::query()->sole();
            self::assertSame('denied', $event->result);
            self::assertNull($event->target_user_id);
            self::assertStringNotContainsString('temporary-password', $event->toJson());
        }
    }

    public function test_creation_preserves_employee_identity_and_forces_first_login_password_change_without_mail(): void
    {
        Http::fake();
        $actor = $this->administrator();
        $employee = $this->employee();
        $employee->update(['phone' => '123', 'station' => 'Station 2', 'display_name' => 'Preferred', 'city_email' => 'provisioned@miamibeachfl.gov']);
        $hash = $employee->getRawOriginal('password');
        $user = app(EmployeeAccountAdministration::class)->createForEmployee($actor, $employee, 'temporary-password', 'admin-password', 'Approved onboarding')->fresh();
        self::assertSame($employee->id, $user->employee_profile_id);
        self::assertSame($employee->employee_id, $user->employee_id);
        self::assertSame($hash, $employee->fresh()->getRawOriginal('password'));
        self::assertTrue(Hash::check('temporary-password', $user->getAuthPassword()));
        self::assertTrue($user->must_change_password);
        self::assertSame('active', $user->getRawOriginal('account_status'));
        self::assertSame(['member'], $user->getRoleNames()->all());
        self::assertSame('provisioned@miamibeachfl.gov', $user->email);
        self::assertNull($user->email_verified_at);
        foreach (Employee::PROFILE_FIELDS as $field) {
            self::assertSame($employee->$field, $user->getRawOriginal($field));
        }
        Http::assertNothingSent();
    }

    public function test_duplicate_or_departed_provisioning_is_rejected_and_audited(): void
    {
        $actor = $this->administrator();
        $employee = $this->employee();
        $employee->update(['roster_status' => 'departed']);
        try {
            app(EmployeeAccountAdministration::class)->createForEmployee($actor, $employee, 'temporary-password', 'admin-password', 'Reviewed request');
            self::fail('Departed employee cannot receive login.');
        } catch (ValidationException) {
            self::assertSame(1, User::query()->count());
            self::assertSame(1, EmployeeProfileEvent::query()->count());
        }
        $employee->update(['roster_status' => 'active']);
        $existing = User::factory()->create(['employee_profile_id' => $employee->id, 'employee_id' => $employee->employee_id]);
        $original = $existing->fresh()->getRawOriginal();
        try {
            app(EmployeeAccountAdministration::class)->createForEmployee($actor, $employee, 'temporary-password', 'admin-password', 'Reviewed duplicate');
            self::fail('Duplicate employee cannot receive another login.');
        } catch (ValidationException) {
            self::assertSame($original, $existing->fresh()->getRawOriginal());
            self::assertSame(2, User::query()->count());
            self::assertSame(2, EmployeeProfileEvent::query()->count());
        }
    }

    public function test_nonemployee_approval_preserves_account_grants_and_records_denial_and_success(): void
    {
        $actor = $this->administrator();
        $target = User::factory()->create(['account_status' => 'active']);
        $target->givePermissionTo(Permission::findOrCreate('app.bid.access', 'web'));
        $original = $target->fresh()->only(['id', 'password', 'employee_id', 'employee_profile_id', 'account_status', 'email']);
        try {
            app(EmployeeAccountAdministration::class)->approveNonemployee($actor, $target, 'wrong', 'Approved external reviewer');
            self::fail('Wrong password must fail.');
        } catch (CurrentPasswordMismatch) {
            self::assertSame('denied', SecurityActionEvent::query()->sole()->result);
        }
        app(EmployeeAccountAdministration::class)->approveNonemployee($actor, $target, 'admin-password', 'Approved external reviewer');
        self::assertSame('approved_nonemployee', $target->fresh()->getRawOriginal('account_classification'));
        self::assertSame($original, $target->fresh()->only(array_keys($original)));
        self::assertTrue($target->fresh()->hasDirectWebPermission('app.bid.access'));
    }

    public function test_unlinked_recovery_change_is_reauthenticated_and_revokes_both_address_tokens(): void
    {
        Http::fake();
        $actor = $this->administrator();
        $target = User::factory()->create(['email' => 'old@miamibeachfl.gov', 'account_status' => 'active']);
        $target->forceFill(['account_classification' => 'approved_nonemployee'])->save();
        $session = AuthenticationSession::factory()->create(['user_id' => $target->id]);
        $hash = $target->getRawOriginal('password');
        foreach (['old@miamibeachfl.gov', 'new@miamibeachfl.gov'] as $email) {
            DB::table('password_reset_tokens')->insert(['email' => $email, 'token' => 'old-token', 'created_at' => now()]);
        }
        $version = $target->security_version;
        $updated = app(EmployeeAccountAdministration::class)->changeUnlinkedRecoveryEmail($actor, $target, 'NEW@miamibeachfl.gov', 'admin-password', 'Verified external contact correction');
        self::assertSame('new@miamibeachfl.gov', $updated->email);
        self::assertSame($target->id, $updated->id);
        self::assertSame($hash, $updated->getRawOriginal('password'));
        self::assertSame('approved_nonemployee', $updated->getRawOriginal('account_classification'));
        self::assertNull($updated->employee_profile_id);
        self::assertNull($updated->email_verified_at);
        self::assertSame($version + 1, $updated->security_version);
        self::assertNotNull($session->fresh()->revoked_at);
        self::assertSame(0, DB::table('password_reset_tokens')->count());
        self::assertSame('allowed', SecurityActionEvent::query()->sole()->result);
        Http::assertNothingSent();
    }

    public function test_unlinked_recovery_change_rejects_stale_password_collision_and_linked_account(): void
    {
        $actor = $this->administrator();
        $target = User::factory()->create(['email' => 'old@miamibeachfl.gov']);
        DB::table('users')->where('id', $actor->id)->update(['password' => Hash::make('rotated-admin-password')]);
        try {
            app(EmployeeAccountAdministration::class)->changeUnlinkedRecoveryEmail($actor, $target, 'new@miamibeachfl.gov', 'admin-password', 'Reason');
            self::fail('Stale password must fail.');
        } catch (CurrentPasswordMismatch) {
            self::assertSame('old@miamibeachfl.gov', $target->fresh()->email);
        }
        User::factory()->create(['email' => 'occupied@miamibeachfl.gov']);
        foreach (['occupied@miamibeachfl.gov', 'invalid'] as $email) {
            try {
                app(EmployeeAccountAdministration::class)->changeUnlinkedRecoveryEmail($actor, $target, $email, 'rotated-admin-password', 'Reason');
                self::fail('Invalid/colliding address must fail.');
            } catch (ValidationException) {
                self::assertSame('old@miamibeachfl.gov', $target->fresh()->email);
            }
        }
        $employee = $this->employee();
        $target->forceFill(['employee_profile_id' => $employee->id, 'employee_id' => $employee->employee_id])->save();
        try {
            app(EmployeeAccountAdministration::class)->changeUnlinkedRecoveryEmail($actor, $target, 'new@miamibeachfl.gov', 'rotated-admin-password', 'Reason');
            self::fail('Linked employee requires canonical city email workflow.');
        } catch (ValidationException) {
            self::assertSame('old@miamibeachfl.gov', $target->fresh()->email);
        }
        self::assertSame(4, SecurityActionEvent::query()->count());
    }

    public function test_unlinked_profile_security_field_attempt_is_audited_without_secret(): void
    {
        $actor = $this->administrator();
        $target = User::factory()->create();
        try {
            app(EmployeeAccountAdministration::class)->updateUnlinkedProfile($actor, $target, ['password' => 'do-not-audit-plaintext']);
            self::fail('Password is not a profile field.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface) {
            self::assertSame('denied', SecurityActionEvent::query()->sole()->result);
            self::assertStringNotContainsString('do-not-audit-plaintext', SecurityActionEvent::query()->sole()->toJson());
        }
    }

    public function test_unknown_account_classification_cannot_change_recovery_identity(): void
    {
        $actor = $this->administrator();
        $target = User::factory()->create();
        $target->forceFill(['account_classification' => 'unknown-import-state'])->save();
        $this->expectException(ValidationException::class);
        app(EmployeeAccountAdministration::class)->changeUnlinkedRecoveryEmail($actor, $target, 'new@miamibeachfl.gov', 'admin-password', 'Requested correction');
    }

    public function test_recovery_email_denies_self_nonadministrator_and_disabled_administrator(): void
    {
        $actor = $this->administrator();
        $target = User::factory()->create(['account_status' => 'active']);
        foreach ([[$actor, $actor], [$target, $actor]] as [$caller, $subject]) {
            try {
                app(EmployeeAccountAdministration::class)->changeUnlinkedRecoveryEmail($caller, $subject, 'new@miamibeachfl.gov', 'admin-password', 'Requested change');
                self::fail('Self/ordinary member must not change recovery identity.');
            } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $exception) {
                self::assertSame(403, $exception->getStatusCode());
            }
        }
        DB::table('users')->where('id', $actor->id)->update(['account_status' => 'disabled']);
        try {
            app(EmployeeAccountAdministration::class)->changeUnlinkedRecoveryEmail($actor, $target, 'new@miamibeachfl.gov', 'admin-password', 'Requested change');
            self::fail('Stale enabled actor must not authorize.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $exception) {
            self::assertSame(403, $exception->getStatusCode());
        }
        self::assertSame(3, SecurityActionEvent::query()->where('result', 'denied')->count());
        self::assertSame($target->email, $target->fresh()->email);
    }

    public function test_recovery_email_clears_stale_city_proof_and_rejects_employee_collision_or_missing_reason(): void
    {
        $actor = $this->administrator();
        $target = User::factory()->create(['email' => 'old@miamibeachfl.gov']);
        $employee = $this->employee();
        $employee->update(['city_email' => 'reserved@miamibeachfl.gov']);
        $proof = CityEmailVerification::query()->create([
            'user_id' => $target->id, 'employee_profile_id' => $employee->id,
            'email' => $target->email, 'original_user_email' => $target->email,
            'security_version' => $target->security_version, 'acknowledged_at' => now(),
            'verified_at' => now(), 'token_hash' => str_repeat('a', 64), 'token_expires_at' => now()->addHour(),
        ]);
        foreach ([['reserved@miamibeachfl.gov', 'Requested change'], ['new@miamibeachfl.gov', '']] as [$email, $reason]) {
            try {
                app(EmployeeAccountAdministration::class)->changeUnlinkedRecoveryEmail($actor, $target, $email, 'admin-password', $reason);
                self::fail('Roster collision/reason must be checked.');
            } catch (ValidationException) {
                self::assertNotNull($proof->fresh()->verified_at);
                self::assertSame('old@miamibeachfl.gov', $target->fresh()->email);
            }
        }
        app(EmployeeAccountAdministration::class)->changeUnlinkedRecoveryEmail($actor, $target, 'new@miamibeachfl.gov', 'admin-password', 'Approved correction');
        self::assertNull($proof->fresh()->verified_at);
        self::assertNull($proof->fresh()->token_hash);
        self::assertNull($proof->fresh()->token_expires_at);
    }

    private function administrator(): User
    {
        $actor = User::factory()->create(['account_status' => 'active', 'password' => 'admin-password']);
        $actor->assignRole(Role::findOrCreate('super_admin', 'web'));

        return $actor;
    }

    private function employee(): Employee
    {
        return Employee::query()->create(['employee_id' => 'TEST-9911', 'name' => 'Roster Member', 'password' => 'original-employee-password', 'roster_status' => 'active']);
    }
}
