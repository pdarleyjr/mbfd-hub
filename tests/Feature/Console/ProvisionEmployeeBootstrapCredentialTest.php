<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ProvisionEmployeeBootstrapCredentialTest extends TestCase
{
    use RefreshDatabase;

    public function test_retired_bootstrap_command_fails_closed_without_mutating_credentials_or_creating_users(): void
    {
        $employee = Employee::query()->create([
            'employee_id' => 'RETIRED-COMMAND-1',
            'name' => 'Retired Command Member',
            'password' => 'existing-compatibility-password',
            'roster_status' => 'active',
        ]);
        $before = $employee->getRawOriginal();

        foreach ([false, true] as $dryRun) {
            $arguments = ['employee_ids' => [(string) $employee->id]];
            if ($dryRun) {
                $arguments['--dry-run'] = true;
            }

            $this->artisan('mbfd:provision-employee-bootstrap', $arguments)
                ->assertFailed()
                ->expectsOutputToContain('EMPLOYEE_BOOTSTRAP_LOGIN_RETIRED');
        }

        $after = $employee->fresh()->getRawOriginal();
        foreach ($before as $key => $value) {
            self::assertSame($value, $after[$key]);
        }
        self::assertSame(0, User::query()->count());
    }
}
