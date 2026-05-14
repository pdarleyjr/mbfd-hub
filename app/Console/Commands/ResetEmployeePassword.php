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
                            {--password= : New password (defaults to config employee.default_temp_password / env EMPLOYEE_DEFAULT_TEMP_PASSWORD)}
                            {--all : Reset ALL employee passwords}';

    protected $description = 'Reset an employee portal password and force password change on next login';

    public function handle(): int
    {
        if ($this->option('all')) {
            return $this->resetAll();
        }

        $employeeId = $this->argument('employee_id');
        $password = $this->resolvePassword();

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

    private function resolvePassword(): string
    {
        $password = $this->option('password')
            ?: config('employee.default_temp_password', env('EMPLOYEE_DEFAULT_TEMP_PASSWORD', ''));

        if ($password === '') {
            $this->error('No password specified. Pass --password=<value> or set config employee.default_temp_password / env EMPLOYEE_DEFAULT_TEMP_PASSWORD.');
            exit(Command::FAILURE);
        }

        return $password;
    }

    private function resetAll(): int
    {
        $password = $this->resolvePassword();

        if (! $this->confirm("Reset ALL employee passwords to the configured default?")) {
            $this->info('Cancelled.');
            return Command::SUCCESS;
        }

        $count = Employee::count();
        $hashed = Hash::make($password);

        Employee::query()->update([
            'password' => $hashed,
            'must_change_password' => true,
        ]);

        $this->info("Reset {$count} employee passwords to the configured default (not displayed)");
        $this->warn('All employees will be required to change password on next login.');

        return Command::SUCCESS;
    }
}
