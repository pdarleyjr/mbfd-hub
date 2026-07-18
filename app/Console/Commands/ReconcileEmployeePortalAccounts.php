<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Employee\ReconcileEmployeePortalAccounts as ReconcileAccounts;
use App\Models\Employee;
use Illuminate\Console\Command;
use InvalidArgumentException;

final class ReconcileEmployeePortalAccounts extends Command
{
    protected $signature = 'mbfd:reconcile-employee-portal
                            {file=scripts/mbfd-personnel.csv : Canonical employee roster CSV}
                            {--password= : Shared portal password; falls back to EMPLOYEE_DEFAULT_TEMP_PASSWORD}
                            {--dry-run : Report roster and missing-account counts without changing data}
                            {--force : Apply without an interactive confirmation}';

    protected $description = 'Provision every canonical employee ID and make each account ready for Employee Portal and Forms login';

    public function handle(ReconcileAccounts $reconcile): int
    {
        $sourcePath = $this->resolveSourcePath((string) $this->argument('file'));

        try {
            $roster = $reconcile->readRoster($sourcePath);
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $existingIds = Employee::query()->whereIn('employee_id', array_keys($roster))->pluck('employee_id');
        $missing = count($roster) - $existingIds->count();
        $this->info(sprintf('Canonical roster: %d accounts; existing: %d; missing: %d.', count($roster), $existingIds->count(), $missing));

        if ($this->option('dry-run')) {
            $this->info('[DRY RUN] No employee accounts were changed.');

            return self::SUCCESS;
        }

        $password = (string) ($this->option('password') ?: config('employee.default_temp_password', ''));
        if ($password === '') {
            $this->error('No portal password supplied. Use --password or configure EMPLOYEE_DEFAULT_TEMP_PASSWORD.');

            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->confirm('Reconcile every roster account and reset all roster passwords?')) {
            $this->info('Cancelled.');

            return self::SUCCESS;
        }

        $result = $reconcile->handle($sourcePath, $password);

        $this->info(sprintf(
            'Employee Portal reconciliation complete: %d created, %d updated, %d total login accounts in the canonical roster.',
            $result['created'],
            $result['updated'],
            $result['total'],
        ));
        $this->info('All reconciled accounts can proceed directly to the Employee Portal and Forms without a forced password-change redirect.');

        return self::SUCCESS;
    }

    private function resolveSourcePath(string $sourcePath): string
    {
        if (str_starts_with($sourcePath, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $sourcePath) === 1) {
            return $sourcePath;
        }

        return base_path($sourcePath);
    }
}
