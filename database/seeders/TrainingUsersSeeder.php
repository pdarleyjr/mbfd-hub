<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class TrainingUsersSeeder extends Seeder
{
    public function run(): void
    {
        // Ensure roles and permissions exist first
        $this->call(RolesAndPermissionsSeeder::class);

        // Training users — emails are lowercased for case-insensitive matching
        $users = [
            [
                'name' => 'Claudio Navas',
                'email' => 'claudionavas@miamibeachfl.gov',
                'password' => env('DEFAULT_TRAINING_PASSWORD', 'changeme'),
                'roles' => ['training_admin'],
            ],
            [
                'name' => 'Daniel Gato',
                'email' => 'danielgato@miamibeachfl.gov',
                'password' => env('DEFAULT_TRAINING_PASSWORD', 'changeme'),
                'roles' => ['training_admin'],
            ],
            [
                'name' => 'Victor White',
                'email' => 'victorwhite@miamibeachfl.gov',
                'password' => env('DEFAULT_TRAINING_PASSWORD', 'changeme'),
                'roles' => ['training_admin'],
            ],
            [
                'name' => 'Michael Sica',
                'email' => 'michaelsica@miamibeachfl.gov',
                'password' => env('DEFAULT_TRAINING_PASSWORD', 'changeme'),
                'roles' => ['training_admin'],
            ],
        ];

        foreach ($users as $userData) {
            $user = User::firstOrCreate(
                ['email' => strtolower($userData['email'])],
                [
                    'name' => $userData['name'],
                    'password' => $userData['password'], // hashed by model cast
                ]
            );

            $user->syncRoles($userData['roles']);
        }
    }
}
