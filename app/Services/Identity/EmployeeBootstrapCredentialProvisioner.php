<?php

declare(strict_types=1);

namespace App\Services\Identity;

use Illuminate\Support\Facades\Hash;
use RuntimeException;

final class EmployeeBootstrapCredentialProvisioner
{
    /** @return array{password: string, must_change_password: true} */
    public function attributesForNewEmployee(): array
    {
        return [
            'password' => Hash::make(bin2hex(random_bytes(48))),
            'must_change_password' => true,
        ];
    }

    /**
     * @param  list<int>  $employeeIds
     * @return array{target_count: int, already_ready: int, would_provision: int, provisioned: int, refused_targets: list<int>}
     */
    public function provision(array $employeeIds, bool $dryRun): array
    {
        throw new RuntimeException('EMPLOYEE_BOOTSTRAP_LOGIN_RETIRED');
    }
}
