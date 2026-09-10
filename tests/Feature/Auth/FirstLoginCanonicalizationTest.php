<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\AccountStatus;
use App\Models\Employee;
use App\Models\User;
use App\Services\Identity\CanonicalUserProvisioner;
use App\Services\Security\EmployeeAccountAdministration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class FirstLoginCanonicalizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_roster_only_employee_cannot_claim_an_account_with_employee_credential(): void
    {
        $employee = Employee::query()->create([
            'employee_id' => 'RETIRED-CLAIM-1',
            'name' => 'Roster Only Employee',
            'password' => 'legacy-employee-password',
            'roster_status' => 'active',
        ]);

        $this->from('/login')->post('/login', [
            'employee_id' => $employee->employee_id,
            'password' => 'legacy-employee-password',
        ])->assertRedirect('/login')->assertSessionHasErrors('employee_id');

        self::assertSame(0, User::query()->count());
        self::assertFalse(session()->has('auth.canonical_activation_intent'));
    }

    public function test_pending_canonical_user_cannot_sign_in_before_an_administrator_issues_a_temporary_password(): void
    {
        $employee = $this->employee('PENDING-LOGIN-1');
        $pending = app(CanonicalUserProvisioner::class)
            ->create($employee->id, 'MISSING_OR_UNSUPPORTED', now())['user'];
        self::assertSame(AccountStatus::PendingActivation, $pending->account_status);

        $this->from('/login')->post('/login', [
            'employee_id' => $employee->employee_id,
            'password' => 'legacy-employee-password',
        ])->assertRedirect('/login')->assertSessionHasErrors('employee_id');

        $this->assertGuest('web');
        self::assertSame($pending->id, $employee->user()->sole()->id);
    }

    public function test_admin_issued_unique_temporary_password_starts_only_a_restricted_session(): void
    {
        $actor = User::factory()->create([
            'account_status' => AccountStatus::Active,
            'password' => 'admin-password',
        ]);
        $actor->assignRole(Role::findOrCreate('super_admin', 'web'));
        $employee = $this->employee('TEMP-LOGIN-1');
        $pending = app(CanonicalUserProvisioner::class)
            ->create($employee->id, 'MISSING_OR_UNSUPPORTED', now())['user'];
        $issued = app(EmployeeAccountAdministration::class)->createForEmployee(
            $actor,
            $employee,
            'individual-temporary-password-2026',
            'admin-password',
            'Controlled first login',
        );
        self::assertSame($pending->id, $issued->id);

        $this->post('/login', [
            'employee_id' => $employee->employee_id,
            'password' => 'individual-temporary-password-2026',
        ])->assertRedirect('/employee/set-password');

        $this->assertAuthenticatedAs($issued, 'web');
        self::assertTrue($issued->fresh()->must_change_password);
        self::assertTrue(Hash::check('individual-temporary-password-2026', $issued->fresh()->getAuthPassword()));
        $this->get('/employee/dashboard')->assertRedirect('/employee/set-password');
    }

    public function test_departed_employee_cannot_use_an_existing_canonical_password(): void
    {
        $employee = $this->employee('DEPARTED-LOGIN-1');
        $employee->forceFill(['roster_status' => 'departed'])->save();
        User::factory()->create([
            'employee_profile_id' => $employee->id,
            'employee_id' => $employee->employee_id,
            'account_status' => AccountStatus::Active,
            'password' => 'existing-private-password',
        ]);

        $this->from('/login')->post('/login', [
            'employee_id' => $employee->employee_id,
            'password' => 'existing-private-password',
        ])->assertRedirect('/login')->assertSessionHasErrors('employee_id');

        $this->assertGuest('web');
    }

    private function employee(string $employeeId): Employee
    {
        return Employee::query()->create([
            'employee_id' => $employeeId,
            'name' => 'First Login Member',
            'password' => 'legacy-employee-password',
            'roster_status' => 'active',
        ]);
    }
}
