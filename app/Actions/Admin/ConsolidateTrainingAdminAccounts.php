<?php

namespace App\Actions\Admin;

use App\Models\User;
use Spatie\Permission\Models\Role;

final class ConsolidateTrainingAdminAccounts
{
    public function handle(): int
    {
        $adminRole = Role::firstOrCreate([
            'name' => 'admin',
            'guard_name' => 'web',
        ]);

        $updated = 0;

        User::query()
            ->role(['training_admin', 'training_viewer'])
            ->each(function (User $user) use ($adminRole, &$updated): void {
                if ($user->hasRole($adminRole)) {
                    return;
                }

                $user->assignRole($adminRole);
                $updated++;
            });

        return $updated;
    }
}
