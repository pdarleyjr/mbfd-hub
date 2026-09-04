<?php

declare(strict_types=1);

namespace App\Services\Identity;

use App\Enums\AccountStatus;
use App\Models\Employee;
use App\Models\User;
use App\Services\IdentityReconciliation\CredentialInspector;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\Permission\Models\Role;

final readonly class CanonicalUserProvisioner
{
    public function __construct(private CredentialInspector $credentials) {}

    /**
     * @return array{user: User, created: bool, credential_hash_copied: bool, activated: bool}
     */
    public function create(int $employeeProfileId, string $credentialProvenance, CarbonInterface $at): array
    {
        return DB::transaction(function () use ($employeeProfileId, $credentialProvenance, $at): array {
            /** @var Employee $employee */
            $employee = Employee::query()->lockForUpdate()->findOrFail($employeeProfileId);
            $email = "employee-{$employee->id}@canonical.mbfdhub.invalid";
            $existing = User::query()->where('employee_profile_id', $employee->id)->lockForUpdate()->first();
            if ($existing !== null) {
                if ($existing->employee_id !== $employee->employee_id || $existing->email !== $email) {
                    throw new RuntimeException("Employee {$employee->id} is linked to a different canonical User.");
                }

                return [
                    'user' => $existing,
                    'created' => false,
                    'credential_hash_copied' => false,
                    'activated' => false,
                ];
            }
            if (User::query()->where('employee_id', $employee->employee_id)->orWhere('email', $email)->exists()) {
                throw new RuntimeException("Employee {$employee->id} conflicts with an existing User identity.");
            }

            $employeeHash = (string) $employee->getRawOriginal('password');
            $copyVerifiedLegacyHash = $credentialProvenance === 'LEGACY_HUMAN_BCRYPT_UNCHANGED';
            if ($copyVerifiedLegacyHash) {
                $legacyCredential = $this->credentials->inspect($employeeHash);
                if ($legacyCredential['state'] !== 'HASH_PRESENT' || $legacyCredential['algorithm'] !== 'BCRYPT') {
                    throw new RuntimeException("Employee {$employee->id} does not have the verified legacy bcrypt declared by the transition.");
                }
            } elseif (! in_array($credentialProvenance, [
                'POST_D03_OR_UNPROVEN_COMPATIBILITY_HASH',
                'MISSING_OR_UNSUPPORTED',
            ], true)) {
                throw new RuntimeException('Credential provenance is not an approved classification.');
            }

            $status = $copyVerifiedLegacyHash ? AccountStatus::Active : AccountStatus::PendingActivation;
            $userId = DB::table('users')->insertGetId([
                'name' => $employee->name,
                'display_name' => $employee->name,
                'email' => $email,
                'password' => $copyVerifiedLegacyHash ? $employeeHash : Hash::make(Str::random(64)),
                'rank' => $employee->rank,
                'must_change_password' => $copyVerifiedLegacyHash ? $employee->must_change_password : true,
                'employee_id' => $employee->employee_id,
                'employee_profile_id' => $employee->id,
                'account_status' => $status->value,
                'security_version' => 1,
                'password_changed_at' => $copyVerifiedLegacyHash ? $at : null,
                'created_at' => $at,
                'updated_at' => $at,
            ]);
            /** @var User $user */
            $user = User::query()->findOrFail($userId);
            if ($copyVerifiedLegacyHash) {
                $user->assignRole(Role::findOrCreate('member', 'web'));
            }

            Log::notice('canonical_identity_user_created', [
                'user_id' => $user->id,
                'employee_profile_id' => $employee->id,
                'credential_provenance' => $credentialProvenance,
                'credential_hash_copied' => $copyVerifiedLegacyHash,
                'account_status' => $status->value,
            ]);

            return [
                'user' => $user,
                'created' => true,
                'credential_hash_copied' => $copyVerifiedLegacyHash,
                'activated' => $copyVerifiedLegacyHash,
            ];
        }, 3);
    }
}
