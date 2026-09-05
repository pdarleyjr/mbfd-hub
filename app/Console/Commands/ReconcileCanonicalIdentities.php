<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\User;
use App\Services\Identity\AccountSecurityService;
use App\Services\Identity\CanonicalCityEmailService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use JsonException;

final class ReconcileCanonicalIdentities extends Command
{
    protected $signature = 'mbfd:identity-reconcile {file : JSON array of exact user_email and employee_id mappings} {--apply}';

    protected $description = 'Dry-run or apply unambiguous canonical User-to-Employee links without changing roles or permissions';

    /** @throws JsonException */
    public function handle(AccountSecurityService $security, CanonicalCityEmailService $cityEmails): int
    {
        $path = (string) $this->argument('file');
        if (! is_file($path) || ! is_readable($path)) {
            throw new InvalidArgumentException('The identity mapping file is missing or unreadable.');
        }
        $mappings = json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        if (! is_array($mappings) || ! array_is_list($mappings)) {
            throw new InvalidArgumentException('The identity mapping file must be a JSON array.');
        }

        $resolved = [];
        $seenUsers = [];
        $seenEmployees = [];
        foreach ($mappings as $index => $mapping) {
            if (! is_array($mapping)
                || ! is_string($mapping['user_email'] ?? null)
                || ! is_string($mapping['employee_id'] ?? null)) {
                throw new InvalidArgumentException("Mapping {$index} is incomplete.");
            }
            $email = strtolower(trim($mapping['user_email']));
            $employeeId = trim($mapping['employee_id']);
            if (isset($seenUsers[$email]) || isset($seenEmployees[$employeeId])) {
                throw new InvalidArgumentException('The mapping contains a duplicate User or Employee ID.');
            }
            $seenUsers[$email] = true;
            $seenEmployees[$employeeId] = true;

            $user = User::query()->whereRaw('LOWER(email) = ?', [$email])->sole();
            $employee = Employee::query()->where('employee_id', $employeeId)->sole();
            if ($user->employee_profile_id !== null && (int) $user->employee_profile_id !== (int) $employee->id) {
                throw new InvalidArgumentException("User {$user->id} is already linked to a different Employee record.");
            }
            if ($employee->user !== null && ! $employee->user->is($user)) {
                throw new InvalidArgumentException("Employee ID {$employeeId} is already linked to a different User.");
            }
            $cityEmail = isset($mapping['city_email']) && is_string($mapping['city_email'])
                ? strtolower(trim($mapping['city_email']))
                : null;
            $resolved[] = ['user' => $user, 'employee' => $employee, 'city_email' => $cityEmail];
        }

        $this->table(['Mappings', 'Already linked', 'Would link'], [[
            count($resolved),
            collect($resolved)->filter(fn (array $row): bool => (int) $row['user']->employee_profile_id === (int) $row['employee']->id)->count(),
            collect($resolved)->filter(fn (array $row): bool => (int) $row['user']->employee_profile_id !== (int) $row['employee']->id)->count(),
        ]]);
        if (! $this->option('apply')) {
            $this->warn('DRY RUN ONLY. Roles, permissions, credentials, and identity links were not changed.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($resolved, $security, $cityEmails): void {
            foreach ($resolved as $row) {
                /** @var User $user */
                $user = $row['user'];
                /** @var Employee $employee */
                $employee = $row['employee'];
                $security->completeCanonicalLink(
                    $user,
                    (int) $employee->getKey(),
                    (string) $employee->employee_id,
                    $employee->getRawOriginal('password'),
                    now(),
                );
                if ($row['city_email'] !== null) {
                    $cityEmails->sync($employee, $user, $row['city_email']);
                }
            }
        });
        $this->info('Canonical links applied. Roles and permissions were not replaced; migrated credentials require a password change.');

        return self::SUCCESS;
    }
}
