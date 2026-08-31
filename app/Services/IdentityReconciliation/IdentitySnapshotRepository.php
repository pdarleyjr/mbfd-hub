<?php

declare(strict_types=1);

namespace App\Services\IdentityReconciliation;

use App\Data\IdentityReconciliation\EmployeeIdentity;
use App\Data\IdentityReconciliation\UserIdentity;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use stdClass;

/**
 * Read-only local database projection for identity preview input.
 *
 * This class deliberately uses only SELECT queries. It never saves an Eloquent
 * model, dispatches an event, or calls an external identity system.
 */
final class IdentitySnapshotRepository
{
    /** @return array{users: list<UserIdentity>, employees: list<EmployeeIdentity>} */
    public function snapshot(): array
    {
        return DB::transaction(function (): array {
            if (DB::connection()->getDriverName() === 'pgsql') {
                DB::statement('SET TRANSACTION READ ONLY');
            }

            return [
                'users' => $this->users(),
                'employees' => $this->employees(),
            ];
        });
    }

    /** @return list<UserIdentity> */
    private function users(): array
    {
        $roles = $this->rolesByUser();
        $directPermissions = $this->directPermissionsByUser();
        $rolePermissions = $this->rolePermissionsByUser();
        $workgroups = $this->workgroupsByUser();
        $notifications = $this->notificationRelationshipsByUser();

        /** @var list<stdClass> $records */
        $records = DB::table('users')
            ->select([
                'id',
                'employee_id',
                'employee_profile_id',
                'account_status',
                'security_version',
                'name',
                'email',
                'rank',
                'password',
                'must_change_password',
            ])
            ->orderBy('id')
            ->get()
            ->all();

        return array_map(function (stdClass $record) use ($roles, $directPermissions, $rolePermissions, $workgroups, $notifications): UserIdentity {
            $userRoles = $roles[(int) $record->id] ?? [];
            $direct = $directPermissions[(int) $record->id] ?? [];
            $effective = array_values(array_unique(array_merge($direct, $rolePermissions[(int) $record->id] ?? [])));
            sort($userRoles, SORT_STRING);
            sort($direct, SORT_STRING);
            sort($effective, SORT_STRING);

            $email = (string) $record->email;

            return new UserIdentity(
                id: (int) $record->id,
                legacyEmployeeId: $record->employee_id === null ? null : (string) $record->employee_id,
                employeeProfileId: $record->employee_profile_id === null ? null : (int) $record->employee_profile_id,
                accountStatus: $record->account_status === null ? null : (string) $record->account_status,
                securityVersion: (int) $record->security_version,
                name: (string) $record->name,
                email: $email,
                rank: $record->rank === null ? null : (string) $record->rank,
                passwordHash: $record->password === null ? null : (string) $record->password,
                mustChangePassword: (bool) $record->must_change_password,
                roles: $userRoles,
                directPermissions: $direct,
                effectivePermissions: $effective,
                workgroups: $workgroups[(int) $record->id] ?? [],
                notificationRelationships: $notifications[(int) $record->id] ?? [],
                externalMappings: [
                    [
                        'system' => 'snipe',
                        'mapping_status' => 'NOT_PERSISTED',
                        'numeric_id' => null,
                        'username' => strstr($email, '@', true) ?: $email,
                        'employee_num' => null,
                    ],
                    [
                        'system' => 'screentinker',
                        'mapping_status' => 'EMAIL_REFERENCE_ONLY',
                        'identifier' => $email,
                    ],
                ],
            );
        }, $records);
    }

    /** @return list<EmployeeIdentity> */
    private function employees(): array
    {
        /** @var list<stdClass> $records */
        $records = DB::table('employees')
            ->select([
                'id',
                'employee_id',
                'name',
                'rank',
                'password',
                'must_change_password',
            ])
            ->orderBy('id')
            ->get()
            ->all();

        return array_map(static fn (stdClass $record): EmployeeIdentity => new EmployeeIdentity(
            id: (int) $record->id,
            employeeId: (string) $record->employee_id,
            name: (string) $record->name,
            rank: $record->rank === null ? null : (string) $record->rank,
            passwordHash: $record->password === null ? null : (string) $record->password,
            mustChangePassword: (bool) $record->must_change_password,
            externalMappings: [[
                'system' => 'bid',
                'mapping_status' => 'EMPLOYEE_ID_REFERENCE_ONLY',
                'identifier' => (string) $record->employee_id,
            ]],
        ), $records);
    }

