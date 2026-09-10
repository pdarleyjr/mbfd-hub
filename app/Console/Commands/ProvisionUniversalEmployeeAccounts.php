<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\User;
use App\Services\Identity\AccountSecurityService;
use App\Services\Identity\CanonicalUserProvisioner;
use App\Services\Identity\UniversalAccountInventory;
use Illuminate\Console\Command;
use Spatie\Permission\Models\Role;
use Throwable;

final class ProvisionUniversalEmployeeAccounts extends Command
{
    protected $signature = 'identity:provision-universal-accounts
                            {--apply : Create/link eligible canonical accounts}
                            {--confirm= : Must equal PROVISION_ACTIVE_EMPLOYEE_ACCOUNTS when applying}
                            {--format=table : table or json}';

    protected $description = 'Inventory or idempotently provision one canonical User for every eligible active Employee.';

    public function handle(UniversalAccountInventory $inventory, CanonicalUserProvisioner $provisioner, AccountSecurityService $security): int
    {
        $before = $inventory->report();
        if (! $this->option('apply')) {
            $this->render($before);
            $this->warn('DRY RUN ONLY. No users, credentials, roles, permissions, or links were changed.');

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
        $reconciled = 0;
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
                if ($row['classification'] === 'EXACT_LEGACY_USER_NEEDS_LINK') {
                    /** @var User $user */
                    $user = User::query()->findOrFail($row['canonical_user_id']);
                    $security->completeCanonicalLink($user, $employee->id, $employee->employee_id, null, now(), false);
                    if (! $user->fresh()->hasRole('member')) {
                        $user->assignRole(Role::findOrCreate('member', 'web'));
                        $memberRolesAdded++;
                    }
                    $reconciled++;

                    continue;
                }
                if ($row['classification'] === 'EXISTING_CANONICAL_USER') {
                    /** @var User $user */
                    $user = User::query()->findOrFail($row['canonical_user_id']);
                    if (! $user->hasRole('member')) {
                        $user->assignRole(Role::findOrCreate('member', 'web'));
                        $memberRolesAdded++;
                    }
                }
            }
        } catch (Throwable $exception) {
            report($exception);
            $this->error('Provisioning stopped safely: '.$exception->getMessage());

            return self::FAILURE;
        }

        $after = $inventory->report();
        $after['apply'] = compact('created', 'reconciled', 'memberRolesAdded');
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
