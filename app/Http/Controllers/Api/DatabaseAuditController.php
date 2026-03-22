<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Employee;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Database Audit Controller
 * 
 * Provides secure access to user and employee account data for administrative auditing.
 * Only accessible to super_admin and admin roles.
 */
class DatabaseAuditController extends Controller
{
    /**
     * Ensure user has admin access.
     */
    public function __construct()
    {
        $this->middleware(function ($request, $next) {
            $user = $request->user();
            
            if (!$user) {
                return response()->json(['error' => 'Unauthenticated'], 401);
            }
            
            if (!$user->hasAnyRole(['super_admin', 'admin'])) {
                return response()->json(['error' => 'Unauthorized - Admin access required'], 403);
            }
            
            return $next($request);
        });
    }

    /**
     * Get all user accounts with their roles and permissions.
     */
    public function getUsers(): JsonResponse
    {
        $users = User::with('roles.permissions')
            ->select([
                'id',
                'name',
                'email',
                'display_name',
                'rank',
                'station',
                'phone',
                'employee_id',
                'must_change_password',
                'notification_preferences',
                'created_at',
                'updated_at'
            ])
            ->orderBy('id')
            ->get()
            ->map(function ($user) {
                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'display_name' => $user->display_name,
                    'rank' => $user->rank,
                    'station' => $user->station,
                    'phone' => $user->phone,
                    'employee_id' => $user->employee_id,
                    'must_change_password' => $user->must_change_password,
                    'notification_preferences' => $user->notification_preferences,
                    'roles' => $user->roles->map(fn ($role) => [
                        'name' => $role->name,
                        'guard_name' => $role->guard_name,
                        'permissions' => $role->permissions->pluck('name')->toArray(),
                    ])->toArray(),
                    'direct_permissions' => $user->permissions->pluck('name')->toArray(),
                    'created_at' => $user->created_at?->toIso8601String(),
                    'updated_at' => $user->updated_at?->toIso8601String(),
                ];
            });

