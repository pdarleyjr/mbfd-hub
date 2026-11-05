<?php

declare(strict_types=1);

namespace App\Services\UnitStatus;

use App\Models\UnitStatus\UnitStatusSyncState;
use Carbon\CarbonImmutable;

/**
 * The feed is fresh only when the last successful sync is recent AND Command reports the collector live. Anything
 * else is reported as what it is, and the page then never shows green.
 */
final class FeedStatusResolver
{
    public function __construct(private readonly CommandSourceFeedClient $client) {}

    public function resolve(CarbonImmutable $now): FeedStatus
    {
        if ($this->client->configurationProblem() !== null) {
            return new FeedStatus(FeedStatus::NOT_CONFIGURED, false, null, null);
        }
        $state = UnitStatusSyncState::current();
        $success = $state?->last_success_at;
        if ($state === null || $success === null) {
            return new FeedStatus($state !== null && $state->consecutive_failures > 0 ? FeedStatus::UNREACHABLE : FeedStatus::STARTING, false, null, null);
        }

        $syncIsRecent = $success->greaterThanOrEqualTo($now->subSeconds((int) config('unit_status.sync_fresh_seconds', 150)));
        $reported = match (true) {
            ! $syncIsRecent => $state->consecutive_failures > 0 ? FeedStatus::UNREACHABLE : FeedStatus::DELAYED,
            // Rows are still waiting to be read: what the page shows may be missing recent calls.
            $state->backlog => FeedStatus::DELAYED,
            $state->source_state === 'live' => FeedStatus::LIVE,
            $state->source_state === 'delayed' => FeedStatus::DELAYED,
            $state->source_state === 'unavailable' => FeedStatus::UNAVAILABLE,
            default => FeedStatus::STARTING,
        };

        return new FeedStatus($reported, $reported === FeedStatus::LIVE, $success, $state->source_checked_at, $syncIsRecent && $state->backlog);
    }
}
