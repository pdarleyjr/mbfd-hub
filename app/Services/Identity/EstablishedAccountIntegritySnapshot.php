<?php

declare(strict_types=1);

namespace App\Services\Identity;

use App\Models\AuthenticationSession;
use App\Models\PersistentLoginCredential;
use App\Models\User;
use App\Models\UserIdentityLink;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class EstablishedAccountIntegritySnapshot
{
    /** @return array{schema:string,generated_at:string,established_account_count:int,accounts:list<array<string,mixed>>} */
    public function capture(): array
    {
        return DB::transaction(function (): array {
            if (DB::getDriverName() === 'pgsql') {
                DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
            }

            $users = User::query();
            if (Schema::hasColumn('users', 'bootstrap_onboarding_eligible')) {
                $users->where('bootstrap_onboarding_eligible', false);
            }
            $accounts = $users
                ->with(['employeeProfile', 'roles', 'permissions', 'identityLinks'])
                ->orderBy('id')
                ->get()
                ->map(fn (User $user): array => $this->account($user))
                ->values()
                ->all();

            return [
                'schema' => 'mbfd-established-account-integrity-v1',
                'generated_at' => now()->toIso8601String(),
                'established_account_count' => count($accounts),
                'accounts' => $accounts,
            ];
        }, 3);
    }

    /**
     * @param  array{accounts:list<array<string,mixed>>}  $before
     * @param  array{accounts:list<array<string,mixed>>}  $after
     * @return array<string, mixed>
     */
    public function compare(array $before, array $after): array
    {
        $beforeById = collect($before['accounts'])->keyBy('user_id');
        $afterById = collect($after['accounts'])->keyBy('user_id');
        $changedUserIds = [];
        $passwordChanges = 0;
        $statusChanges = 0;
        $mustChangeChanges = 0;
        $rolePermissionChanges = 0;
        $emailChanges = 0;

        foreach ($beforeById as $userId => $baseline) {
            $current = $afterById->get($userId);
            if (! is_array($current)) {
                $changedUserIds[] = (int) $userId;

                continue;
            }
            if (($baseline['password_hash_fingerprint'] ?? null) !== ($current['password_hash_fingerprint'] ?? null)) {
                $passwordChanges++;
            }
            if (($baseline['account_status'] ?? null) !== ($current['account_status'] ?? null)) {
                $statusChanges++;
            }
            if (($baseline['must_change_password'] ?? null) !== ($current['must_change_password'] ?? null)) {
                $mustChangeChanges++;
            }
            if (($baseline['authorization_fingerprint'] ?? null) !== ($current['authorization_fingerprint'] ?? null)) {
                $rolePermissionChanges++;
            }
            if (($baseline['email_fingerprint'] ?? null) !== ($current['email_fingerprint'] ?? null)) {
                $emailChanges++;
            }
            if (! hash_equals($this->rowFingerprint($baseline), $this->rowFingerprint($current))) {
                $changedUserIds[] = (int) $userId;
            }
        }

        sort($changedUserIds, SORT_NUMERIC);

        return [
            'baseline_accounts' => $beforeById->count(),
            'current_accounts' => $afterById->count(),
            'newly_established_accounts' => $afterById->keys()->diff($beforeById->keys())->count(),
            'unexpected_changes' => count(array_unique($changedUserIds)),
            'password_hashes_changed' => $passwordChanges,
            'account_status_changed' => $statusChanges,
            'must_change_state_changed' => $mustChangeChanges,
            'role_permission_changes' => $rolePermissionChanges,
            'email_changes' => $emailChanges,
            'changed_user_ids' => array_values(array_unique($changedUserIds)),
        ];
    }

    /** @return array<string, mixed> */
    private function account(User $user): array
    {
        $roles = $user->roles->pluck('name')->sort()->values()->all();
        $permissions = $user->permissions->pluck('name')->sort()->values()->all();
        $links = $user->identityLinks->map(static fn (UserIdentityLink $link): array => [
            'provider' => $link->provider,
            'subject' => $link->subject,
            'provider_user_id' => $link->provider_user_id,
            'status' => $link->status,
            'security_state_fingerprint' => hash('sha256', json_encode($link->security_state, JSON_THROW_ON_ERROR)),
        ])->sortBy(static fn (array $link): string => $link['provider'].'|'.$link['subject'])->values()->all();
        $sessions = AuthenticationSession::query()->where('user_id', $user->id)->orderBy('id')
            ->get(['id', 'security_version', 'context_class', 'revoked_at', 'revoked_reason'])
            ->map(static fn (AuthenticationSession $session): array => $session->getAttributes())->all();
        $persistent = PersistentLoginCredential::query()->where('user_id', $user->id)->orderBy('id')
            ->get(['id', 'context_class', 'revoked_at', 'revoked_reason'])
            ->map(static fn (PersistentLoginCredential $credential): array => $credential->getAttributes())->all();
        $emailState = [
            'user_email' => $user->email,
            'email_verified_at' => $user->email_verified_at?->toIso8601String(),
            'employee_city_email' => $user->employeeProfile?->city_email,
        ];

        return [
            'user_id' => $user->id,
            'employee_id' => $user->employee_id,
            'employee_profile_id' => $user->employee_profile_id,
            'password_hash_fingerprint' => hash('sha256', (string) $user->getRawOriginal('password')),
            'account_status' => $user->getRawOriginal('account_status'),
            'must_change_password' => $user->must_change_password,
            'security_version' => $user->security_version,
            ...$emailState,
            'email_fingerprint' => hash('sha256', json_encode($emailState, JSON_THROW_ON_ERROR)),
            'roles' => $roles,
            'direct_permissions' => $permissions,
            'authorization_fingerprint' => hash('sha256', json_encode([$roles, $permissions], JSON_THROW_ON_ERROR)),
            'identity_links' => $links,
            'session_state_fingerprint' => hash('sha256', json_encode([$sessions, $persistent], JSON_THROW_ON_ERROR)),
        ];
    }

    /** @param array<string, mixed> $row */
    private function rowFingerprint(array $row): string
    {
        return hash('sha256', json_encode($row, JSON_THROW_ON_ERROR));
    }
}
