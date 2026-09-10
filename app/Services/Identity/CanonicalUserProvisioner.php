<?php

declare(strict_types=1);

namespace App\Services\Identity;

use App\Enums\AccountStatus;
use App\Models\Employee;
use App\Models\User;
use App\Models\UserNotificationSubscription;
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
     * @return array{user: User, created: bool, credential_hash_copied: bool, activated: bool, member_role_added: bool}
     */
    public function create(int $employeeProfileId, string $credentialProvenance, CarbonInterface $at): array
    {
        return DB::transaction(function () use ($employeeProfileId, $credentialProvenance, $at): array {
            // Existing User -> Employee, never Employee -> a newly discovered User.
            // New inserts below are private to this transaction, not existing locks.
            $existing = User::query()->where('employee_profile_id', $employeeProfileId)->lockForUpdate()->first();
            /** @var Employee $employee */
            $employee = Employee::query()->lockForUpdate()->findOrFail($employeeProfileId);
            if ($employee->roster_status === 'departed') {
                throw new RuntimeException('Departed personnel cannot receive or activate a login account.');
            }
            $email = "employee-{$employee->id}@canonical.mbfdhub.invalid";
            if (User::query()->where('employee_profile_id', $employee->id)->value('id') !== $existing?->id) {
                throw new RuntimeException('The canonical identity changed while acquiring locks. Reload before retrying.');
            }
            if ($existing !== null) {
                if ($existing->employee_id !== $employee->employee_id) {
                    throw new RuntimeException("Employee {$employee->id} is linked to a different canonical User.");
                }

                UserNotificationSubscription::ensureDepartmentUpdatesForUser($existing->id);
                $memberRoleAdded = ! $existing->hasRole('member');
                $existing->assignRole(Role::findOrCreate('member', 'web'));

                return [
                    'user' => $existing,
                    'created' => false,
                    'credential_hash_copied' => false,
                    'activated' => false,
                    'member_role_added' => $memberRoleAdded,
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
                'display_name' => $employee->display_name,
                'station' => $employee->station,
                'phone' => $employee->phone,
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
            $user = User::query()->findOrFail($userId);
            $user->assignRole(Role::findOrCreate('member', 'web'));
            UserNotificationSubscription::ensureDepartmentUpdatesForUser($userId);

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
                'member_role_added' => true,
            ];
        }, 3);
    }
}
