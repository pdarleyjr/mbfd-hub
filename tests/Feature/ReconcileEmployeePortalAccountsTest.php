<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Employee\ReconcileEmployeePortalAccounts;
use App\Models\Employee;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ReconcileEmployeePortalAccountsTest extends TestCase
{
    use RefreshDatabase;

    public function test_canonical_roster_contains_every_active_employee_id_including_victor(): void
    {
        $rows = app(ReconcileEmployeePortalAccounts::class)
            ->readRoster(base_path('scripts/mbfd-personnel.csv'));

        $this->assertCount(237, $rows);
        $this->assertSame('Victor White', $rows['19545']['name']);
        $this->assertSame('Division Chief', $rows['19545']['rank']);
        $this->assertArrayNotHasKey('20734', $rows, 'Documented former employee Derek Lewis must not be provisioned.');
    }

    public function test_reconciliation_creates_missing_accounts_and_makes_every_roster_login_forms_ready(): void
    {
        Employee::query()->create([
            'employee_id' => '20731',
            'name' => 'Old Name',
            'rank' => 'Firefighter',
            'password' => 'old-password',
            'must_change_password' => true,
        ]);

        $source = $this->temporaryRoster(<<<'CSV'
Name,Rank,Employee ID
Peter Darley,Lieutenant,20731
Victor White,Division Chief,19545
CSV);

        try {
            $result = app(ReconcileEmployeePortalAccounts::class)->handle($source, 'Miamibeach!');
        } finally {
            @unlink($source);
        }

        $this->assertSame(['created' => 1, 'updated' => 1, 'total' => 2], $result);

        $employeePanel = Filament::getPanel('employee');

        foreach (Employee::query()->orderBy('employee_id')->get() as $employee) {
            $this->assertTrue(Hash::check('Miamibeach!', $employee->password));
            $this->assertFalse($employee->must_change_password);
            $this->assertTrue($employee->canAccessPanel($employeePanel));
            $this->assertTrue(auth('employee')->validate([
                'employee_id' => $employee->employee_id,
                'password' => 'Miamibeach!',
            ]));
        }

        $this->assertDatabaseHas('employees', [
            'employee_id' => '19545',
            'name' => 'Victor White',
            'rank' => 'Division Chief',
            'must_change_password' => false,
        ]);
    }

    public function test_reconciliation_rejects_duplicate_employee_ids_without_changing_accounts(): void
    {
        $source = $this->temporaryRoster(<<<'CSV'
Name,Rank,Employee ID
Victor White,Division Chief,19545
Duplicate Victor,Firefighter,19545
CSV);

        try {
            app(ReconcileEmployeePortalAccounts::class)->handle($source, 'Miamibeach!');
            $this->fail('A duplicate employee ID should reject the complete roster.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('Duplicate Employee ID 19545', $exception->getMessage());
        } finally {
            @unlink($source);
        }

        $this->assertDatabaseCount('employees', 0);
    }

    public function test_reconciliation_command_reports_the_final_account_total_without_printing_the_password(): void
    {
        $source = $this->temporaryRoster(<<<'CSV'
Name,Rank,Employee ID
Peter Darley,Lieutenant,20731
Victor White,Division Chief,19545
CSV);

        try {
            $this->artisan('mbfd:reconcile-employee-portal', [
                'file' => $source,
                '--password' => 'Miamibeach!',
                '--force' => true,
            ])
                ->expectsOutputToContain('2 created, 0 updated, 2 total login accounts')
                ->doesntExpectOutputToContain('Miamibeach!')
                ->assertSuccessful();
        } finally {
            @unlink($source);
        }

        $this->assertDatabaseCount('employees', 2);
    }

    private function temporaryRoster(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'mbfd-employee-roster-');
        file_put_contents($path, $contents.PHP_EOL);

        return $path;
    }
}
