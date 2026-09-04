<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\AssignedEquipment;
use App\Models\Employee;
use App\Models\Station;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Spatie\Permission\Models\Role;

class PersonnelRequestsE2ESeeder extends Seeder
{
    public function run(): void
    {
        $adminPassword = $this->requiredPassword('PERSONNEL_REQUESTS_E2E_ADMIN_PASSWORD');
        $officerPassword = $this->requiredPassword('PERSONNEL_REQUESTS_E2E_OFFICER_PASSWORD');
        $memberPassword = $this->requiredPassword('PERSONNEL_REQUESTS_E2E_MEMBER_PASSWORD');

        $role = Role::findOrCreate('logistics_admin', 'web');
        $memberRole = Role::findOrCreate('member', 'web');
        $adminEmployee = Employee::query()->updateOrCreate(
            ['employee_id' => '99003'],
            ['name' => 'Personnel E2E Admin', 'rank' => 'Captain', 'password' => Hash::make($adminPassword), 'must_change_password' => false],
        );
        $admin = User::query()->firstOrNew(['email' => 'personnel-admin@example.test']);
        $admin->forceFill([
            'name' => 'Personnel E2E Admin',
            'password' => Hash::make($adminPassword),
            'must_change_password' => false,
            'employee_id' => $adminEmployee->employee_id,
            'employee_profile_id' => $adminEmployee->id,
            'account_status' => 'active',
            'is_admin' => true,
        ])->save();
        $admin->syncRoles([$role]);

        $officer = Employee::query()->updateOrCreate(
            ['employee_id' => '99001'],
            ['name' => 'Avery Officer', 'rank' => 'Captain', 'password' => Hash::make($officerPassword), 'must_change_password' => false],
        );
        $member = Employee::query()->updateOrCreate(
            ['employee_id' => '99002'],
            ['name' => 'Morgan Member', 'rank' => 'Firefighter', 'password' => Hash::make($memberPassword), 'must_change_password' => false],
        );
        $officerUser = User::query()->firstOrNew(['email' => 'personnel-officer@example.test']);
        $officerUser->forceFill([
            'name' => $officer->name,
            'password' => Hash::make($officerPassword),
            'must_change_password' => false,
            'employee_id' => $officer->employee_id,
            'employee_profile_id' => $officer->id,
            'account_status' => 'active',
        ])->save();
        $officerUser->syncRoles([$memberRole]);
        $memberUser = User::query()->firstOrNew(['email' => 'personnel-member@example.test']);
        $memberUser->forceFill([
            'name' => $member->name,
            'password' => Hash::make($memberPassword),
            'must_change_password' => false,
            'employee_id' => $member->employee_id,
            'employee_profile_id' => $member->id,
            'account_status' => 'active',
        ])->save();
        $memberUser->syncRoles([$memberRole]);
        $station = Station::query()->updateOrCreate(
            ['station_number' => '1'],
            ['address' => '1051 Jefferson Avenue', 'zip_code' => '33139', 'is_active' => true],
        );

        AssignedEquipment::query()->create([
            'user_id' => null,
            'employee_portal_id' => $member->id,
            'category' => 'Personnel PPE',
            'item_description' => 'E2E Structural Firefighting Helmet',
            'quantity' => 1,
            'issued_at' => today()->subYear(),
            'expires_at' => today()->addDays(30),
            'status' => 'active',
            'notes' => "Browser fixture for Station {$station->station_number}; never used outside the disposable E2E database.",
        ]);
    }

    private function requiredPassword(string $name): string
    {
        $password = env($name);

        if (! is_string($password) || $password === '') {
            throw new RuntimeException("{$name} must be set for the disposable browser fixture.");
        }

        return $password;
    }
}
