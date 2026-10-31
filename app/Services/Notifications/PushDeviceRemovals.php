<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use Illuminate\Support\Facades\Cache;

/**
 * Remembers that a member removed a device from their list, so the automatic repair does not quietly add it
 * back the next time that device opens the Hub. Turning alerts on again on the device itself clears it.
 */
final class PushDeviceRemovals
{
    private const TTL_DAYS = 90;

    public function remember(string $endpoint): void
    {
        Cache::put($this->key(hash('sha256', $endpoint)), true, now()->addDays(self::TTL_DAYS));
    }

    public function forget(string $endpoint): void
    {
        Cache::forget($this->key(hash('sha256', $endpoint)));
    }

    public function wasRemoved(string $endpointHash): bool
    {
        return Cache::has($this->key($endpointHash));
    }

    private function key(string $endpointHash): string
    {
        return 'push-device-removed:'.$endpointHash;
    }
}
