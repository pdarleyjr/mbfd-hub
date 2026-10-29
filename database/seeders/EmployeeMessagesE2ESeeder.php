<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Synthetic personas for the Employee <-> Logistics messaging browser suite. Testing database only.
 *
 *   99301 Casey Alpha      member A
 *   99302 Jordan Bravo     member B
 *   99303 Riley Logistics  logistics_admin + admin.access (+ admin.communications.view for the separate Inbound Email check)
 *   99304 Vic Viewer       admin.access + admin.employee_messages.view (read only)
 *   99305 Uma Unrelated    admin.access + admin.communications.view (no messaging capability)
 *
 * Every persona has an active personnel profile so each can sign in through the same Employee ID form
 * members use. One random password per run comes from the environment and is never written down.
 */
final class EmployeeMessagesE2ESeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('testing') || config('database.default') !== 'sqlite'
            || basename((string) config('database.connections.sqlite.database')) !== 'employee_messages_e2e.sqlite') {
            throw new RuntimeException('Employee message browser fixtures require their disposable testing database.');
        }
        $password = env('EMPLOYEE_MESSAGES_E2E_PASSWORD');
        if (! is_string($password) || strlen($password) < 16) {
            throw new RuntimeException('The browser test password must be supplied.');
        }

        $memberRole = Role::findOrCreate('member', 'web');
        $logisticsRole = Role::findOrCreate('logistics_admin', 'web');

        $personas = [
            ['99301', 'Casey Alpha', 'Firefighter', [$memberRole], []],
            ['99302', 'Jordan Bravo', 'Firefighter', [$memberRole], []],
            ['99303', 'Riley Logistics', 'Lieutenant', [$logisticsRole], ['admin.access', 'admin.communications.view']],
            ['99304', 'Vic Viewer', 'Captain', [$memberRole], ['admin.access', 'admin.employee_messages.view']],
            ['99305', 'Uma Unrelated', 'Captain', [$memberRole], ['admin.access', 'admin.communications.view']],
        ];

        foreach ($personas as [$number, $name, $rank, $roles, $permissions]) {
            $employee = Employee::query()->updateOrCreate(
                ['employee_id' => $number],
                ['name' => $name, 'rank' => $rank, 'city_email' => "messages-fixture-{$number}@miamibeachfl.gov", 'roster_status' => 'active'],
            );
            $user = User::query()->firstOrNew(['employee_id' => $number]);
            $user->forceFill([
                'name' => $name,
                'email' => $employee->city_email,
                'password' => Hash::make($password),
                'must_change_password' => false,
                'employee_id' => $number,
                'employee_profile_id' => $employee->id,
                'account_status' => 'active',
            ])->save();
            $user->forceFill(['email_verified_at' => now()])->save();
            $user->syncRoles($roles);
            foreach ($permissions as $permission) {
                $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
            }
        }
    }
}
