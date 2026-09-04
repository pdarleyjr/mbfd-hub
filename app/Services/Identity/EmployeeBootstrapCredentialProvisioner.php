<?php

declare(strict_types=1);

namespace App\Services\Identity;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use RuntimeException;

final class EmployeeBootstrapCredentialProvisioner
{
    /** @return array{password: string, must_change_password: true} */
    public function attributesForNewEmployee(): array
    {
        return [
            'password' => Hash::make($this->secret()),
            'must_change_password' => true,
        ];
    }

    /**
     * @param  list<int>  $employeeIds
     * @return array{target_count: int, already_ready: int, would_provision: int, provisioned: int, refused_targets: list<int>}
     */
    public function provision(array $employeeIds, bool $dryRun): array
    {
        $secret = $this->secret();
        $employeeIds = array_values(array_unique($employeeIds));
        sort($employeeIds, SORT_NUMERIC);

        if ($employeeIds === []) {
            throw new RuntimeException('NO_EMPLOYEE_TARGETS');
        }

        return DB::transaction(function () use ($employeeIds, $dryRun, $secret): array {
            $query = Employee::query()->whereKey($employeeIds)->orderBy('id');
            if (! $dryRun) {
                $query->lockForUpdate();
            }

            /** @var list<Employee> $employees */
            $employees = $query->get()->all();
            $foundIds = array_map(static fn (Employee $employee): int => (int) $employee->id, $employees);
            $refusedTargets = array_values(array_diff($employeeIds, $foundIds));
            $ready = [];
            $needsProvisioning = [];

            foreach ($employees as $employee) {
                $employeeId = (string) $employee->employee_id;
                $identityIsInvalid = $employeeId === ''
                    || trim($employeeId) !== $employeeId
                    || Employee::query()->where('employee_id', $employeeId)->count() !== 1;
                $canonicalIdentityExists = User::query()
                    ->where('employee_profile_id', $employee->id)
                    ->orWhere('employee_id', $employeeId)
                    ->exists();

                if ($identityIsInvalid || $canonicalIdentityExists) {
                    $refusedTargets[] = (int) $employee->id;

                    continue;
                }

                if ($employee->must_change_password && Hash::check($secret, $employee->getAuthPassword())) {
                    $ready[] = $employee;

                    continue;
                }

                $needsProvisioning[] = $employee;
            }

            sort($refusedTargets, SORT_NUMERIC);
            if ($refusedTargets !== []) {
                return [
                    'target_count' => count($employeeIds),
                    'already_ready' => count($ready),
                    'would_provision' => count($needsProvisioning),
                    'provisioned' => 0,
                    'refused_targets' => $refusedTargets,
                ];
            }

            if (! $dryRun) {
                foreach ($needsProvisioning as $employee) {
                    $employee->forceFill([
                        'password' => Hash::make($secret),
                        'must_change_password' => true,
                    ])->save();
                }

                Log::notice('employee_bootstrap_credentials_provisioned', [
                    'employee_profile_ids' => array_map(
                        static fn (Employee $employee): int => (int) $employee->id,
                        $needsProvisioning,
                    ),
                    'target_count' => count($employeeIds),
                    'provisioned_count' => count($needsProvisioning),
                    'already_ready_count' => count($ready),
                ]);
            }

            return [
                'target_count' => count($employeeIds),
                'already_ready' => count($ready),
                'would_provision' => count($needsProvisioning),
                'provisioned' => $dryRun ? 0 : count($needsProvisioning),
                'refused_targets' => [],
            ];
        }, 3);
    }

    private function secret(): string
    {
        $secret = config('security.employee_bootstrap.secret');
        if (! is_string($secret) || $secret === '') {
            throw new RuntimeException('NEED_SECURE_BOOTSTRAP_SECRET');
        }

        return $secret;
    }
}
