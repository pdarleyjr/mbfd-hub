<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\Identity\IdentityProvider;
use App\Models\IdentitySynchronization;
use App\Models\UserIdentityLink;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

final class SynchronizeIdentity implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 6;

    /** @var list<int> */
    public array $backoff = [10, 30, 60, 300, 900];

    public function __construct(public readonly int $synchronizationId) {}

    public function handle(IdentityProvider $provider): void
    {
        $synchronization = IdentitySynchronization::query()->with('user')->find($this->synchronizationId);
        if (! $synchronization instanceof IdentitySynchronization || $synchronization->state === 'completed') {
            return;
        }
        $user = $synchronization->user;
        $link = $user?->identityLinks()->where('provider', $synchronization->provider)->first();
        if ($user === null || ! $link instanceof UserIdentityLink) {
            $synchronization->forceFill([
                'state' => 'failed',
                'last_error' => 'Missing canonical user or immutable provider link.',
                'last_attempted_at' => now(),
            ])->save();

            return;
        }

        $synchronization->forceFill([
            'state' => 'processing',
            'attempts' => $synchronization->attempts + 1,
            'last_attempted_at' => now(),
            'last_error' => null,
        ])->save();

        try {
            $provider->synchronize($user, $link);
            if ($synchronization->reset_mfa) {
                $provider->resetMfa($user, $link);
            }
            if ($synchronization->revoke_sessions || $synchronization->reset_mfa || ! $user->isUpstreamIdentityEnabled()) {
                $provider->revokeSessions($user, $link);
            }
            $securityState = $provider->securityState($user, $link);
        } catch (Throwable $exception) {
            $synchronization->forceFill([
                'state' => 'failed',
                'last_error' => $exception::class,
            ])->save();
            throw $exception;
        }

        $link->forceFill([
            'status' => $user->isUpstreamIdentityEnabled() ? 'active' : 'disabled',
            'last_synced_at' => now(),
            'security_state' => $securityState,
        ])->save();
        $synchronization->forceFill([
            'state' => 'completed',
            'completed_at' => now(),
        ])->save();
    }
}
