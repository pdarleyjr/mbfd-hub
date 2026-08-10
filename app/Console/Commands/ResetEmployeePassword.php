<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Employee;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class ResetEmployeePassword extends Command
{
    protected $signature = 'mbfd:reset-employee-password
                            {employee_id : The Employee ID to reset}
                            {--password= : Unique temporary password for this employee (minimum 15 characters)}';

    protected $description = 'Reset an employee portal password and force password change on next login';

    public function handle(): int
    {
        $employeeId = $this->argument('employee_id');
        $password = $this->resolvePassword();
        if ($password === null) {
            return Command::FAILURE;
        }

        $employee = Employee::where('employee_id', $employeeId)->first();

        if (! $employee) {
            $this->error("Employee with ID {$employeeId} not found.");

            return Command::FAILURE;
        }

        $employee->update([
            'password' => Hash::make($password),
            'must_change_password' => true,
        ]);

        $this->info("Password reset for {$employee->name} (ID: {$employeeId})");
        $this->info('New password: (set; not displayed)');
        $this->warn('Employee will be required to change password on next login.');

        return Command::SUCCESS;
    }

    private function resolvePassword(): ?string
    {
        $password = (string) $this->option('password');

        if ($password === '') {
            $this->error('Pass a unique temporary password with --password=<value>. Shared default passwords and mass resets are not supported.');

            return null;
        }
        if (mb_strlen($password) < 15) {
            $this->error('Temporary passwords must contain at least 15 characters.');

            return null;
        }

        return $password;
    }
}
