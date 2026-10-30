<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\CityEmailVerification;
use App\Models\Employee;
use App\Models\Station;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Spatie\Permission\Models\Role;

/**
 * Synthetic personas for the admin dashboards browser suite (tests/e2e/admin-dashboards.spec.ts).
 * Disposable testing database only; the issue rows themselves come from the test-only fixture
 * sources (tests/Support/Dashboards) that the suite adds to its own config cache.
 *
 *   99881 Dash Fixture Admin   super_admin
 *   99882 Dash Fixture Member  no admin access
 */
final class AdminDashboardsE2ESeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('testing') || config('database.default') !== 'sqlite'
            || basename((string) config('database.connections.sqlite.database')) !== 'admin_dashboards_e2e.sqlite') {
            throw new RuntimeException('Admin dashboard browser fixtures require the disposable admin_dashboards_e2e.sqlite database.');
        }
        $password = env('ADMIN_DASHBOARDS_E2E_PASSWORD');
        if (! is_string($password) || strlen($password) < 24) {
            throw new RuntimeException('A random ephemeral fixture password is required.');
        }

        foreach ([['99881', 'Dash Fixture Admin', true], ['99882', 'Dash Fixture Member', false]] as [$number, $name, $admin]) {
            $employee = Employee::query()->create([
                'employee_id' => $number, 'name' => $name, 'rank' => $admin ? 'Captain' : 'Firefighter',
                'roster_status' => 'active', 'city_email' => "dash-fixture-{$number}@example.test",
                'password' => Hash::make(bin2hex(random_bytes(32))), 'must_change_password' => false,
            ]);
            $user = new User;
            $user->forceFill([
                'name' => $name, 'email' => $employee->city_email, 'email_verified_at' => now(),
                'employee_id' => $number, 'employee_profile_id' => $employee->id,
                'password' => Hash::make($password), 'must_change_password' => false,
                'account_status' => 'active', 'is_admin' => $admin,
            ])->save();
            if ($admin) {
                $user->assignRole(Role::findOrCreate('super_admin', 'web'));
            }
            CityEmailVerification::query()->create([
                'user_id' => $user->id, 'employee_profile_id' => $employee->id,
                'email' => "dash-fixture-{$number}-disposable@miamibeachfl.gov", 'original_user_email' => $user->email,
                'original_employee_city_email' => $employee->city_email,
                'security_version' => $user->fresh()->security_version,
                'acknowledged_at' => now(), 'delivery_status' => 'pending',
            ]);
        }

        // The Logistics Board's station widget needs at least one station to render its grid.
        Station::query()->create(['station_number' => 1, 'name' => 'Station 1', 'address' => '1 Fixture Way', 'city' => 'Miami Beach', 'state' => 'FL', 'zip_code' => '33139', 'is_active' => true]);
    }
}
