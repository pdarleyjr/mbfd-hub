<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PersonnelImportSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_reimport_updates_profile_without_resetting_existing_password(): void
    {
        $employee = Employee::create([
            'employee_id' => '20731',
            'name' => 'Existing Name',
            'rank' => 'Firefighter',
            'password' => 'existing-private-password',
            'must_change_password' => false,
        ]);
        $originalHash = $employee->password;

        $path = tempnam(sys_get_temp_dir(), 'mbfd-personnel-');
        file_put_contents($path, "Name,Rank,Employee ID\nUpdated Name,Lieutenant,20731\n");

        try {
            $this->artisan('mbfd:import-personnel', [
                'file' => $path,
            ])->assertSuccessful();
        } finally {
            @unlink($path);
        }

        $employee->refresh();
        $this->assertSame('Updated Name', $employee->name);
        $this->assertSame('Lieutenant', $employee->rank);
        $this->assertSame($originalHash, $employee->password);
        $this->assertFalse($employee->must_change_password);
    }

    public function test_new_employee_import_requires_a_dedicated_credentials_output_file(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'mbfd-personnel-');
        file_put_contents($path, "Name,Rank,Employee ID\nNew Employee,Firefighter,30001\n");

        try {
            $this->artisan('mbfd:import-personnel', ['file' => $path])->assertFailed();
        } finally {
            @unlink($path);
        }

        $this->assertDatabaseCount('employees', 0);
    }

    public function test_new_employee_receives_a_unique_generated_temporary_password(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'mbfd-personnel-');
        $output = sys_get_temp_dir().DIRECTORY_SEPARATOR.'mbfd-credentials-'.uniqid().'.csv';
        file_put_contents($path, "Name,Rank,Employee ID\nNew Employee,Firefighter,30001\n");

        try {
            $this->artisan('mbfd:import-personnel', [
                'file' => $path,
                '--credentials-output' => $output,
            ])->assertSuccessful();

            $rows = array_map('str_getcsv', file($output, FILE_IGNORE_NEW_LINES));
            $this->assertSame(['employee_id', 'temporary_password'], $rows[0]);
            $this->assertSame('30001', $rows[1][0]);
            $this->assertGreaterThanOrEqual(20, strlen($rows[1][1]));

            $employee = Employee::where('employee_id', '30001')->sole();
            $this->assertTrue(password_verify($rows[1][1], $employee->password));
            $this->assertTrue($employee->must_change_password);
        } finally {
            @unlink($path);
            @unlink($output);
        }
    }
}
