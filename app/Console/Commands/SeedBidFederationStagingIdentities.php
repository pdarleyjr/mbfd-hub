<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\AccountStatus;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

final class SeedBidFederationStagingIdentities extends Command
{
    protected $signature = 'bid:seed-federation-staging-identities';

    protected $description = 'Create or reset only the isolated Hub staging federation test identities.';

    public function handle(): int
    {
        if (! app()->environment('staging')) {
            $this->error('This command is restricted to APP_ENV=staging.');

            return self::FAILURE;
        }

        Role::findOrCreate('admin', 'web');
        Role::findOrCreate('workgroup_admin', 'web');

        $this->upsert('STG-BID-ADMIN', 'Staging Bid Administrator', 'admin', 'HUB_STAGING_BID_ADMIN_PASSWORD');
        $this->upsert('STG-BID-MEMBER', 'Staging Bid Member', null, 'HUB_STAGING_BID_MEMBER_PASSWORD');
        $this->upsert('STG-BID-WORKGROUP', 'Staging Bid Workgroup Administrator', 'workgroup_admin', 'HUB_STAGING_BID_WORKGROUP_PASSWORD');

        $this->info('Isolated Hub staging federation identities are ready. Passwords were not displayed.');

        return self::SUCCESS;
    }

    private function upsert(string $employeeId, string $name, ?string $role, string $passwordVariable): void
    {
        $password = (string) env($passwordVariable);

        if (strlen($password) < 20) {
            throw new \RuntimeException("{$passwordVariable} must be a staging-only password of at least 20 characters.");
        }

        $employee = Employee::query()->updateOrCreate(
            ['employee_id' => $employeeId],
            [
                'name' => $name,
                'rank' => 'Staging',
                'password' => Hash::make($password),
                'must_change_password' => false,
            ],
        );

        $user = User::query()->updateOrCreate(
            ['employee_profile_id' => $employee->getKey()],
            [
                'name' => $name,
                'employee_id' => $employeeId,
                'email' => strtolower($employeeId).'@staging.invalid',
                'password' => Hash::make($password),
                'account_status' => AccountStatus::Active,
                'security_version' => 1,
            ],
        );

        $user->syncRoles($role === null ? [] : [$role]);
    }
}
