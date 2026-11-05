<?php

declare(strict_types=1);

namespace App\Services\UnitStatus;

use App\Models\UnitStatus\UnitStatusCoverageGap;
use App\Models\UnitStatus\UnitStatusSyncState;
use Carbon\CarbonImmutable;
use Throwable;

/** Reads and writes the single sync-state row: tracking start, cursor, failures, the circuit breaker and coverage gaps. */
final class SyncStateKeeper
{
    /** Created once. `tracking_starts_at` is read from config only here and is never changed afterwards. */
    public function ensure(CarbonImmutable $now): UnitStatusSyncState
    {
        $state = UnitStatusSyncState::current();
        if ($state !== null) {
            return $state;
        }

        return UnitStatusSyncState::query()->create([
            'id' => UnitStatusSyncState::ROW_ID,
            'tracking_starts_at' => $this->configuredTrackingStart($now),
        ]);
    }

    public function circuitIsOpen(UnitStatusSyncState $state, CarbonImmutable $now): bool
    {
        return $state->circuit_open_until !== null && $state->circuit_open_until->greaterThan($now);
    }

    public function recordSuccess(UnitStatusSyncState $state, CarbonImmutable $now, int $skipped): void
    {
        $state->forceFill([
            'last_success_at' => $now,
            'consecutive_failures' => 0,
            'circuit_open_until' => null,
            'last_error_class' => null,
            'rows_skipped_total' => $state->rows_skipped_total + $skipped,
        ])->save();
    }

    /** After the threshold, the network is skipped for min(base * 2^(n - threshold), max) seconds. */
    public function recordFailure(UnitStatusSyncState $state, CarbonImmutable $now, string $errorClass): void
    {
        $failures = $state->consecutive_failures + 1;
        $threshold = (int) config('unit_status.breaker_threshold', 3);
        $delay = $failures >= $threshold
            ? min((int) config('unit_status.breaker_base_seconds', 30) * (2 ** ($failures - $threshold)), (int) config('unit_status.breaker_max_seconds', 300))
            : 0;
        $state->forceFill([
            'consecutive_failures' => $failures,
            'last_error_class' => $errorClass,
            'circuit_open_until' => $delay > 0 ? $now->addSeconds($delay) : null,
        ])->save();
    }

    /** Remember what Command says about the collector, and open or close a coverage gap to match. */
    public function recordHealth(UnitStatusSyncState $state, SourceFeedPage $page, CarbonImmutable $now): void
    {
        $health = $page->healthState();
        $readAt = $page->lastSourceReadAt();
        $state->forceFill([
            'source_state' => $health,
            'source_health' => $page->health,
            'source_checked_at' => $readAt !== null && $readAt->lessThanOrEqualTo($now) ? $readAt : $state->source_checked_at,
        ])->save();
        $this->trackGap($health, $readAt, $now);
    }

    private function trackGap(?string $health, ?CarbonImmutable $readAt, CarbonImmutable $now): void
    {
        $open = UnitStatusCoverageGap::query()->whereNull('ended_at')->orderBy('id')->get();
        $reason = $health === null || $health === 'live' ? null : 'feed_'.preg_replace('/[^a-z_]/', '', strtolower($health));
        foreach ($open as $gap) {
            if ($gap->reason !== $reason) {
                $gap->forceFill(['ended_at' => $now])->save();
            }
        }
        if ($reason !== null && ! $open->contains('reason', $reason)) {
            $start = $readAt !== null && $readAt->lessThanOrEqualTo($now) ? $readAt : $now;
            UnitStatusCoverageGap::query()->create(['started_at' => $start, 'reason' => $reason]);
        }
    }

    private function configuredTrackingStart(CarbonImmutable $now): CarbonImmutable
    {
        $zone = (string) config('unit_status.timezone', 'America/New_York');
        try {
            return CarbonImmutable::parse((string) config('unit_status.tracking_starts_at'), $zone)->utc();
        } catch (Throwable) {
            // An unreadable setting must not invent a start in the past: tracking begins when the first sync runs.
            return $now;
        }
    }
}
