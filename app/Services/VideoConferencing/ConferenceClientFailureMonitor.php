<?php

namespace App\Services\VideoConferencing;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

class ConferenceClientFailureMonitor
{
    private const WINDOW_MINUTES = 15;

    public function record(): void
    {
        $key = $this->bucketKey(CarbonImmutable::now('UTC'));
        Cache::add($key, 0, now()->addMinutes(self::WINDOW_MINUTES + 2));
        Cache::increment($key);
    }

    public function recentCount(): int
    {
        $now = CarbonImmutable::now('UTC');

        return collect(range(0, self::WINDOW_MINUTES - 1))
            ->sum(fn (int $minutesAgo): int => (int) Cache::get(
                $this->bucketKey($now->subMinutes($minutesAgo)),
                0,
            ));
    }

    private function bucketKey(CarbonImmutable $minute): string
    {
        return 'video-conferencing:client-failures:'.$minute->format('YmdHi');
    }
}
