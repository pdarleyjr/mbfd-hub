<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** @var list<string> */
    private array $permissions = [
        'admin.access',
        'admin.members.view',
        'admin.members.manage',
        'admin.members.security',
        'admin.fleet.view',
        'admin.fleet.manage',
        'admin.stations.view',
        'admin.stations.manage',
        'admin.equipment.view',
        'admin.equipment.manage',
        'admin.personnel.view',
        'admin.personnel.manage',
        'admin.training.view',
        'admin.training.manage',
        'admin.workgroups.view',
        'admin.workgroups.manage',
        'admin.forms.view',
        'admin.forms.manage',
        'admin.projects.view',
        'admin.projects.manage',
        'admin.notifications.view',
        'admin.notifications.manage',
        'admin.communications.view',
        'admin.communications.send',
        'admin.system.view',
        'admin.system.manage',
        'app.media_control.access',
        'app.bid.access',
    ];

    public function up(): void
    {
        $now = now();
        foreach ($this->permissions as $name) {
            DB::table('permissions')->insertOrIgnore([
                'name' => $name,
                'guard_name' => 'web',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $all = $this->permissionIds($this->permissions);
        $this->grantRole('super_admin', $all);

        $this->backfillDirectPermissions(['admin'], array_values(array_filter(
            $this->permissions,
            fn (string $permission): bool => ! in_array($permission, [
                'admin.members.security',
                'admin.communications.send',
                'admin.system.manage',
            ], true),
        )));
        $this->backfillDirectPermissions(['logistics_admin'], [
            'admin.access', 'admin.fleet.view', 'admin.fleet.manage', 'admin.stations.view',
            'admin.stations.manage', 'admin.equipment.view', 'admin.equipment.manage',
            'admin.personnel.view', 'admin.personnel.manage', 'admin.forms.view',
            'admin.forms.manage', 'admin.projects.view', 'admin.projects.manage',
            'admin.notifications.view', 'admin.notifications.manage',
            'app.media_control.access', 'app.bid.access',
        ]);
        $this->backfillDirectPermissions(['training_admin'], [
            'admin.access', 'admin.training.view', 'admin.training.manage',
            'admin.notifications.view', 'admin.notifications.manage',
            'app.media_control.access', 'app.bid.access',
        ]);

        // Application entitlements are deliberately copied directly to each
        // existing user so later role changes cannot silently grant an app.
        $this->backfillDirectPermissions(
            ['admin', 'logistics_admin', 'training_admin'],
            ['app.media_control.access'],
        );
        $this->backfillDirectPermissions(
            ['admin', 'logistics_admin', 'training_admin', 'training_viewer'],
            ['app.bid.access'],
        );
        $this->backfillDirectPermissions(
            ['admin', 'logistics_admin', 'training_admin'],
            ['admin.access'],
        );

        $eventRoles = [
            'vehicle_inspections' => ['super_admin', 'logistics_admin'],
            'station_inspections' => ['super_admin', 'logistics_admin'],
            'fire_equipment_requests' => [],
            'station_requests' => ['super_admin', 'admin', 'logistics_admin'],
            'apparatus_service_tickets' => ['super_admin', 'admin', 'logistics_admin'],
            'workgroup_evaluations' => ['super_admin', 'workgroup_facilitator'],
            'station_inventory_alerts' => ['super_admin', 'logistics_admin'],
        ];
        foreach (DB::table('users')->select(['id', 'notification_preferences'])->get() as $user) {
            $preferences = json_decode((string) ($user->notification_preferences ?? '{}'), true);
            $preferences = is_array($preferences) ? $preferences : [];
            $roles = DB::table('model_has_roles')
                ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
                ->where('model_has_roles.model_type', 'App\\Models\\User')
                ->where('model_has_roles.model_id', $user->id)
                ->pluck('roles.name')
                ->all();
            foreach ($eventRoles as $eventKey => $eligibleRoles) {
                $enabled = array_intersect($roles, $eligibleRoles) !== []
                    && (bool) ($preferences[$eventKey] ?? true);
                DB::table('user_notification_subscriptions')->insertOrIgnore([
                    'user_id' => $user->id,
                    'event_key' => $eventKey,
                    'database_enabled' => $enabled,
                    'webpush_enabled' => $enabled,
                    'email_enabled' => false,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        // Permissions and grants are intentionally retained. Rolling back a
        // schema migration must never silently remove operator access.
    }

    /** @param list<string> $names @return list<int> */
    private function permissionIds(array $names): array
    {
        return DB::table('permissions')
            ->where('guard_name', 'web')
            ->whereIn('name', $names)
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    /** @param list<int> $permissionIds */
    private function grantRole(string $roleName, array $permissionIds): void
    {
        $roleId = DB::table('roles')->where('name', $roleName)->where('guard_name', 'web')->value('id');
        if ($roleId === null) {
            return;
        }

        foreach ($permissionIds as $permissionId) {
            DB::table('role_has_permissions')->insertOrIgnore([
                'permission_id' => $permissionId,
                'role_id' => $roleId,
            ]);
        }
    }

    /** @param list<string> $roles @param list<string> $permissions */
    private function backfillDirectPermissions(array $roles, array $permissions): void
    {
        $userIds = DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_type', 'App\\Models\\User')
            ->whereIn('roles.name', $roles)
            ->pluck('model_has_roles.model_id')
            ->unique();

        foreach ($userIds as $userId) {
            foreach ($this->permissionIds($permissions) as $permissionId) {
                DB::table('model_has_permissions')->insertOrIgnore([
                    'permission_id' => $permissionId,
                    'model_type' => 'App\\Models\\User',
                    'model_id' => $userId,
                ]);
            }
        }
    }
};
