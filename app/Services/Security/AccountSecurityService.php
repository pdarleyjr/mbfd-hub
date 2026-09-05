<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Enums\AccountStatus;
use App\Enums\Security\AccountSecurityAction;
use App\Enums\Security\RecentAuthenticationAction;
use App\Models\User;
use App\Policies\AccountSecurityPolicy;
use App\Services\Identity\AccountSecurityService as IdentityAccountSecurityService;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

final class AccountSecurityService
{
    public function __construct(
        private readonly AccountSecurityPolicy $policy,
        private readonly SecurityAuditRecorder $auditRecorder,
        private readonly IdentityAccountSecurityService $identityAccountSecurity,
        private readonly LastCriticalAdministratorGuard $lastCriticalAdministratorGuard,
        private readonly RecentAuthentication $recentAuthentication,
        private readonly Session $session,
    ) {}

    /**
     * @throws AuthorizationException
     */
    public function disable(User $actor, User $target, string $reason, CarbonInterface $at): User
    {
        $this->authorize($actor, $target, AccountSecurityAction::Disable, $reason);

        try {
            return DB::transaction(function () use ($target, $reason, $at): User {
                if (! $this->lastCriticalAdministratorGuard->allowsDisable($target, lockForUpdate: true)) {
                    throw new AuthorizationException('The final active Super Administrator cannot be disabled.');
                }

                return $this->identityAccountSecurity->disable($target, $reason, $at);
            });
        } catch (AuthorizationException $exception) {
            $this->auditRecorder->record($actor, $target, AccountSecurityAction::Disable->value, 'denied', 'last active super administrator');

            throw $exception;
        }
    }

    /**
     * @throws AuthorizationException
     */
    public function enable(User $actor, User $target, string $reason, CarbonInterface $at): User
    {
        $this->authorize($actor, $target, AccountSecurityAction::Enable, $reason);

        return $this->identityAccountSecurity->changeStatus($target, AccountStatus::Active, $reason, $at);
    }

    /**
     * Accepts a one-time plaintext value at the write boundary and never returns it.
     *
     * @throws AuthorizationException
     */
    public function resetPassword(
        User $actor,
        User $target,
        string $temporaryPassword,
        string $reason,
        CarbonInterface $at,
    ): User {
        $this->authorize($actor, $target, AccountSecurityAction::AdministrativeRecovery, $reason);

        return $this->identityAccountSecurity->setAdministrativeRecoveryPassword(
            $target,
            Hash::make($temporaryPassword),
            $at,
        );
    }

    /** @throws AuthorizationException */
    public function forcePasswordChange(User $actor, User $target, string $reason, CarbonInterface $at): User
    {
        $this->authorize($actor, $target, AccountSecurityAction::ForcePasswordChange, $reason);

        return $this->identityAccountSecurity->forcePasswordChange($target, $at);
    }

    /** @throws AuthorizationException */
    public function revokeSessions(User $actor, User $target, string $reason, CarbonInterface $at): User
    {
        $this->authorize($actor, $target, AccountSecurityAction::RevokeSessions, $reason);

        return $this->identityAccountSecurity->revokeAll($target, $reason, $at);
    }

    /**
     * This only authorizes and records a future administrative action. It never
     * receives, generates, or transmits a password or recovery secret.
     *
     * @throws AuthorizationException
     */
    public function authorize(
        User $actor,
        User $target,
        AccountSecurityAction $action,
        ?string $reason = null,
    ): void {
        if (! $this->policy->allows($actor, $target, $action)) {
            $this->auditRecorder->record($actor, $target, $action->value, 'denied', $reason);

            throw new AuthorizationException('The requested account-security action is not authorized.');
        }

        if (! $this->recentAuthentication->isSatisfiedForSession(
            $this->session,
            RecentAuthenticationAction::SecurityAdministration,
        )) {
            $this->auditRecorder->record($actor, $target, $action->value, 'denied', $reason);

            throw new AuthorizationException('Recent authentication is required for account-security administration.');
        }

        $this->auditRecorder->record($actor, $target, $action->value, 'allowed', $reason);
    }
}
