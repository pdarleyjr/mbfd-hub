<?php

declare(strict_types=1);

namespace App\Services\Identity;

use App\Enums\AccountStatus;
use App\Models\Employee;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

final readonly class MemberBootstrapCohortInitializer
{
    public function __construct(private MemberBootstrapCohortManifest $manifests) {}

    /** @return array{cohort_count:int,initialized:int,already_eligible:int,manifest_sha256:string,source_backup_sha256:string} */
    public function initialize(string $manifestPath, CarbonImmutable $at): array
    {
        $manifest = $this->manifests->load($manifestPath);

        $result = DB::transaction(function () use ($manifest, $at): array {
            $evidenceByUserId = collect($manifest['members'])->keyBy('user_id');
            $userIds = $evidenceByUserId->keys()->map(static fn (mixed $id): int => (int) $id)->sort()->values()->all();
            $users = User::query()->whereKey($userIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            if ($users->count() !== count($userIds)) {
                throw new RuntimeException('Bootstrap cohort no longer matches the proven User set.');
            }
            $employeeIds = collect($manifest['members'])->pluck('employee_profile_id')->map(static fn (mixed $id): int => (int) $id)->unique()->sort()->values()->all();
            $employees = Employee::query()->whereKey($employeeIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            if ($employees->count() !== count($employeeIds)) {
                throw new RuntimeException('Bootstrap cohort no longer matches the proven Employee set.');
            }

            $this->rejectAuthenticationArtifacts($userIds, $users->pluck('email')->filter()->values()->all());
            $alreadyEligible = 0;
            foreach ($userIds as $userId) {
                /** @var User $user */
                $user = $users->get($userId);
                /** @var array{user_id:int,employee_profile_id:int,created_at:string,password_hash_fingerprint:string} $evidence */
                $evidence = $evidenceByUserId->get($userId);
                /** @var Employee|null $employee */
                $employee = $employees->get($evidence['employee_profile_id']);
                if (! $employee instanceof Employee
                    || $user->employee_profile_id !== $employee->id
                    || $user->employee_id !== $employee->employee_id
                    || $employee->roster_status !== 'active'
                    || $user->getRawOriginal('account_status') !== AccountStatus::PendingActivation->value
                    || ! $user->must_change_password
                    || $user->password_changed_at !== null
                    || $user->getRawOriginal('temporary_credential_fingerprint') !== null
                    || $user->bootstrap_onboarding_completed_at !== null
                    || $user->last_login_at !== null
                    || $user->email_verified_at !== null
                    || $user->getRawOriginal('remember_token') !== null
                    || $user->security_version !== 1
                    || $user->email !== "employee-{$employee->id}@canonical.mbfdhub.invalid"
                    || ! hash_equals($evidence['password_hash_fingerprint'], hash('sha256', (string) $user->getRawOriginal('password')))
                    || ! CarbonImmutable::parse($evidence['created_at'])->equalTo($user->created_at)) {
                    throw new RuntimeException('Bootstrap cohort evidence does not match the current unestablished account state.');
                }
                if ($user->bootstrap_onboarding_eligible) {
                    if ($user->bootstrap_onboarding_eligible_at === null) {
                        throw new RuntimeException('Bootstrap cohort eligibility state is incomplete.');
                    }
                    $alreadyEligible++;
                } elseif ($user->bootstrap_onboarding_eligible_at !== null) {
                    throw new RuntimeException('Bootstrap cohort eligibility state is inconsistent.');
                }
            }

            $initialized = User::query()
                ->whereKey($userIds)
                ->where('bootstrap_onboarding_eligible', false)
                ->update([
                    'bootstrap_onboarding_eligible' => true,
                    'bootstrap_onboarding_eligible_at' => $at,
                    'updated_at' => $at,
                ]);
            if ($initialized + $alreadyEligible !== count($userIds)) {
                throw new RuntimeException('Bootstrap cohort changed during initialization.');
            }

            return [
                'cohort_count' => count($userIds),
                'initialized' => $initialized,
                'already_eligible' => $alreadyEligible,
                'manifest_sha256' => $manifest['manifest_sha256'],
                'source_backup_sha256' => $manifest['source_backup_sha256'],
            ];
        }, 3);

        Log::notice('member_bootstrap_cohort_initialized', $result);

        return $result;
    }

    /** @param list<int> $userIds
     * @param  list<string>  $emails
     */
    private function rejectAuthenticationArtifacts(array $userIds, array $emails): void
    {
        foreach (['authentication_sessions', 'persistent_login_credentials', 'user_identity_links', 'identity_synchronizations'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->whereIn('user_id', $userIds)->exists()) {
                throw new RuntimeException('Bootstrap cohort contains prior authentication or identity-provider activity.');
            }
        }
        if (Schema::hasTable('personal_access_tokens')
            && DB::table('personal_access_tokens')
                ->where('tokenable_type', (new User)->getMorphClass())
                ->whereIn('tokenable_id', $userIds)
                ->exists()) {
            throw new RuntimeException('Bootstrap cohort contains prior authentication or identity-provider activity.');
        }
        if (Schema::hasTable('sessions') && Schema::hasColumn('sessions', 'user_id')
            && DB::table('sessions')->whereIn('user_id', $userIds)->exists()) {
            throw new RuntimeException('Bootstrap cohort contains prior application sessions.');
        }
        if (Schema::hasTable('password_reset_tokens') && DB::table('password_reset_tokens')->whereIn('email', $emails)->exists()) {
            throw new RuntimeException('Bootstrap cohort contains prior password-recovery activity.');
        }
    }
}
