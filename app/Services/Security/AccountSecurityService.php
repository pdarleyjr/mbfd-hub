<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Enums\Security\AccountSecurityAction;
use App\Models\User;
use App\Policies\AccountSecurityPolicy;
use Illuminate\Auth\Access\AuthorizationException;

final class AccountSecurityService
{
    public function __construct(
        private readonly AccountSecurityPolicy $policy,
        private readonly SecurityAuditRecorder $auditRecorder,
    ) {}

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

        $this->auditRecorder->record($actor, $target, $action->value, 'allowed', $reason);
    }
}
