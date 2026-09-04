<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Identity\EmployeeBootstrapCredentialProvisioner;
use Illuminate\Console\Command;
use RuntimeException;

final class ProvisionEmployeeBootstrapCredential extends Command
{
    protected $signature = 'mbfd:provision-employee-bootstrap
                            {employee_ids* : Exact Employee database primary keys}
                            {--dry-run : Inspect the exact targets without changing credentials}';

    protected $description = 'Provision the protected first-login bootstrap credential for exact eligible unlinked Employee profiles.';

    public function handle(EmployeeBootstrapCredentialProvisioner $provisioner): int
    {
        $rawIds = (array) $this->argument('employee_ids');
        $employeeIds = [];
        foreach ($rawIds as $rawId) {
            if (preg_match('/^[1-9][0-9]*$/', $rawId) !== 1) {
                $this->error('INVALID_EMPLOYEE_DATABASE_ID');

                return self::FAILURE;
            }

            $employeeIds[] = (int) $rawId;
        }

        try {
            $result = $provisioner->provision($employeeIds, (bool) $this->option('dry-run'));
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->line('DRY_RUN='.($this->option('dry-run') ? 'YES' : 'NO'));
        $this->line('TARGET_COUNT='.$result['target_count']);
        $this->line('ALREADY_READY='.$result['already_ready']);
        $this->line('WOULD_PROVISION='.$result['would_provision']);
        $this->line('PROVISIONED='.$result['provisioned']);
        $this->line('REFUSED_TARGETS='.count($result['refused_targets']));

        return $result['refused_targets'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
