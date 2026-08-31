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