    /** @return array<int, list<string>> */
    private function rolesByUser(): array
    {
        if (! Schema::hasTable('model_has_roles') || ! Schema::hasTable('roles')) {
            return [];
        }

        return $this->groupStrings(
            DB::table('model_has_roles as assignment')
                ->join('roles', 'roles.id', '=', 'assignment.role_id')
                ->where('assignment.model_type', User::class)
                ->select(['assignment.model_id as user_id', 'roles.name as value'])
                ->orderBy('assignment.model_id')
                ->orderBy('roles.name')
                ->get()
                ->all(),
        );
    }

    /** @return array<int, list<string>> */
    private function directPermissionsByUser(): array
    {
        if (! Schema::hasTable('model_has_permissions') || ! Schema::hasTable('permissions')) {
            return [];
        }

        return $this->groupStrings(
            DB::table('model_has_permissions as assignment')
                ->join('permissions', 'permissions.id', '=', 'assignment.permission_id')
                ->where('assignment.model_type', User::class)
                ->select(['assignment.model_id as user_id', 'permissions.name as value'])
                ->orderBy('assignment.model_id')
                ->orderBy('permissions.name')
                ->get()
                ->all(),
        );
    }

    /** @return array<int, list<string>> */
    private function rolePermissionsByUser(): array
    {
        if (! Schema::hasTable('model_has_roles')
            || ! Schema::hasTable('role_has_permissions')
            || ! Schema::hasTable('permissions')) {
            return [];
        }

        return $this->groupStrings(
            DB::table('model_has_roles as assignment')
                ->join('role_has_permissions', 'role_has_permissions.role_id', '=', 'assignment.role_id')
                ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
                ->where('assignment.model_type', User::class)
                ->select(['assignment.model_id as user_id', 'permissions.name as value'])
                ->distinct()
                ->orderBy('assignment.model_id')
                ->orderBy('permissions.name')
                ->get()
                ->all(),
        );
    }

    /** @return array<int, list<array{id: int, name: string, role: string, active: bool}>> */
    private function workgroupsByUser(): array
    {
        if (! Schema::hasTable('workgroup_members') || ! Schema::hasTable('workgroups')) {
            return [];
        }

        $grouped = [];
        $rows = DB::table('workgroup_members as membership')
            ->join('workgroups', 'workgroups.id', '=', 'membership.workgroup_id')
            ->select([
                'membership.user_id',
                'workgroups.id',
                'workgroups.name',
                'membership.role',
                'membership.is_active',
            ])
            ->orderBy('membership.user_id')
            ->orderBy('workgroups.id')
            ->get();

        foreach ($rows as $row) {
            $grouped[(int) $row->user_id][] = [
                'id' => (int) $row->id,
                'name' => (string) $row->name,
                'role' => (string) $row->role,
                'active' => (bool) $row->is_active,
            ];
        }

        return $grouped;
    }

    /** @return array<int, array<string, int>> */
    private function notificationRelationshipsByUser(): array
    {
        $grouped = [];
        if (Schema::hasTable('notifications')) {
            $rows = DB::table('notifications')
                ->where('notifiable_type', User::class)
                ->select(['notifiable_id', DB::raw('COUNT(*) as aggregate')])
                ->groupBy('notifiable_id')
                ->orderBy('notifiable_id')
                ->get();
            foreach ($rows as $row) {
                $grouped[(int) $row->notifiable_id]['database_notifications'] = (int) $row->aggregate;
            }
        }

        if (Schema::hasTable('push_subscriptions')) {
            $rows = DB::table('push_subscriptions')
                ->where('subscribable_type', User::class)
                ->select(['subscribable_id', DB::raw('COUNT(*) as aggregate')])
                ->groupBy('subscribable_id')
                ->orderBy('subscribable_id')
                ->get();
            foreach ($rows as $row) {
                $grouped[(int) $row->subscribable_id]['push_subscriptions'] = (int) $row->aggregate;
            }
        }

        ksort($grouped);

        return $grouped;
    }

    /**
     * @param  list<stdClass>  $rows
     * @return array<int, list<string>>
     */
    private function groupStrings(array $rows): array
    {
        $grouped = [];
        foreach ($rows as $row) {
            $grouped[(int) $row->user_id][] = (string) $row->value;
        }

        return $grouped;
    }
}
