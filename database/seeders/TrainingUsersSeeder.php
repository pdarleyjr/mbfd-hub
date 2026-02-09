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

        // Training users — emails are lowercased by the User model mutator
        $users = [
            [
                'name' => 'Miguel Anchia',
                'email' => 'MiguelAnchia@miamibeachfl.gov',
                'password' => 'Penco1',
                'roles' => ['training_admin', 'super_admin'],
            ],
            [
                'name' => 'Richard Quintela',
                'email' => 'RichardQuintela@miamibeachfl.gov',
                'password' => 'Penco2',
                'roles' => ['training_admin', 'super_admin'],
            ],
            [
                'name' => 'Peter Darley',
                'email' => 'PeterDarley@miamibeachfl.gov',
                'password' => 'Penco3',
                'roles' => ['training_admin', 'super_admin'],
            ],
            [
                'name' => 'Gerald DeYoung',
                'email' => 'geralddeyoung@miamibeachfl.gov',
                'password' => 'MBFDGerry1',
                'roles' => ['training_viewer'],
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
