<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Enums\AccountStatus;
use App\Enums\Security\AccountSecurityAction;
use App\Models\Employee;
use App\Models\User;
use App\Services\Security\AccountSecurityService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class AccountSecurityFreshAuthorityTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Current-authorized-password!';

    public static function actions(): array
    {
        return array_map(fn (string $action): array => [$action], ['disable', 'enable', 'resetPassword', 'forcePasswordChange', 'revokeSessions']);
    }

    #[DataProvider('actions')]
    public function test_each_mutation_rejects_stale_disabled_actor(string $action): void
    {
        [$actor, $target] = $this->identities();
        DB::table('users')->where('id', $actor->id)->update(['account_status' => 'disabled']);
        $this->assertDenied($actor, $target, $action);
    }

    #[DataProvider('actions')]
    public function test_each_mutation_rejects_stale_role_and_password(string $action): void
    {
        [$actor, $target] = $this->identities();
        $actor->load('roles');
        $actor->fresh()->syncRoles([]);
        $this->assertDenied($actor, $target, $action);
        $actor->fresh()->assignRole('super_admin');
        DB::table('users')->where('id', $actor->id)->update(['password' => Hash::make('New-authoritative-password!')]);
        $this->assertDenied($actor, $target, $action);
    }

    #[DataProvider('actions')]
    public function test_successful_mutations_require_current_password_even_with_recent_session(string $action): void
    {
        [$actor, $target] = $this->identities();
        $this->assertDenied($actor, $target, $action, null);
        $this->assertDenied($actor, $target, $action, 'wrong');
        $this->invoke($actor, $target, $action, self::PASSWORD);
        self::assertSame(2, $target->fresh()->security_version);
        $this->assertDatabaseHas('security_action_events', ['target_user_id' => $target->id, 'result' => 'allowed']);
    }

    public function test_current_password_can_establish_recent_authentication_but_empty_reason_and_self_are_denied(): void
    {
        [$actor, $target] = $this->identities();
        session()->forget('auth.password_confirmed_at');
        try {
            app(AccountSecurityService::class)->disable($actor, $target, ' ', now(), self::PASSWORD);
            self::fail('An audit reason is required.');
        } catch (AuthorizationException) {
            self::assertSame(1, $target->fresh()->security_version);
        }
        $this->assertDenied($actor, $actor, 'disable');
        app(AccountSecurityService::class)->disable($actor, $target, 'Approved incident', now(), self::PASSWORD);
        self::assertSame(AccountStatus::Disabled, $target->fresh()->account_status);
    }

    public function test_can_perform_uses_fresh_authority_and_does_not_create_success_audit(): void
    {
        [$actor, $target] = $this->identities();
        $service = app(AccountSecurityService::class);
        self::assertTrue($service->canPerform($actor, $target, AccountSecurityAction::Disable));
        self::assertFalse($service->canPerform($actor, $actor, AccountSecurityAction::Disable));
        DB::table('users')->where('id', $actor->id)->update(['account_status' => 'disabled']);
        self::assertFalse($service->canPerform($actor, $target, AccountSecurityAction::Disable));
        $this->assertDatabaseCount('security_action_events', 0);
    }

    public function test_failed_mutation_rolls_back_success_audit_and_records_failure(): void
    {
        [$actor, $target] = $this->identities();
        DB::unprepared("CREATE TRIGGER deny_security_test_update BEFORE UPDATE ON users BEGIN SELECT RAISE(ABORT, 'synthetic failure'); END");
        try {
            $this->invoke($actor, $target, 'disable', self::PASSWORD);
            self::fail('The injected write failure must propagate.');
        } catch (\Illuminate\Database\QueryException) {
            self::assertSame(1, $target->fresh()->security_version);
            $this->assertDatabaseMissing('security_action_events', ['result' => 'allowed']);
            $this->assertDatabaseHas('security_action_events', ['target_user_id' => $target->id, 'result' => 'failed']);
        } finally {
            DB::unprepared('DROP TRIGGER deny_security_test_update');
        }
    }

    public function test_departed_employee_cannot_be_enabled_even_with_a_stale_active_roster_relation(): void
    {
        [$actor, $target] = $this->identities();
        $employee = Employee::query()->create(['employee_id' => 'ENABLE-TEST', 'name' => 'Enable Test', 'rank' => 'Firefighter', 'password' => Hash::make('Legacy-preserved-password'), 'roster_status' => 'active']);
        $target->forceFill(['employee_profile_id' => $employee->id, 'employee_id' => $employee->employee_id, 'account_status' => AccountStatus::Disabled])->save();
        $target->load('employeeProfile');
        DB::table('employees')->where('id', $employee->id)->update(['roster_status' => 'departed']);
        $service = app(AccountSecurityService::class);

        self::assertFalse($service->canPerform($actor, $target, AccountSecurityAction::Enable));
        $this->assertDenied($actor, $target, 'enable');
        self::assertSame(AccountStatus::Disabled, $target->fresh()->account_status);
        self::assertSame('departed', $employee->fresh()->roster_status);
        $this->assertDatabaseMissing('security_action_events', ['result' => 'allowed']);
    }

    /** @return array<string, array{string}> */
    public static function enableableRosterStatuses(): array
    {
        return ['active' => ['active'], 'legacy unknown' => ['unknown']];
    }

    #[DataProvider('enableableRosterStatuses')]
    public function test_enable_preserves_active_and_legacy_unknown_roster_behavior(string $status): void
    {
        [$actor, $target] = $this->identities();
        $employee = Employee::query()->create(['employee_id' => 'ENABLE-TEST', 'name' => 'Enable Test', 'rank' => 'Firefighter', 'password' => Hash::make('Legacy-preserved-password'), 'roster_status' => $status]);
        $target->forceFill(['employee_profile_id' => $employee->id, 'employee_id' => $employee->employee_id, 'account_status' => AccountStatus::Disabled])->save();
        $service = app(AccountSecurityService::class);
        self::assertTrue($service->canPerform($actor, $target, AccountSecurityAction::Enable));
        $service->enable($actor, $target, 'Approved return to access', now(), self::PASSWORD);
        self::assertSame(AccountStatus::Active, $target->fresh()->account_status);
        self::assertSame($status, $employee->fresh()->roster_status);
    }

    private function identities(): array
    {
        Role::findOrCreate('super_admin', 'web');
        $actor = User::factory()->create(['account_status' => AccountStatus::Active, 'password' => self::PASSWORD]);
        $actor->assignRole('super_admin');
        $target = User::factory()->create(['account_status' => AccountStatus::Active]);
        session()->put('auth.password_confirmed_at', time());

        return [$actor, $target];
    }

    private function invoke(User $actor, User $target, string $action, ?string $password): void
    {
        $service = app(AccountSecurityService::class);
        if ($action === 'resetPassword') {
            $service->resetPassword($actor, $target, 'One-time-recovery-secret!', 'Approved incident', now(), $password);
        } else {
            $service->{$action}($actor, $target, 'Approved incident', now(), $password);
        }
    }

    private function assertDenied(User $actor, User $target, string $action, ?string $password = self::PASSWORD): void
    {
        $before = $target->fresh()->getAttributes();
        try {
            $this->invoke($actor, $target, $action, $password);
            self::fail('Fresh authority and current password must be required.');
        } catch (AuthorizationException) {
            self::assertSame($before, $target->fresh()->getAttributes());
            $this->assertDatabaseHas('security_action_events', ['target_user_id' => $target->id, 'result' => 'denied']);
        }
    }
}
