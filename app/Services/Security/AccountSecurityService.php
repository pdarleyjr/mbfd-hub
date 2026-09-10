<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Enums\AccountStatus;
use App\Enums\Security\AccountSecurityAction;
use App\Enums\Security\RecentAuthenticationAction;
use App\Exceptions\CurrentPasswordMismatch;
use App\Models\Employee;
use App\Models\User;
use App\Policies\AccountSecurityPolicy;
use App\Rules\SafeNewPassword;
use App\Services\Communications\CloudflareEmailDispatcher;
use App\Services\Identity\AccountSecurityService as IdentityAccountSecurityService;
use App\Services\Identity\CityEmailVerificationService;
use App\Services\Identity\IdentityProviderService;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Throwable;

final class AccountSecurityService
{
    public function __construct(
        private readonly AccountSecurityPolicy $policy,
        private readonly SecurityAuditRecorder $auditRecorder,
        private readonly IdentityAccountSecurityService $identityAccountSecurity,
        private readonly LastCriticalAdministratorGuard $lastCriticalAdministratorGuard,
        private readonly RecentAuthentication $recentAuthentication,
        private readonly Session $session,
        private readonly IdentityProviderService $identityProvider,
        private readonly CityEmailVerificationService $recoveryEmails,
        private readonly CloudflareEmailDispatcher $emailDispatcher,
    ) {}

    public function disable(User $actor, User $target, string $reason, CarbonInterface $at, ?string $currentPassword = null): User
    {
        return $this->mutate($actor, $target, AccountSecurityAction::Disable, $reason, $at, $currentPassword);
    }

    public function enable(User $actor, User $target, string $reason, CarbonInterface $at, ?string $currentPassword = null): User
    {
        return $this->mutate($actor, $target, AccountSecurityAction::Enable, $reason, $at, $currentPassword);
    }

    /** Plaintext credentials are accepted only at this boundary and never audited or returned. */
    public function resetPassword(User $actor, User $target, string $temporaryPassword, string $reason, CarbonInterface $at, ?string $currentPassword = null): User
    {
        return $this->mutate($actor, $target, AccountSecurityAction::AdministrativeRecovery, $reason, $at, $currentPassword, $temporaryPassword);
    }

    public function forcePasswordChange(User $actor, User $target, string $reason, CarbonInterface $at, ?string $currentPassword = null): User
    {
        return $this->mutate($actor, $target, AccountSecurityAction::ForcePasswordChange, $reason, $at, $currentPassword);
    }

    public function revokeSessions(User $actor, User $target, string $reason, CarbonInterface $at, ?string $currentPassword = null): User
    {
        $updated = $this->mutate($actor, $target, AccountSecurityAction::RevokeSessions, $reason, $at, $currentPassword);
        $this->identityProvider->requestSessionRevocation($updated);

        return $updated;
    }

    public function resetMfa(User $actor, User $target, string $reason, CarbonInterface $at, ?string $currentPassword = null): User
    {
        $updated = $this->mutate($actor, $target, AccountSecurityAction::ResetSecurityState, $reason, $at, $currentPassword);
        $this->identityProvider->requestMfaReset($updated);

        return $updated;
    }

    public function sendIdentityRecovery(User $actor, User $target, string $reason, ?string $currentPassword = null): User
    {
        $action = AccountSecurityAction::AdministrativeRecovery;
        try {
            [$currentActor, $currentTarget, $recipient] = DB::transaction(function () use ($actor, $target, $action, $reason, $currentPassword): array {
                $locked = User::query()->whereKey([$actor->getKey(), $target->getKey()])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                $currentActor = $locked->get($actor->getKey());
                $currentTarget = $locked->get($target->getKey());
                if (! $currentActor instanceof User || ! $currentTarget instanceof User
                    || ! $this->policy->allows($currentActor, $currentTarget, $action)
                    || ! $this->identityProvider->enabledFor($currentTarget)
                    || ! $currentTarget->isUpstreamIdentityEnabled()) {
                    throw new AuthorizationException('The MBFD Identity recovery action is not authorized.');
                }
                $this->requireReason($reason);
                if ($currentPassword === null || ! Hash::check($currentPassword, $currentActor->getAuthPassword())) {
                    throw new CurrentPasswordMismatch('The current password is incorrect.');
                }
                $recipient = $this->recoveryEmails->connectedEmail($currentTarget);
                if ($recipient === null) {
                    throw new AuthorizationException('An authoritative recovery address is required.');
                }
                $this->session->put((string) config('security.recent_authentication.session_key'), time());

                return [$currentActor, $currentTarget, $recipient];
            });
            $url = $this->identityProvider->recoveryLink($currentTarget);
            $this->emailDispatcher->send(
                to: [$recipient],
                subject: 'MBFD Identity account recovery',
                text: "An MBFD administrator sent an individual account activation or recovery link.\n\nContinue securely: {$url}\n\nThis one-time link expires shortly. If you did not expect it, ignore this message and contact an administrator.",
                html: null,
                sourceType: 'identity_administrative_recovery',
                sourceId: (string) $currentTarget->getKey(),
            );
            $this->auditRecorder->record($currentActor, $currentTarget, $action->value, 'allowed', trim($reason));

            return $currentTarget;
        } catch (Throwable $exception) {
            $this->auditRecorder->record($actor, $target, $action->value, $exception instanceof AuthorizationException ? 'denied' : 'failed', mb_substr(trim($reason), 0, 500));
            throw $exception;
        }
    }

