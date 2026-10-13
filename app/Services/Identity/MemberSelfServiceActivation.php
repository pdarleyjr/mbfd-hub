<?php

declare(strict_types=1);

namespace App\Services\Identity;

use App\Enums\AccountStatus;
use App\Exceptions\MemberBootstrapStateChanged;
use App\Jobs\IssueMemberOnboardingInvitation;
use App\Models\Employee;
use App\Models\MemberActivationVerifier;
use App\Models\MemberOnboardingInvitation;
use App\Models\User;
use App\Services\Security\SecurityAuditRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/**
 * First-time account claiming from trusted personnel records.
 *
 * Employee ID, date of birth and phone are knowledge, not possession: this is
 * not MFA. It may only ever activate a never-activated ordinary member account
 * and can never reset or replace an established credential.
 */
final class MemberSelfServiceActivation
{
    public function __construct(
        private readonly MemberActivationVerifierDigest $digests,
        private readonly AccountSecurityService $security,
        private readonly MemberOnboardingInvitationService $invitations,
        private readonly SecurityAuditRecorder $audit,
    ) {}

    public function enabled(): bool
    {
        // Claiming sets a local password, so it follows the local-login switch.
        return (bool) config('identity.self_service_activation.enabled')
            && (bool) config('identity.local_login_enabled')
            && config('identity.credential_authority') === 'local';
    }

    public function emailRequestEnabled(): bool
    {
        return (bool) config('identity.self_service_activation.email_request_enabled');
    }

    /** Returns the activated account, or null for every kind of refusal. */
    public function claim(
        string $employeeId,
        CarbonImmutable $dateOfBirth,
        string $normalizedPhone,
        string $passwordHash,
        CarbonImmutable $at,
    ): ?User {
        return DB::transaction(function () use ($employeeId, $dateOfBirth, $normalizedPhone, $passwordHash, $at): ?User {
            $identity = $this->soleIdentity($employeeId);
            if ($identity === null) {
                return null;
            }
            // Same order as email completion: Invitation -> User -> Employee.
            MemberOnboardingInvitation::query()->where('user_id', $identity['user_id'])->lockForUpdate()->get(['id']);
            $user = User::query()->lockForUpdate()->find($identity['user_id']);
            $employee = Employee::query()->lockForUpdate()->find($identity['employee_profile_id']);
            if (! $user instanceof User || ! $employee instanceof Employee || ! $this->eligible($user, $employee)) {
                return null;
            }
            $verifier = MemberActivationVerifier::query()
                ->where('employee_profile_id', $employee->id)->lockForUpdate()->first();
            if (! $verifier instanceof MemberActivationVerifier
                || ! $verifier->isUsable()
                || $verifier->employee_id !== $employee->employee_id) {
                return null;
            }

            $dobMatches = hash_equals((string) $verifier->dob_digest, $this->digests->dateOfBirth($employee->id, $dateOfBirth));
            $phoneMatches = hash_equals((string) $verifier->phone_digest, $this->digests->phone($employee->id, $normalizedPhone));
            if (! $dobMatches || ! $phoneMatches) {
                $this->recordFailure($verifier, $user, $at);

                return null;
            }

            try {
                $activated = $this->security->completeSelfServiceActivation(
                    $user->id, $employee->id, $user->security_version, $passwordHash, $at,
                );
            } catch (MemberBootstrapStateChanged) {
                return null;
            }
            $verifier->forceFill(['dob_digest' => null, 'phone_digest' => null, 'consumed_at' => $at])->save();

            return $activated;
        }, 3);
    }

    /**
     * Ask the existing invitation pipeline to email the member's approved City
     * address. The caller never learns whether anything was sent.
     */
    public function requestEmailInvitation(string $employeeId): void
    {
        $identity = $this->soleIdentity($employeeId);
        if ($identity === null || config('queue.default') === 'sync') {
            return;
        }
        $employee = Employee::query()->find($identity['employee_profile_id']);
        $user = User::query()->find($identity['user_id']);
        if (! $employee instanceof Employee || ! $user instanceof User
            || $this->hasElevatedAccess($user)
            || $this->invitations->assess($employee)['status'] !== 'ready') {
            return;
        }
        // Bound what a stranger who knows an Employee ID can make the Hub send:
        // two links a day per member, and a ceiling across all members that
        // leaves the shared email budget for administrators and recovery.
        $memberKey = 'member-activation-email:member:'.$user->id;
        $globalKey = 'member-activation-email:global';
        if (RateLimiter::tooManyAttempts($memberKey, 2) || RateLimiter::tooManyAttempts($globalKey, 40)) {
            return;
        }
        RateLimiter::hit($memberKey, 86400);
        RateLimiter::hit($globalKey, 3600);

        $this->audit->record($user, $user, 'member_onboarding_invitation_self_requested', 'allowed', null, [
            'employee_profile_id' => $employee->id,
        ]);
        IssueMemberOnboardingInvitation::dispatch($user->id, null, IssueMemberOnboardingInvitation::bindingHash(
            $user->id, $employee->employee_id, (string) $employee->city_email, $user->security_version,
        ));
    }

    public function eligible(User $user, Employee $employee): bool
    {
        return $user->employee_profile_id === $employee->id
            && $user->employee_id === $employee->employee_id
            && $user->getRawOriginal('account_status') === AccountStatus::PendingActivation->value
            && $user->bootstrap_onboarding_eligible
            && $user->bootstrap_onboarding_completed_at === null
            && $employee->roster_status === 'active'
            && ! $this->hasElevatedAccess($user);
    }

    /** Privileged accounts must be activated through the administrator-issued invitation. */
    public function hasElevatedAccess(User $user): bool
    {
        return $user->roles()->where('name', '!=', 'member')->exists()
            || $user->permissions()->exists();
    }

    /** @return array{user_id:int,employee_profile_id:int}|null */
    private function soleIdentity(string $employeeId): ?array
    {
        $employees = Employee::query()->where('employee_id', $employeeId)->limit(2)->get(['id', 'employee_id']);
        if ($employees->count() !== 1 || $employees->first()->employee_id !== $employeeId) {
            return null;
        }
        $employee = $employees->first();
        $linked = User::query()->where('employee_profile_id', $employee->id)->limit(2)->pluck('id');
        $identified = User::query()->where('employee_id', $employeeId)->limit(2)->pluck('id');
        if ($linked->count() !== 1 || $identified->count() !== 1 || $linked->first() !== $identified->first()) {
            return null;
        }

        return ['user_id' => (int) $linked->first(), 'employee_profile_id' => (int) $employee->id];
    }

    private function recordFailure(MemberActivationVerifier $verifier, User $user, CarbonImmutable $at): void
    {
        $attempts = $verifier->failed_attempts + 1;
        $locked = $attempts >= max(1, (int) config('identity.self_service_activation.max_failed_attempts', 10));
        $verifier->forceFill([
            'failed_attempts' => $attempts,
            'last_failed_at' => $at,
            'locked_at' => $locked ? $at : null,
        ])->save();
        $this->audit->record($user, $user, 'member_self_service_activation_denied', 'denied',
            $locked ? 'verification_locked' : 'verification_mismatch',
            ['employee_profile_id' => $verifier->employee_profile_id, 'failed_attempts' => $attempts],
        );
    }
}
