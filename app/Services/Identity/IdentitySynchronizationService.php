<?php

declare(strict_types=1);

namespace App\Services\Identity;

use App\Jobs\SynchronizeIdentity;
use App\Models\IdentitySynchronization;
use App\Models\User;

final class IdentitySynchronizationService
{
    public function request(User $user, bool $revokeSessions = false, bool $resetMfa = false): ?IdentitySynchronization
    {
        $current = $user->fresh();
        if (! $current instanceof User
            || ! $current->identityLinks()->where('provider', 'authentik')->exists()) {
            return null;
        }
        $synchronization = IdentitySynchronization::query()->firstOrCreate([
            'provider' => 'authentik',
            'user_id' => $current->getKey(),
            'requested_security_version' => $current->security_version,
        ], [
            'desired_active' => $current->isUpstreamIdentityEnabled(),
            'revoke_sessions' => $revokeSessions,
            'reset_mfa' => $resetMfa,
            'state' => 'pending',
        ]);
        if (! $synchronization->wasRecentlyCreated) {
            $synchronization->forceFill([
                'desired_active' => $current->isUpstreamIdentityEnabled(),
                'revoke_sessions' => $synchronization->revoke_sessions || $revokeSessions,
                'reset_mfa' => $synchronization->reset_mfa || $resetMfa,
                'state' => 'pending',
                'completed_at' => null,
            ])->save();
        }

        SynchronizeIdentity::dispatch($synchronization->getKey())->afterCommit();

        return $synchronization;
    }
}