    /** Read-only visibility preview. Mutation always repeats this check under locks. */
    public function canPerform(User $actor, User $target, AccountSecurityAction $action): bool
    {
        $currentActor = $actor->fresh();
        $currentTarget = $target->fresh();

        return $currentActor !== null && $currentTarget !== null
            && $this->policy->allows($currentActor, $currentTarget, $action)
            && ($action !== AccountSecurityAction::Enable || $this->rosterAllowsEnable($currentTarget))
            && ($action !== AccountSecurityAction::Disable || $this->lastCriticalAdministratorGuard->allowsDisable($currentTarget));
    }

    /** Authorization-only compatibility API: never records an unperformed action as successful. */
    public function authorize(User $actor, User $target, AccountSecurityAction $action, ?string $reason = null): void
    {
        try {
            if (! $this->canPerform($actor, $target, $action)
                || ! $this->recentAuthentication->isSatisfiedForSession($this->session, RecentAuthenticationAction::SecurityAdministration)) {
                throw new AuthorizationException('Current authority and recent authentication are required.');
            }
            $this->requireReason($reason ?? '');
        } catch (AuthorizationException $exception) {
            $this->auditRecorder->record($actor, $target, $action->value, 'denied', $reason);
            throw $exception;
        }
    }

    private function mutate(User $actor, User $target, AccountSecurityAction $action, string $reason, CarbonInterface $at, ?string $currentPassword, ?string $temporaryPassword = null): User
    {
        try {
            return DB::transaction(function () use ($actor, $target, $action, $reason, $at, $currentPassword, $temporaryPassword): User {
                $this->lastCriticalAdministratorGuard->lockActiveCriticalAdministrators();
                $locked = User::query()->whereKey([$actor->getKey(), $target->getKey()])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                $currentActor = $locked->get($actor->getKey());
                $currentTarget = $locked->get($target->getKey());
                if (! $currentActor instanceof User || ! $currentTarget instanceof User
                    || ! $this->policy->allows($currentActor, $currentTarget, $action)
                    || ($action === AccountSecurityAction::Disable && ! $this->lastCriticalAdministratorGuard->allowsDisable($currentTarget))) {
                    throw new AuthorizationException('The current account-security action is not authorized.');
                }
                if ($action === AccountSecurityAction::Enable && ! $this->rosterAllowsEnable($currentTarget, lock: true)) {
                    throw new AuthorizationException('A departed or inconsistent employee identity cannot be enabled. Review the employment status first.');
                }
                if (in_array($action, [AccountSecurityAction::AdministrativeRecovery, AccountSecurityAction::ForcePasswordChange], true)
                    && config('identity.credential_authority') === 'authentik'
                    && $currentTarget->identityLinks()->where('provider', 'authentik')->exists()) {
                    throw new AuthorizationException('MBFD Identity accounts use individual recovery links, not administrator-set passwords.');
                }
                $this->requireReason($reason);
                // A cached model/hash or session timestamp cannot authorize this write.
                if ($currentPassword === null || ! Hash::check($currentPassword, $currentActor->getAuthPassword())) {
                    throw new CurrentPasswordMismatch('The current password is incorrect.');
                }
                $this->session->put((string) config('security.recent_authentication.session_key'), time());
                if ($action === AccountSecurityAction::AdministrativeRecovery) {
                    Validator::make(['temporary_password' => $temporaryPassword], [
                        'temporary_password' => [new SafeNewPassword],
                    ])->validate();
                }
                if ($action === AccountSecurityAction::AdministrativeRecovery && ($temporaryPassword === null || strlen($temporaryPassword) < 12 || strlen($temporaryPassword) > 255)) {
                    throw new AuthorizationException('A temporary password of 12 to 255 characters is required.');
                }
                $updated = match ($action) {
                    AccountSecurityAction::Disable => $this->identityAccountSecurity->disable($currentTarget, trim($reason), $at),
                    AccountSecurityAction::Enable => $this->identityAccountSecurity->changeStatus($currentTarget, AccountStatus::Active, trim($reason), $at),
                    AccountSecurityAction::AdministrativeRecovery => $this->identityAccountSecurity->setAdministrativeRecoveryPassword($currentTarget, Hash::make((string) $temporaryPassword), $at),
                    AccountSecurityAction::ForcePasswordChange => $this->identityAccountSecurity->forcePasswordChange($currentTarget, $at),
                    AccountSecurityAction::RevokeSessions => $this->identityAccountSecurity->revokeAll($currentTarget, trim($reason), $at),
                    AccountSecurityAction::ResetSecurityState => $this->identityAccountSecurity->revokeAll($currentTarget, trim($reason), $at),
                    default => throw new AuthorizationException('Unsupported administrative action.'),
                };
                $this->auditRecorder->record($currentActor, $updated, $action->value, 'allowed', trim($reason));

                return $updated;
            });
        } catch (Throwable $exception) {
            $this->auditRecorder->record($actor, $target, $action->value, $exception instanceof AuthorizationException ? 'denied' : 'failed', mb_substr(trim($reason), 0, 500));
            throw $exception;
        }
    }

    private function requireReason(string $reason): void
    {
        if (trim($reason) === '' || mb_strlen($reason) > 500) {
            throw new AuthorizationException('An audit reason of 1 to 500 characters is required.');
        }
    }

    private function rosterAllowsEnable(User $target, bool $lock = false): bool
    {
        if ($target->employee_profile_id === null) {
            return true;
        }
        // Match the directory mutation lock order: current Users, then Employee.
        $query = Employee::query()->whereKey($target->employee_profile_id);
        if ($lock) {
            $query->lockForUpdate();
        }
        $employee = $query->first();

        return $employee !== null && $employee->employee_id === $target->employee_id
            && $employee->roster_status !== 'departed';
    }
}
