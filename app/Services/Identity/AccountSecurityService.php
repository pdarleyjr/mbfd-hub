<?php

declare(strict_types=1);

namespace App\Services\Identity;

use App\Enums\AccountStatus;
use App\Models\AuthenticationSession;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

final class AccountSecurityService
{
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
    ): array {
        return DB::transaction(function () use ($user, $employeeProfileId, $employeeId, $passwordHash, $at): array {
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
            if ($lockedUser->getRawOriginal('account_status') === AccountStatus::PendingActivation->value) {
                $changes['account_status'] = AccountStatus::Active->value;
                $activated = true;
            }
            if ($passwordHash !== null && ! hash_equals((string) $lockedUser->getRawOriginal('password'), $passwordHash)) {
                $changes['password'] = $passwordHash;
                $changes['password_changed_at'] = $at;
                $passwordChanged = true;
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
                'security_version' => $lockedUser->security_version + 1,
            ])->save();

            $this->revokeSessions($lockedUser, 'password changed', $at);

            return $lockedUser;
        });
    }

    private function revokeSessions(User $user, string $reason, CarbonInterface $at): void
    {
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
