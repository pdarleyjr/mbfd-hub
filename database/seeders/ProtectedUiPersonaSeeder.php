<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\CityEmailVerification;
use App\Models\Employee;
use App\Models\User;
use App\Models\Workgroup;
use App\Models\WorkgroupMember;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Read-only UI crawl personas for the disposable protected-UI database.
 * Runs after ProtectedUiE2ESeeder, whose super_admin (99871) is the super-admin persona.
 */
final class ProtectedUiPersonaSeeder extends Seeder
{
    /**
     * Officer status is rank-based (personnel_requests.officer_ranks), not a role.
     * Workgroup personas get panel access only through an active membership, never a global permission.
     *
     * @var array<string, array{employee_id: string, rank: string, roles: list<string>, workgroup_role?: string}>
     */
    private const PERSONAS = [
        'member' => ['employee_id' => '99872', 'rank' => 'Firefighter', 'roles' => ['member']],
        'officer' => ['employee_id' => '99873', 'rank' => 'Captain', 'roles' => ['member']],
        'employee-only' => ['employee_id' => '99874', 'rank' => 'Firefighter', 'roles' => []],
        'logistics-admin' => ['employee_id' => '99875', 'rank' => 'Lieutenant', 'roles' => ['logistics_admin']],
        'admin' => ['employee_id' => '99876', 'rank' => 'Captain', 'roles' => ['admin']],
        'workgroup-member' => ['employee_id' => '99877', 'rank' => 'Firefighter', 'roles' => ['member'], 'workgroup_role' => 'member'],
        'workgroup-facilitator' => ['employee_id' => '99879', 'rank' => 'Lieutenant', 'roles' => ['member'], 'workgroup_role' => 'facilitator'],
        'training-viewer' => ['employee_id' => '99878', 'rank' => 'Firefighter', 'roles' => ['training_viewer']],
        'training-admin' => ['employee_id' => '99880', 'rank' => 'Captain', 'roles' => ['training_admin']],
    ];

    /**
     * Fixture-only direct grants mirroring the current direct-permission model; applied to synthetic users only.
     * Application entitlements (app.bid.access, app.media_control.access) are listed explicitly per persona.
     *
     * @var array<string, list<string>>
     */
    private const PERMISSIONS = [
        'logistics-admin' => [
            'admin.access', 'admin.fleet.view', 'admin.fleet.manage', 'admin.stations.view', 'admin.stations.manage',
            'admin.equipment.view', 'admin.equipment.manage', 'admin.personnel.view', 'admin.personnel.manage',
            'admin.forms.view', 'admin.forms.manage', 'admin.projects.view', 'admin.projects.manage',
            'admin.notifications.view', 'admin.notifications.manage', 'app.media_control.access', 'app.bid.access',
        ],
        'admin' => [
            'admin.access', 'admin.members.view', 'admin.members.manage', 'admin.fleet.view', 'admin.fleet.manage',
            'admin.stations.view', 'admin.stations.manage', 'admin.equipment.view', 'admin.equipment.manage',
            'admin.personnel.view', 'admin.personnel.manage', 'admin.training.view', 'admin.training.manage',
            'admin.workgroups.view', 'admin.workgroups.manage', 'admin.forms.view', 'admin.forms.manage',
            'admin.projects.view', 'admin.projects.manage', 'admin.notifications.view', 'admin.notifications.manage',
            'admin.communications.view', 'admin.system.view', 'app.media_control.access', 'app.bid.access',
        ],
        'training-admin' => [
            'admin.access', 'admin.training.view', 'admin.training.manage', 'admin.notifications.view',
            'admin.notifications.manage', 'app.media_control.access', 'app.bid.access',
        ],
        'training-viewer' => ['app.bid.access'],
    ];

    public function run(): void
    {
        if (! app()->environment('testing') || config('database.default') !== 'sqlite'
            || basename((string) config('database.connections.sqlite.database')) !== 'protected_ui_e2e.sqlite') {
            throw new RuntimeException('Persona fixtures require the disposable protected_ui_e2e.sqlite database.');
        }
        $password = env('PROTECTED_UI_E2E_PASSWORD');
        if (! is_string($password) || strlen($password) < 24) {
            throw new RuntimeException('A random ephemeral fixture password is required.');
        }

        $manifest = ['super-admin' => '99871'];
        foreach (self::PERSONAS as $persona => $definition) {
            $user = $this->persona($persona, $definition, $password);
            $manifest[$persona] = $definition['employee_id'];
            if (isset($definition['workgroup_role'])) {
                $workgroup = Workgroup::query()->firstOrCreate(
                    ['name' => 'Local UI persona workgroup'],
                    ['is_active' => true, 'created_by' => $user->id],
                );
                WorkgroupMember::query()->create([
                    'workgroup_id' => $workgroup->id, 'user_id' => $user->id,
                    'role' => $definition['workgroup_role'], 'is_active' => true,
                ]);
            }
        }

        File::ensureDirectoryExists(base_path('test-results/protected-ui-auth'));
        File::put(base_path('test-results/protected-ui-auth/personas.json'), json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
    }

    /** @param array{employee_id: string, rank: string, roles: list<string>, workgroup_role?: string} $definition */
    private function persona(string $persona, array $definition, string $password): User
    {
        $email = "protected-ui-{$persona}@example.test";
        $employee = Employee::query()->create([
            'employee_id' => $definition['employee_id'], 'name' => 'Protected UI '.ucwords(str_replace('-', ' ', $persona)),
            'rank' => $definition['rank'], 'roster_status' => 'active', 'city_email' => $email,
            'password' => Hash::make(bin2hex(random_bytes(32))), 'must_change_password' => false,
        ]);
        $user = new User;
        $user->forceFill([
            'name' => $employee->name, 'email' => $email, 'email_verified_at' => now(),
            'employee_id' => $employee->employee_id, 'employee_profile_id' => $employee->id,
            'password' => Hash::make($password), 'must_change_password' => false,
            'account_status' => 'active', 'is_admin' => in_array('admin', $definition['roles'], true),
        ])->save();
        foreach ($definition['roles'] as $role) {
            $user->assignRole(Role::findOrCreate($role, 'web'));
        }
        foreach (self::PERMISSIONS[$persona] ?? [] as $name) {
            // Only grant permissions the migrated schema already defines; never invent one.
            $permission = Permission::query()->where('name', $name)->where('guard_name', 'web')->first()
                ?? throw new RuntimeException("Fixture permission {$name} is not defined by the migrated schema.");
            $user->givePermissionTo($permission);
        }
        // Same acknowledged-review fixture state as the super_admin persona; no mailbox is contacted.
        CityEmailVerification::query()->create([
            'user_id' => $user->id, 'employee_profile_id' => $employee->id,
            'email' => "protected-ui-{$persona}-fixture@miamibeachfl.gov", 'original_user_email' => $user->email,
            'original_employee_city_email' => $employee->city_email,
            'security_version' => $user->fresh()->security_version,
            'acknowledged_at' => now(), 'delivery_status' => 'pending',
        ]);

        return $user;
    }
}
