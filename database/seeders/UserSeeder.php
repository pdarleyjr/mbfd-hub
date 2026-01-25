<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $users = [
            ['email' => 'PeterDarley@miamibeachfl.gov', 'name' => 'Peter Darley', 'password' => Hash::make('Penco3')],
            ['email' => 'RichardQuintela@miamibeachfl.gov', 'name' => 'Richard Quintela', 'password' => Hash::make('Penco2')],
            ['email' => 'MiguelAnchia@miamibeachfl.gov', 'name' => 'Miguel Anchia', 'password' => Hash::make('Penco1')],
            ['email' => 'geralddeyoung@miamibeachfl.gov', 'name' => 'Gerald DeYoung', 'password' => Hash::make('MBFDGerry1')],
        ];

        foreach ($users as $userData) {
            User::updateOrCreate(
                ['email' => $userData['email']],
                $userData
            );
        }
        
        $this->command->info('Users created successfully!');
    }
}
