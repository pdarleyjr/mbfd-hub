<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class ProvisionUsers extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mbfd:provision-users';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Provision MBFD users with default roles';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        // Create roles if they don't exist
        $adminRole = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $staffRole = Role::firstOrCreate(['name' => 'staff', 'guard_name' => 'web']);

        $this->info('Roles created/validated.');

        // Define users
        $users = [
            [
                'email' => 'MiguelAnchia@miamibeachfl.gov',
                'password' => env('DEFAULT_ADMIN_PASSWORD', 'changeme'),
                'name' => 'Miguel Anchia',
                'role' => 'admin',
            ],
            [
                'email' => 'RichardQuintela@miamibeachfl.gov',
                'password' => env('DEFAULT_ADMIN_PASSWORD', 'changeme'),
                'name' => 'Richard Quintela',
                'role' => 'admin',
            ],
            [
                'email' => 'PeterDarley@miamibeachfl.gov',
                'password' => env('DEFAULT_ADMIN_PASSWORD', 'changeme'),
                'name' => 'Peter Darley',
                'role' => 'admin',
            ],
            [
                'email' => 'geralddeyoung@miamibeachfl.gov',
                'password' => env('DEFAULT_ADMIN_PASSWORD', 'changeme'),
                'name' => 'Gerald DeYoung',
                'role' => 'staff',
            ],
        ];

        foreach ($users as $userData) {
            $user = User::updateOrCreate(
                ['email' => $userData['email']],
                [
                    'name' => $userData['name'],
                    'password' => Hash::make($userData['password']),
                    'must_change_password' => true, // Force password change for provisioned users
                ]
            );

            // Assign role
            $roleName = $userData['role'];
            if (!$user->hasRole($roleName)) {
                $user->assignRole($roleName);
            }

            $this->info("User {$user->email} provisioned with role: {$roleName} (must change password)");
        }

        $this->info('All users provisioned successfully!');
    }
}
