<?php

declare(strict_types=1);

namespace App\Services\UnitStatus;

use App\Models\UnitStatus\UnitStatusSyncState;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One sync run (contract section 2): delta from the saved cursor minus an overlap, page by page, every row applied
 * idempotently, then merge housekeeping when due. Failures feed a circuit breaker; nothing here ever resets a count.
 */
final class UnitStatusSync
{
    public function __construct(
        private readonly CommandSourceFeedClient $client,
        private readonly CallApplier $applier,
        private readonly SyncStateKeeper $keeper,
        private readonly MergeHousekeeping $housekeeping,
    ) {}

    public function run(?CarbonImmutable $now = null): SyncOutcome
    {
        $now ??= CarbonImmutable::now();
        if (! (bool) config('unit_status.enabled')) {
            return new SyncOutcome(SyncOutcome::SKIPPED, 'Unit Status is switched off.');
        }
        $problem = $this->client->configurationProblem();
        if ($problem !== null) {
            return new SyncOutcome(SyncOutcome::SKIPPED, 'Unit Status source feed is not configured ('.$problem.').');
        }

        $state = $this->keeper->ensure($now);
        if ($this->keeper->circuitIsOpen($state, $now)) {
            return new SyncOutcome(SyncOutcome::SKIPPED, 'Circuit open until '.$state->circuit_open_until?->toIso8601String().'.');
        }
        $state->forceFill(['last_attempt_at' => $now])->save();

        try {
            return $this->sync($state, $now);
        } catch (SourceFeedUnavailable $exception) {
            $this->keeper->recordFailure($state, $now, $exception->reason);
            Log::warning('unit_status.sync.feed_failed', ['reason' => $exception->reason, 'failures' => $state->consecutive_failures]);

            return new SyncOutcome(SyncOutcome::FEED_FAILED, 'Feed unavailable: '.$exception->reason);
        } catch (Throwable $exception) {
            $this->keeper->recordFailure($state, $now, 'hub_error');
            Log::error('unit_status.sync.hub_error', ['exception' => $exception::class, 'message' => $exception->getMessage()]);

            return new SyncOutcome(SyncOutcome::HUB_ERROR, 'Sync failed inside the Hub: '.$exception::class);
        }
    }

    private function sync(UnitStatusSyncState $state, CarbonImmutable $now): SyncOutcome
    {
        $applied = 0;
        $skipped = 0;
        $pages = 0;
        $context = new SyncContext($now, $state->tracking_starts_at, $state->first_synced_at ?? $now);
        [$since, $afterId] = [$this->startCursor($state), null];

        do {
            $page = $this->client->delta($since, $afterId);
            $pages++;
            $this->markFirstSync($state, $now);
            foreach ($page->calls as $call) {
                $this->applier->apply($call, $context);
                $applied++;
            }
            $skipped += $page->skipped;
            $this->keeper->recordHealth($state, $page, $now);
            $this->advanceCursor($state, $page);
            [$since, $afterId] = [$page->cursorUpdatedSince ?? $since, $page->cursorAfterId];
        } while ($page->hasMore && $pages < (int) config('unit_status.max_pages', 10));

        $this->runHousekeeping($state, $context);
        $this->keeper->recordSuccess($state, $now, $skipped);
        if ($skipped > 0) {
            Log::warning('unit_status.sync.rows_skipped', ['skipped' => $skipped]);
        }

        return new SyncOutcome(SyncOutcome::OK, 'Applied '.$applied.' call rows.', $applied, $skipped, $pages);
    }

    /** The saved cursor minus the overlap; the very first run starts before tracking began to catch calls still running. */
    private function startCursor(UnitStatusSyncState $state): CarbonImmutable
    {
        if ($state->cursor_updated_since === null) {
            return $state->tracking_starts_at->subHours((int) config('unit_status.first_run_lookback_hours', 12));
        }

        return $state->cursor_updated_since->subSeconds((int) config('unit_status.overlap_seconds', 120));
    }

    private function markFirstSync(UnitStatusSyncState $state, CarbonImmutable $now): void
    {
        if ($state->first_synced_at === null) {
            $state->forceFill(['first_synced_at' => $now])->save();
        }
    }

    /** A failed lookup must not turn a good delta into a failed run; it is retried on the next run. */
    private function runHousekeeping(UnitStatusSyncState $state, SyncContext $context): void
    {
        if (! $this->housekeeping->due($state, $context)) {
            return;
        }
        try {
            $this->housekeeping->run($state, $context);
        } catch (SourceFeedUnavailable $exception) {
            Log::warning('unit_status.sync.merge_check_failed', ['reason' => $exception->reason]);
        }
    }

    /** The high-water mark only moves forward, so an empty page that echoes the overlapped cursor never drags it back. */
    private function advanceCursor(UnitStatusSyncState $state, SourceFeedPage $page): void
    {
        $next = $page->cursorUpdatedSince;
        if ($next === null || ($state->cursor_updated_since !== null && $next->lessThanOrEqualTo($state->cursor_updated_since))) {
            return;
        }
        $state->forceFill(['cursor_updated_since' => $next, 'cursor_after_id' => $page->cursorAfterId])->save();
    }
}