        return response()->json([
            'count' => $users->count(),
            'users' => $users,
        ]);
    }

    /**
     * Get all employee accounts.
     */
    public function getEmployees(): JsonResponse
    {
        $employees = Employee::select([
                'id',
                'employee_id',
                'name',
                'rank',
                'must_change_password',
                'created_at',
                'updated_at'
            ])
            ->orderBy('id')
            ->get()
            ->map(function ($employee) {
                return [
                    'id' => $employee->id,
                    'employee_id' => $employee->employee_id,
                    'name' => $employee->name,
                    'rank' => $employee->rank,
                    'must_change_password' => $employee->must_change_password,
                    'created_at' => $employee->created_at?->toIso8601String(),
                    'updated_at' => $employee->updated_at?->toIso8601String(),
                ];
            });

        return response()->json([
            'count' => $employees->count(),
            'employees' => $employees,
        ]);
    }

    /**
     * Get all roles and permissions defined in the system.
     */
    public function getRolesAndPermissions(): JsonResponse
    {
        $roles = DB::table('roles')
            ->leftJoin('role_has_permissions', 'roles.id', '=', 'role_has_permissions.role_id')
            ->leftJoin('permissions', 'role_has_permissions.permission_id', '=', 'permissions.id')
            ->select(
                'roles.id as role_id',
                'roles.name as role_name',
                'roles.guard_name as role_guard',
                'permissions.name as permission_name',
                'permissions.guard_name as permission_guard'
            )
            ->orderBy('roles.id')
            ->get();

        $grouped = [];
        foreach ($roles as $row) {
            $roleKey = $row->role_name . ' (' . $row->role_guard . ')';
            if (!isset($grouped[$roleKey])) {
                $grouped[$roleKey] = [
                    'id' => $row->role_id,
                    'name' => $row->role_name,
                    'guard_name' => $row->role_guard,
                    'permissions' => [],
                ];
            }
            if ($row->permission_name) {
                $grouped[$roleKey]['permissions'][] = [
                    'name' => $row->permission_name,
                    'guard_name' => $row->permission_guard,
                ];
            }
        }

        return response()->json([
            'roles' => array_values($grouped),
            'total_roles' => count($grouped),
        ]);
    }

    /**
     * Get complete audit summary - all accounts, roles, and statistics.
     */
    public function getAuditSummary(): JsonResponse
    {
        // User statistics
        $userCount = User::count();
        $usersWithRoles = DB::table('model_has_roles')
            ->where('model_type', User::class)
            ->distinct('model_id')
            ->count('model_id');

        // Employee statistics
        $employeeCount = Employee::count();
        $employeesRequiringPasswordChange = Employee::where('must_change_password', true)->count();

        // Role assignment statistics
        $roleAssignments = DB::table('model_has_roles')
            ->where('model_type', User::class)
            ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
            ->select('roles.name', DB::raw('COUNT(*) as count'))
            ->groupBy('roles.name')
            ->get()
            ->keyBy('name')
            ->map(fn ($row) => $row->count);

        // Check for duplicate emails/employee_ids
        $duplicateEmails = DB::table('users')
            ->select('email', DB::raw('COUNT(*) as count'))
            ->groupBy('email')
            ->having('count', '>', 1)
            ->get();

        $duplicateEmployeeIds = DB::table('employees')
            ->select('employee_id', DB::raw('COUNT(*) as count'))
            ->groupBy('employee_id')
            ->having('count', '>', 1)
            ->get();

        // Cross-reference: users with employee_id matching an employee record
        $usersWithEmployeeId = User::whereNotNull('employee_id')->get();
        $linkedAccounts = [];
        foreach ($usersWithEmployeeId as $user) {
            $employee = Employee::where('employee_id', $user->employee_id)->first();
            if ($employee) {
                $linkedAccounts[] = [
                    'user_id' => $user->id,
                    'user_name' => $user->name,
                    'user_email' => $user->email,
                    'employee_id' => $user->employee_id,
                    'employee_name' => $employee->name,
                    'employee_rank' => $employee->rank,
                ];
            }
        }

        return response()->json([
            'summary' => [
                'total_users' => $userCount,
                'users_with_roles' => $usersWithRoles,
                'users_without_roles' => $userCount - $usersWithRoles,
                'total_employees' => $employeeCount,
                'employees_requiring_password_change' => $employeesRequiringPasswordChange,
                'role_distribution' => $roleAssignments,
            ],
            'data_integrity' => [
                'duplicate_emails' => $duplicateEmails->isEmpty() ? 'none' : $duplicateEmails,
                'duplicate_employee_ids' => $duplicateEmployeeIds->isEmpty() ? 'none' : $duplicateEmployeeIds,
            ],
            'linked_accounts' => $linkedAccounts,
            'generated_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * Check case-insensitive email lookup for a specific email.
     */
    public function checkEmailCase(string $email): JsonResponse
    {
        // Check for exact match
        $exactMatch = User::where('email', $email)->first();
        
        // Check for case-insensitive match
        $caseInsensitiveMatch = User::whereRaw('LOWER(email) = ?', [strtolower($email)])
            ->where('email', '!=', $email)
            ->get();

        return response()->json([
            'email' => $email,
            'normalized_email' => strtolower($email),
            'exact_match' => $exactMatch ? [
                'id' => $exactMatch->id,
                'name' => $exactMatch->name,
                'email' => $exactMatch->email,
            ] : null,
            'case_insensitive_matches' => $caseInsensitiveMatch->map(fn ($u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
            ])->toArray(),
            'is_unique' => $exactMatch === null && $caseInsensitiveMatch->isEmpty(),
        ]);
    }

    /**
     * Check case-insensitive employee_id lookup.
     */
    public function checkEmployeeIdCase(string $employeeId): JsonResponse
    {
        // Check for exact match
        $exactMatch = Employee::where('employee_id', $employeeId)->first();
        
        // Check for case-insensitive match (employee_id is typically numeric, but check anyway)
        $caseInsensitiveMatch = Employee::whereRaw('LOWER(employee_id::text) = ?', [strtolower($employeeId)])
            ->where('employee_id', '!=', $employeeId)
            ->get();

        return response()->json([
            'employee_id' => $employeeId,
            'exact_match' => $exactMatch ? [
                'id' => $exactMatch->id,
                'name' => $exactMatch->name,
                'employee_id' => $exactMatch->employee_id,
            ] : null,
            'case_insensitive_matches' => $caseInsensitiveMatch->map(fn ($e) => [
                'id' => $e->id,
                'name' => $e->name,
                'employee_id' => $e->employee_id,
            ])->toArray(),
            'is_unique' => $exactMatch === null && $caseInsensitiveMatch->isEmpty(),
        ]);
    }
}