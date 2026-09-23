<?php

declare(strict_types=1);

namespace App\Services\Identity;

use App\Enums\AccountStatus;
use App\Exceptions\MemberBootstrapStateChanged;
use App\Models\AuthenticationSession;
use App\Models\Employee;
use App\Models\MemberOnboardingInvitation;
use App\Models\MemberOnboardingRosterBinding;
use App\Models\User;
use App\Services\Security\SecurityAuditRecorder;
use Carbon\CarbonInterface;
use Illuminate\Auth\Passwords\PasswordBroker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;

final class AccountSecurityService
{
    public function completeMemberOnboarding(
        int $invitationId,
        int $userId,
        int $employeeProfileId,
        int $expectedSecurityVersion,
        string $sessionBinding,
        string $passwordHash,
        CarbonInterface $at,
    ): User {
        return DB::transaction(function () use ($invitationId, $userId, $employeeProfileId, $expectedSecurityVersion, $sessionBinding, $passwordHash, $at): User {
            /** @var MemberOnboardingInvitation|null $invitation */
            $invitation = MemberOnboardingInvitation::query()->lockForUpdate()->find($invitationId);
            /** @var User|null $lockedUser */
            $lockedUser = User::query()->lockForUpdate()->find($userId);
            /** @var Employee|null $lockedEmployee */
            $lockedEmployee = Employee::query()->lockForUpdate()->find($employeeProfileId);
            $rosterBinding = MemberOnboardingRosterBinding::query()
                ->where('employee_profile_id', $employeeProfileId)
                ->lockForUpdate()
                ->first();
            $authoritativeEmail = strtolower(trim((string) $lockedEmployee?->city_email));
            if (! $invitation instanceof MemberOnboardingInvitation || ! $lockedUser instanceof User || ! $lockedEmployee instanceof Employee
                || $invitation->user_id !== $lockedUser->id
                || $invitation->employee_profile_id !== $lockedEmployee->id
                || $invitation->security_version !== $expectedSecurityVersion
                || $invitation->token_hash === null
                || $invitation->expires_at === null || ! $invitation->expires_at->greaterThan($at)
                || $invitation->redeemed_at === null || $invitation->consumed_at !== null
                || ! is_string($invitation->redeemed_binding_hash)
                || ! hash_equals($invitation->redeemed_binding_hash, hash('sha256', $sessionBinding))
                || ! hash_equals($invitation->email, $authoritativeEmail)
                || ! $rosterBinding instanceof MemberOnboardingRosterBinding
                || $rosterBinding->employee_id !== $lockedEmployee->employee_id
                || $rosterBinding->city_email !== $authoritativeEmail
                || $lockedUser->employee_profile_id !== $lockedEmployee->getKey()
                || $lockedUser->employee_id !== $lockedEmployee->employee_id
                || $lockedEmployee->roster_status !== 'active'
                || $lockedUser->getRawOriginal('account_status') !== AccountStatus::PendingActivation->value
                || ! $lockedUser->bootstrap_onboarding_eligible
                || $lockedUser->bootstrap_onboarding_completed_at !== null
                || $lockedUser->security_version !== $expectedSecurityVersion) {
                throw new MemberBootstrapStateChanged('The onboarding authorization is no longer current.');
            }

            app(CanonicalCityEmailService::class)->sync($lockedEmployee, $lockedUser, $invitation->email);
            DB::table('users')->where('id', $lockedUser->id)->update([
                'password' => $passwordHash,
                'temporary_credential_fingerprint' => null,
                'account_status' => AccountStatus::Active->value,
                'must_change_password' => false,
                'password_changed_at' => $at,
                'bootstrap_onboarding_eligible' => false,
                'bootstrap_onboarding_completed_at' => $at,
                'email_verified_at' => $at,
                'security_version' => $lockedUser->security_version + 1,
                'updated_at' => $at,
            ]);
            $invitation->forceFill([
                'token_hash' => null,
                'expires_at' => null,
                'redeemed_binding_hash' => null,
                'consumed_at' => $at,
                'delivery_status' => 'consumed',
            ])->save();
            $lockedUser = $lockedUser->fresh('employeeProfile');
            $this->revokeSessions($lockedUser, 'member onboarding completed', $at);
            app(SecurityAuditRecorder::class)->record(
                $lockedUser,
                $lockedUser,
                'complete_member_onboarding',
                'allowed',
                null,
                ['employee_profile_id' => $lockedEmployee->id, 'city_email_verified' => true],
            );

            return $lockedUser;
        }, 3);
    }

