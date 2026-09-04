<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class ProvisionEmployeeBootstrapCredentialTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['security.employee_bootstrap.secret' => 'test-owner-approved-bootstrap']);
    }

    public function test_dry_run_reports_counts_without_mutating_an_eligible_unlinked_employee(): void
    {
        $employee = $this->employee('BOOTSTRAP-DRY');
        $originalHash = $employee->getRawOriginal('password');

        $this->artisan('mbfd:provision-employee-bootstrap', [
            'employee_ids' => [(string) $employee->id],
            '--dry-run' => true,
        ])->assertSuccessful()
            ->expectsOutputToContain('TARGET_COUNT=1')
            ->expectsOutputToContain('WOULD_PROVISION=1')
            ->doesntExpectOutputToContain('test-owner-approved-bootstrap')
            ->doesntExpectOutputToContain('BOOTSTRAP-DRY');

        $employee->refresh();
        $this->assertSame($originalHash, $employee->getRawOriginal('password'));
        $this->assertFalse($employee->must_change_password);
    }

    public function test_apply_is_idempotent_and_does_not_create_users_or_assign_privileges(): void
    {
        $employee = $this->employee('BOOTSTRAP-APPLY');
        $userCount = User::query()->count();

        $this->artisan('mbfd:provision-employee-bootstrap', [
            'employee_ids' => [(string) $employee->id],
        ])->assertSuccessful()
            ->expectsOutputToContain('PROVISIONED=1');

        $employee->refresh();
        $firstHash = $employee->getRawOriginal('password');
        $this->assertTrue(Hash::check('test-owner-approved-bootstrap', $employee->getAuthPassword()));
        $this->assertTrue($employee->must_change_password);
        $this->assertSame($userCount, User::query()->count());

        $this->artisan('mbfd:provision-employee-bootstrap', [
            'employee_ids' => [(string) $employee->id],
        ])->assertSuccessful()
            ->expectsOutputToContain('ALREADY_READY=1')
            ->expectsOutputToContain('PROVISIONED=0');

        $this->assertSame($firstHash, $employee->fresh()->getRawOriginal('password'));
    }

    public function test_linked_or_identity_ambiguous_targets_are_refused_without_any_credential_change(): void
    {
        $linked = $this->employee('BOOTSTRAP-LINKED');
        $ambiguous = $this->employee('BOOTSTRAP-AMBIGUOUS');
        $linkedUser = User::factory()->create(['employee_id' => 'BOOTSTRAP-LINKED']);
        $linkedUser->forceFill(['employee_profile_id' => $linked->id])->save();
        $linkedUser->assignRole(Role::findOrCreate('super_admin', 'web'));
        User::factory()->create(['employee_id' => 'BOOTSTRAP-AMBIGUOUS']);
        $linkedHash = $linked->getRawOriginal('password');
        $ambiguousHash = $ambiguous->getRawOriginal('password');

        $this->artisan('mbfd:provision-employee-bootstrap', [
            'employee_ids' => [(string) $linked->id, (string) $ambiguous->id],
        ])->assertFailed()
            ->expectsOutputToContain('REFUSED_TARGETS=2');

        $this->assertSame($linkedHash, $linked->fresh()->getRawOriginal('password'));
        $this->assertSame($ambiguousHash, $ambiguous->fresh()->getRawOriginal('password'));
        $this->assertTrue($linkedUser->fresh()->hasRole('super_admin'));
    }

    public function test_missing_secret_fails_closed_without_mutation(): void
    {
        config(['security.employee_bootstrap.secret' => null]);
        $employee = $this->employee('BOOTSTRAP-NO-SECRET');
        $originalHash = $employee->getRawOriginal('password');

        $this->artisan('mbfd:provision-employee-bootstrap', [
            'employee_ids' => [(string) $employee->id],
        ])->assertFailed()
            ->expectsOutputToContain('NEED_SECURE_BOOTSTRAP_SECRET');

        $this->assertSame($originalHash, $employee->fresh()->getRawOriginal('password'));
    }

    private function employee(string $employeeId): Employee
    {
        return Employee::query()->create([
            'employee_id' => $employeeId,
            'name' => 'Bootstrap Test Employee',
            'rank' => 'Firefighter',
            'password' => 'opaque-existing-credential',
            'must_change_password' => false,
        ]);
    }
}
