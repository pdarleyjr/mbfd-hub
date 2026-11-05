<?php

declare(strict_types=1);

namespace App\Services\Identity;

use App\Models\AuthenticationSession;
use App\Models\PersistentLoginCredential;
use App\Models\User;
use App\Models\UserIdentityLink;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class EstablishedAccountIntegritySnapshot
{
    public function __construct(private readonly EstablishedSessionActivity $sessionActivity) {}

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
     * @param  list<int>  $allowedBootstrapCohortUserIds
     * @return array<string, mixed>
     */
    public function compare(array $before, array $after, array $allowedBootstrapCohortUserIds = []): array
    {
        $beforeById = collect($before['accounts'])->keyBy('user_id');
        $afterById = collect($after['accounts'])->keyBy('user_id');
        $allowedBootstrapCohort = array_fill_keys($allowedBootstrapCohortUserIds, true);
        $changedUserIds = [];
        $passwordChanges = 0;
        $statusChanges = 0;
        $mustChangeChanges = 0;
        $rolePermissionChanges = 0;
        $emailChanges = 0;
        $allowedBootstrapCohortTransitions = 0;
        $sessionActivity = 0;

        foreach ($beforeById as $userId => $baseline) {
            $current = $afterById->get($userId);
            if (! is_array($current)) {
                if (isset($allowedBootstrapCohort[(int) $userId])) {
                    $allowedBootstrapCohortTransitions++;

                    continue;
                }
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
            $sessionState = $this->sessionState($baseline, $current);
            if ($sessionState === EstablishedSessionActivity::ACTIVITY) {
                $sessionActivity++;
            }
            if ($sessionState === EstablishedSessionActivity::CHANGED
                || ! hash_equals($this->rowFingerprint($baseline), $this->rowFingerprint($current))) {
                $changedUserIds[] = (int) $userId;
            }
        }

        sort($changedUserIds, SORT_NUMERIC);

        return [
            'baseline_accounts' => $beforeById->count(),
            'current_accounts' => $afterById->count(),
            'newly_established_accounts' => $afterById->keys()->diff($beforeById->keys())->count(),
            'allowed_bootstrap_cohort_transitions' => $allowedBootstrapCohortTransitions,
            'unexpected_changes' => count(array_unique($changedUserIds)),
            'password_hashes_changed' => $passwordChanges,
            'account_status_changed' => $statusChanges,
            'must_change_state_changed' => $mustChangeChanges,
            'role_permission_changes' => $rolePermissionChanges,
            'email_changes' => $emailChanges,
            'session_activity' => $sessionActivity,
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
        $sessionRows = AuthenticationSession::query()->where('user_id', $user->id)->orderBy('id')
            ->get(['id', 'security_version', 'context_class', 'revoked_at', 'revoked_reason', 'idle_expires_at', 'absolute_expires_at']);
        // The fingerprint keeps its original inputs so a baseline written before session activity was
        // recorded can still be compared, strictly, against a new snapshot.
        $sessions = $sessionRows->map(static fn (AuthenticationSession $session): array => array_intersect_key(
            $session->getAttributes(),
            array_flip(['id', 'security_version', 'context_class', 'revoked_at', 'revoked_reason']),
        ))->all();
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
            'sessions' => array_values(array_map(fn (array $raw): array => [
                'id' => (string) $raw['id'],
                'security_version' => (int) $raw['security_version'],
                'context_class' => $raw['context_class'],
                'absolute_expires_at' => $this->instant($raw['absolute_expires_at']),
                'idle_expires_at' => $this->instant($raw['idle_expires_at']),
                'revoked_at' => $this->instant($raw['revoked_at']),
                'revoked_reason' => $raw['revoked_reason'],
            ], $sessionRows->map(static fn (AuthenticationSession $session): array => $session->getAttributes())->all())),
            'persistent_logins_fingerprint' => hash('sha256', json_encode($persistent, JSON_THROW_ON_ERROR)),
        ];
    }

    /**
     * Sessions are compared on their own so members signing in and out during a release are activity, not a
     * change. A baseline without the session list (written before this) still compares the fingerprint strictly.
     *
     * @param  array<string, mixed>  $baseline
     * @param  array<string, mixed>  $current
     */
    private function sessionState(array $baseline, array $current): string
    {
        if (! isset($baseline['sessions'], $current['sessions'], $baseline['persistent_logins_fingerprint'])) {
            return ($baseline['session_state_fingerprint'] ?? null) === ($current['session_state_fingerprint'] ?? null)
                ? EstablishedSessionActivity::SAME
                : EstablishedSessionActivity::CHANGED;
        }
        if ($baseline['persistent_logins_fingerprint'] !== ($current['persistent_logins_fingerprint'] ?? null)) {
            return EstablishedSessionActivity::CHANGED;
        }

        return $this->sessionActivity->classify($baseline['sessions'], $current['sessions'], (int) ($current['security_version'] ?? 0));
    }

    /** One spelling for a stored time, whatever the database driver returns, so snapshots compare as written. */
    private function instant(mixed $value): ?string
    {
        return $value === null ? null : CarbonImmutable::parse((string) $value)->utc()->toIso8601String();
    }

    /** @param array<string, mixed> $row */
    private function rowFingerprint(array $row): string
    {
        unset($row['session_state_fingerprint'], $row['sessions'], $row['persistent_logins_fingerprint']);

        return hash('sha256', json_encode($row, JSON_THROW_ON_ERROR));
    }
}