    public function setAdministrativeRecoveryPassword(User $user, string $passwordHash, string $fingerprint, CarbonInterface $at): User
    {
        return DB::transaction(function () use ($user, $passwordHash, $fingerprint, $at): User {
            /** @var User $lockedUser */
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);
            DB::table('users')->where('id', $lockedUser->id)->update([
                'password' => $passwordHash,
                'temporary_credential_fingerprint' => $fingerprint,
                'must_change_password' => true,
                'password_changed_at' => $at,
                'bootstrap_onboarding_eligible' => false,
                'security_version' => $lockedUser->security_version + 1,
                'updated_at' => $at,
            ]);
            $lockedUser = $lockedUser->fresh();
            $this->revokeSessions($lockedUser, 'administrative password recovery', $at);

            return $lockedUser;
        });
    }

    public function activateWithTemporaryPassword(User $user, string $passwordHash, string $fingerprint, CarbonInterface $at): User
    {
        return DB::transaction(function () use ($user, $passwordHash, $fingerprint, $at): User {
            /** @var User $lockedUser */
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);
            if ($lockedUser->getRawOriginal('account_status') !== AccountStatus::PendingActivation->value) {
                throw new \RuntimeException('Only an account awaiting activation may use the first-login temporary-password action.');
            }
            DB::table('users')->where('id', $lockedUser->id)->update([
                'password' => $passwordHash,
                'temporary_credential_fingerprint' => $fingerprint,
                'account_status' => AccountStatus::Active->value,
                'must_change_password' => true,
                'password_changed_at' => $at,
                'email_verified_at' => null,
                'bootstrap_onboarding_eligible' => false,
                'bootstrap_onboarding_completed_at' => $at,
                'security_version' => $lockedUser->security_version + 1,
                'updated_at' => $at,
            ]);
            $lockedUser = $lockedUser->fresh();
            $this->revokeSessions($lockedUser, 'temporary password issued', $at);

            return $lockedUser;
        });
    }

    public function forcePasswordChange(User $user, CarbonInterface $at): User
    {
        return DB::transaction(function () use ($user, $at): User {
            /** @var User $lockedUser */
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);
            $lockedUser->forceFill([
                'must_change_password' => true,
                'bootstrap_onboarding_eligible' => false,
                'security_version' => $lockedUser->security_version + 1,
            ])->save();
            $this->revokeSessions($lockedUser, 'password change required', $at);

            return $lockedUser;
        });
    }

    public function changePassword(User $user, string $passwordHash, CarbonInterface $at): User
    {
        return DB::transaction(function () use ($user, $passwordHash, $at): User {
            /** @var User $lockedUser */
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);
            DB::table('users')->where('id', $lockedUser->id)->update([
                'password' => $passwordHash,
                'temporary_credential_fingerprint' => null,
                'must_change_password' => false,
                'password_changed_at' => $at,
                'bootstrap_onboarding_eligible' => false,
                'security_version' => $lockedUser->security_version + 1,
                'updated_at' => $at,
            ]);
            $lockedUser = $lockedUser->fresh();
            $this->revokeSessions($lockedUser, 'password changed', $at);

            return $lockedUser;
        });
    }

    /**
     * Complete an approved canonical identity transition as one security event.
     *
     * @return array{user: User, changed: bool, password_changed: bool, activated: bool}
     */
    public function completeCanonicalLink(
        User $user,
        int $employeeProfileId,
        string $employeeId,
        ?string $passwordHash,
        CarbonInterface $at,
        bool $activatePending = true,
    ): array {
        return DB::transaction(function () use ($user, $employeeProfileId, $employeeId, $passwordHash, $at, $activatePending): array {
            /** @var User $lockedUser */
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);
            $changes = [];
            $passwordChanged = false;
            $activated = false;

            if ($lockedUser->employee_profile_id !== $employeeProfileId) {
                $changes['employee_profile_id'] = $employeeProfileId;
            }
            if ($lockedUser->employee_id !== $employeeId) {
                $changes['employee_id'] = $employeeId;
            }
            if (isset($changes['employee_profile_id']) || isset($changes['employee_id'])) {
                // Every canonical linking path must update legacy SQL consumers,
                // not only the administrator UI. Preserve the User -> Employee lock order.
                $employee = Employee::query()->where('employee_id', $employeeId)->lockForUpdate()->findOrFail($employeeProfileId);
                foreach (Employee::PROFILE_FIELDS as $field) {
                    $changes[$field] = $employee->getAttribute($field);
                }
            }
            if ($activatePending && $lockedUser->getRawOriginal('account_status') === AccountStatus::PendingActivation->value) {
                $changes['account_status'] = AccountStatus::Active->value;
                $activated = true;
            }
            if ($passwordHash !== null && ! hash_equals((string) $lockedUser->getRawOriginal('password'), $passwordHash)) {
                $changes['password'] = $passwordHash;
                $changes['password_changed_at'] = $at;
                $changes['must_change_password'] = true;
                $passwordChanged = true;
            }
            if ($lockedUser->bootstrap_onboarding_eligible) {
                $changes['bootstrap_onboarding_eligible'] = false;
            }

            if ($changes === []) {
                return [
                    'user' => $lockedUser,
                    'changed' => false,
                    'password_changed' => false,
                    'activated' => false,
                ];
            }

            $changes['security_version'] = $lockedUser->security_version + 1;
            $changes['updated_at'] = $at;
            DB::table('users')->where('id', $lockedUser->id)->update($changes);
            $lockedUser = $lockedUser->fresh();
            $this->revokeSessions($lockedUser, 'canonical identity transition', $at);

            return [
                'user' => $lockedUser,
                'changed' => true,
                'password_changed' => $passwordChanged,
                'activated' => $activated,
            ];
        });
    }

    public function disable(User $user, string $reason, CarbonInterface $at): User
    {
        return $this->changeStatus($user, AccountStatus::Disabled, $reason, $at);
    }

    public function changeStatus(User $user, AccountStatus $status, string $reason, CarbonInterface $at): User
    {
        return DB::transaction(function () use ($user, $status, $reason, $at): User {
            /** @var User $lockedUser */
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);
            $lockedUser->forceFill([
                'account_status' => $status,
                'bootstrap_onboarding_eligible' => false,
                'security_version' => $lockedUser->security_version + 1,
            ])->save();

            $this->revokeSessions($lockedUser, $reason, $at);

            return $lockedUser;
        });
    }

    public function revokeAll(User $user, string $reason, CarbonInterface $at): User
    {
        return DB::transaction(function () use ($user, $reason, $at): User {
            /** @var User $lockedUser */
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);
            $lockedUser->forceFill([
                'security_version' => $lockedUser->security_version + 1,
            ])->save();

            $this->revokeSessions($lockedUser, $reason, $at);

            return $lockedUser;
        });
    }

    public function recordPasswordChange(User $user, CarbonInterface $at): User
    {
        return DB::transaction(function () use ($user, $at): User {
            /** @var User $lockedUser */
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);
            $lockedUser->forceFill([
                'password_changed_at' => $at,
                'temporary_credential_fingerprint' => null,
                'bootstrap_onboarding_eligible' => false,
                'security_version' => $lockedUser->security_version + 1,
            ])->save();

            $this->revokeSessions($lockedUser, 'password changed', $at);

            return $lockedUser;
        });
    }

    private function revokeSessions(User $user, string $reason, CarbonInterface $at): void
    {
        // Recovery links are credentials too: a security-version transition
        // invalidates outstanding links along with browser and federated sessions.
        $broker = Password::broker();
        if (! $broker instanceof PasswordBroker) {
            throw new \LogicException('The configured password broker does not support token persistence.');
        }
        $broker->deleteToken($user);
        app(\App\Services\Oidc\OidcSessionRevoker::class)->revoke($user);
        app(\App\Services\Cloud\NextcloudAccountSynchronizer::class)->request($user);

        DB::table('personal_access_tokens')
            ->where('tokenable_type', User::class)
            ->where('tokenable_id', $user->id)
            ->delete();

        AuthenticationSession::query()
            ->where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->update([
                'revoked_at' => $at,
                'revoked_reason' => $reason,
                'updated_at' => $at,
            ]);
    }
}
