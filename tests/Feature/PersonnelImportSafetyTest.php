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
            $this->artisan('mbfd:import-personnel', ['file' => $path])
                ->assertSuccessful()
                ->expectsOutputToContain('compatibility hash unchanged');
        } finally {
            @unlink($path);
        }

        $employee->refresh();
        $this->assertSame('Updated Name', $employee->name);
        $this->assertSame('Lieutenant', $employee->rank);
        $this->assertSame($originalHash, $employee->password);
        $this->assertFalse($employee->must_change_password);
    }

    public function test_new_employee_import_does_not_issue_an_employee_password(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'mbfd-personnel-');
        file_put_contents($path, "Name,Rank,Employee ID\nNew Employee,Firefighter,30001\n");

        try {
            $this->artisan('mbfd:import-personnel', ['file' => $path])
                ->assertSuccessful()
                ->doesntExpectOutputToContain('password');
        } finally {
            @unlink($path);
        }

        $employee = Employee::where('employee_id', '30001')->sole();
        $this->assertNotSame('', $employee->getRawOriginal('password'));
        $this->assertFalse($employee->must_change_password);
    }
}
