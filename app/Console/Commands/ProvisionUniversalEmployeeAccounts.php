<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Employee;
use App\Services\Identity\CanonicalUserProvisioner;
use App\Services\Identity\UniversalAccountInventory;
use Illuminate\Console\Command;
use Throwable;

final class ProvisionUniversalEmployeeAccounts extends Command
{
    protected $signature = 'identity:provision-universal-accounts
                            {--apply : Create/link eligible canonical accounts}
                            {--confirm= : Must equal PROVISION_ACTIVE_EMPLOYEE_ACCOUNTS when applying}
                            {--format=table : table or json}';

    protected $description = 'Inventory or idempotently provision one canonical User for every eligible active Employee.';

    public function handle(UniversalAccountInventory $inventory, CanonicalUserProvisioner $provisioner): int
    {
        $before = $inventory->report();
        if (! $this->option('apply')) {
            $this->render($before);
            if ($this->option('format') !== 'json') {
                $this->warn('DRY RUN ONLY. No users, credentials, roles, permissions, or links were changed.');
            }

            return self::SUCCESS;
        }
        if ($this->option('confirm') !== 'PROVISION_ACTIVE_EMPLOYEE_ACCOUNTS') {
            $this->error('Apply blocked: --confirm=PROVISION_ACTIVE_EMPLOYEE_ACCOUNTS is required.');

            return self::FAILURE;
        }
        if (($before['summary']['identity_conflicts'] ?? 0) !== 0) {
            $this->error('Apply blocked: identity conflicts require administrator review.');
            $this->render($before);

            return self::FAILURE;
        }

        $created = 0;
        $memberRolesAdded = 0;
        try {
            foreach ($before['rows'] as $row) {
                if ($row['roster_status'] !== 'active') {
                    continue;
                }
                /** @var Employee $employee */
                $employee = Employee::query()->findOrFail($row['employee_profile_id']);
                if ($row['classification'] === 'ROSTER_ONLY_NEEDS_USER') {
                    $result = $provisioner->create($employee->id, 'MISSING_OR_UNSUPPORTED', now());
                    $created += $result['created'] ? 1 : 0;
                    $memberRolesAdded += $result['member_role_added'] ? 1 : 0;

                    continue;
                }
                // Every pre-existing User is immutable in this operation.
            }
        } catch (Throwable $exception) {
            report($exception);
            $this->error('Provisioning stopped safely: '.$exception->getMessage());

            return self::FAILURE;
        }

        $after = $inventory->report();
        $after['apply'] = compact('created', 'memberRolesAdded');
        $this->render($after);

        return ($after['summary']['identity_conflicts'] ?? 0) === 0
            && ($after['summary']['active_employees_without_canonical_user'] ?? 1) === 0
            ? self::SUCCESS : self::FAILURE;
    }

    /** @param array<string, mixed> $report */
    private function render(array $report): void
    {
        if ($this->option('format') === 'json') {
            $this->line(json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return;
        }
        $this->table(['Classification', 'Count'], collect($report['summary'])
            ->filter(fn (mixed $value): bool => is_int($value))
            ->map(fn (int $value, string $key): array => [$key, $value])->values()->all());
    }
}
