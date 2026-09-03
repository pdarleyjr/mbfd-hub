<?php

declare(strict_types=1);

namespace App\Services\Identity;

use App\Enums\AccountStatus;
use App\Models\Employee;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final readonly class DualCredentialIdentityClaim
{
    public function __construct(private AccountSecurityService $accountSecurity) {}

    public function claim(
        int $employeeProfileId,
        string $legacyEmail,
        string $legacyPassword,
        CarbonInterface $at,
    ): ?User {
        return DB::transaction(function () use ($employeeProfileId, $legacyEmail, $legacyPassword, $at): ?User {
            /** @var Employee $employee */
            $employee = Employee::query()->lockForUpdate()->findOrFail($employeeProfileId);
            /** @var User|null $user */
            $user = User::query()->where('email', strtolower($legacyEmail))->lockForUpdate()->first();

            $dummyHash = Hash::make(Str::random(48));
            $legacyCredentialValid = Hash::check(
                $legacyPassword,
                $user?->getAuthPassword() ?? $dummyHash,
            );

            $eligible = $user instanceof User
                && $legacyCredentialValid
                && $user->getRawOriginal('account_status') === AccountStatus::PendingActivation->value
                && $user->employee_profile_id === null
                && $user->employee_id === null
                && ! str_ends_with($user->email, '@canonical.mbfdhub.invalid')
                && ($user->roles()->exists()
                    || $user->permissions()->exists()
                    || DB::table('workgroup_members')->where('user_id', $user->id)->exists())
                && ! User::query()->where('employee_profile_id', $employee->id)->exists();

            if (! $eligible) {
                return null;
            }

            $employeeHash = (string) $employee->getRawOriginal('password');
            $transition = $this->accountSecurity->completeCanonicalLink(
                $user,
                $employee->id,
                $employee->employee_id,
                $employeeHash,
                $at,
            );

            Log::notice('canonical_identity_dual_credential_claimed', [
                'user_id' => $user->id,
                'employee_profile_id' => $employee->id,
                'credential_hash_copied' => $transition['password_changed'],
                'account_activated' => $transition['activated'],
            ]);

            return $transition['user'];
        }, 3);
    }
}
