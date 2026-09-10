<?php

declare(strict_types=1);

namespace App\Services\Identity;

use App\Models\Employee;
use App\Models\User;
use App\Support\ApplicationAccessRegistry;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

final readonly class UniversalAccountInventory
{
    public function __construct(private ApplicationAccessRegistry $applications) {}

    /** @return array<string, mixed> */
    public function report(): array
    {
        $employees = Employee::query()->orderBy('employee_id')->orderBy('id')->get();
        $users = User::query()->with(['roles', 'permissions', 'identityLinks'])->orderBy('id')->get();
        $employeesByIdentifier = $employees->groupBy('employee_id');
        $usersByIdentifier = $users->filter(fn (User $user): bool => filled($user->employee_id))->groupBy('employee_id');
        $usersByProfile = $users->filter(fn (User $user): bool => $user->employee_profile_id !== null)->groupBy('employee_profile_id');
        $workgroups = DB::table('workgroup_members')->orderBy('user_id')->orderBy('workgroup_id')->orderBy('id')->get()->groupBy('user_id');
        $rows = [];
        $associatedUserIds = [];

        foreach ($employees as $employee) {
            $identifierUsers = $usersByIdentifier->get($employee->employee_id, collect());
            $profileUsers = $usersByProfile->get($employee->id, collect());
            $classification = $this->classifyEmployee(
                $employee,
                $employeesByIdentifier->get($employee->employee_id, collect()),
                $identifierUsers,
                $profileUsers,
            );
            $user = $this->associatedUser($identifierUsers, $profileUsers);
            if ($user instanceof User) {
                $associatedUserIds[$user->id] = true;
            }
            $rows[] = $this->employeeRow($employee, $user, $classification, $workgroups->get($user?->id, collect()));
        }

        foreach ($users as $user) {
            if (isset($associatedUserIds[$user->id])) {
                continue;
            }
            $rows[] = $this->userRow($user, $workgroups->get($user->id, collect()));
        }

        $counts = array_fill_keys([
            'EXISTING_CANONICAL_USER', 'EXACT_LEGACY_USER_NEEDS_LINK', 'ROSTER_ONLY_NEEDS_USER',
            'DUPLICATE_EMPLOYEE_ID', 'MULTIPLE_USERS_FOR_EMPLOYEE', 'CONFLICTING_LINK',
            'DEPARTED', 'NONEMPLOYEE/SERVICE',
        ], 0);
        foreach ($rows as $row) {
            $counts[$row['classification']] = ($counts[$row['classification']] ?? 0) + 1;
        }
        $conflicts = array_values(array_filter($rows, static fn (array $row): bool => in_array($row['classification'], [
            'DUPLICATE_EMPLOYEE_ID', 'MULTIPLE_USERS_FOR_EMPLOYEE', 'CONFLICTING_LINK',
        ], true)));

        return [
            'generated_at' => now()->toIso8601String(),
            'summary' => [
                'active_employees' => $employees->where('roster_status', 'active')->count(),
                ...$counts,
                'identity_conflicts' => count($conflicts),
                'active_employees_without_canonical_user' => count(array_filter($rows, static fn (array $row): bool => $row['roster_status'] === 'active'
                    && $row['classification'] !== 'EXISTING_CANONICAL_USER')),
            ],
            'rows' => $rows,
            'conflicts' => array_map(static fn (array $row): array => [
                'employee_id' => $row['employee_id'],
                'classification' => $row['classification'],
            ], $conflicts),
        ];
    }

    /** @param Collection<int, Employee> $matchingEmployees
     * @param  Collection<int, User>  $identifierUsers
     * @param  Collection<int, User>  $profileUsers
     */
    private function classifyEmployee(Employee $employee, Collection $matchingEmployees, Collection $identifierUsers, Collection $profileUsers): string
    {
        if ($employee->roster_status !== 'active') {
            return 'DEPARTED';
        }
        if ($matchingEmployees->count() > 1) {
            return 'DUPLICATE_EMPLOYEE_ID';
        }
        if ($profileUsers->count() > 1 || $identifierUsers->count() > 1) {
            return 'MULTIPLE_USERS_FOR_EMPLOYEE';
        }
        if ($profileUsers->count() === 1) {
            $linked = $profileUsers->first();

            return $linked instanceof User && $linked->employee_id === $employee->employee_id
                && ($identifierUsers->isEmpty() || $identifierUsers->first()->is($linked))
                ? 'EXISTING_CANONICAL_USER' : 'CONFLICTING_LINK';
        }
        if ($identifierUsers->count() === 1) {
            $candidate = $identifierUsers->first();

            return $candidate instanceof User && $candidate->employee_profile_id === null
                ? 'EXACT_LEGACY_USER_NEEDS_LINK' : 'CONFLICTING_LINK';
        }

        return 'ROSTER_ONLY_NEEDS_USER';
    }

    /** @param Collection<int, User> $identifierUsers
     * @param  Collection<int, User>  $profileUsers
     */
    private function associatedUser(Collection $identifierUsers, Collection $profileUsers): ?User
    {
        $profile = $profileUsers->count() === 1 ? $profileUsers->first() : null;
        if ($profile instanceof User) {
            return $profile;
        }
        $identifier = $identifierUsers->count() === 1 ? $identifierUsers->first() : null;

        return $identifier instanceof User ? $identifier : null;
    }

    /** @param Collection<int, stdClass> $workgroups
     * @return array<string, mixed>
     */
    private function employeeRow(Employee $employee, ?User $user, string $classification, Collection $workgroups): array
    {
        return [
            'employee_profile_id' => $employee->id,
            'employee_id' => $employee->employee_id,
            'canonical_user_id' => $user?->id,
            'roster_status' => $employee->roster_status,
            'classification' => $classification,
            ...$this->authorizationState($user, $workgroups),
        ];
    }

    /** @param Collection<int, stdClass> $workgroups
     * @return array<string, mixed>
     */
    private function userRow(User $user, Collection $workgroups): array
    {
        return [
            'employee_profile_id' => $user->employee_profile_id,
            'employee_id' => $user->employee_id,
            'canonical_user_id' => $user->id,
            'roster_status' => null,
            'classification' => 'NONEMPLOYEE/SERVICE',
            ...$this->authorizationState($user, $workgroups),
        ];
    }

    /** @param Collection<int, stdClass> $workgroups
     * @return array<string, mixed>
     */
    private function authorizationState(?User $user, Collection $workgroups): array
    {
        if (! $user instanceof User) {
            return [
                'account_state' => 'awaiting_account', 'base_member_role' => false,
                'role_count' => 0, 'direct_permission_count' => 0, 'workgroup_count' => 0,
                'app_access' => [], 'credential_fingerprint' => null, 'authorization_fingerprint' => null,
                'downstream_fingerprint' => null, 'must_change_password' => null,
            ];
        }
        $applicationStates = $this->applications->states($user);
        $allowedApplications = array_keys(array_filter($applicationStates, static fn (array $state): bool => $state['allowed']));
        sort($allowedApplications, SORT_STRING);
        $roles = $user->roles->pluck('name')->sort()->values()->all();
        $permissions = $user->permissions->pluck('name')->sort()->values()->all();
        $workgroupState = $workgroups->map(static fn (stdClass $membership): array => [
            'id' => $membership->id,
            'workgroup_id' => $membership->workgroup_id,
            'role' => $membership->role,
            'is_active' => (bool) $membership->is_active,
            'count_evaluations' => (bool) $membership->count_evaluations,
        ])->values()->all();
        $identityState = $user->identityLinks->map(static fn ($link): array => [
            'id' => $link->id,
            'provider' => $link->provider,
            'subject' => $link->subject,
            'provider_user_id' => $link->provider_user_id,
            'status' => $link->status,
        ])->sortBy('id')->values()->all();
        $key = (string) config('app.key');

        return [
            'account_state' => $user->getRawOriginal('account_status'),
            'base_member_role' => $user->hasRole('member'),
            'role_count' => count($roles),
            'direct_permission_count' => count($permissions),
            'workgroup_count' => count($workgroupState),
            'app_access' => $allowedApplications,
            'credential_fingerprint' => hash_hmac('sha256', (string) $user->getRawOriginal('password'), $key),
            'authorization_fingerprint' => hash_hmac('sha256', json_encode([$roles, $permissions, $workgroupState, $allowedApplications], JSON_THROW_ON_ERROR), $key),
            'downstream_fingerprint' => hash_hmac('sha256', json_encode($identityState, JSON_THROW_ON_ERROR), $key),
            'must_change_password' => $user->must_change_password,
        ];
    }
}
