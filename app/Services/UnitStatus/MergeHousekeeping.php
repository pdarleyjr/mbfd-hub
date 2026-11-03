<?php

declare(strict_types=1);

namespace App\Services\UnitStatus;

use App\Models\UnitStatus\UnitStatusCall;
use App\Models\UnitStatus\UnitStatusSyncState;

/**
 * Every few minutes, asks Command about calls that are still provisional. A `missing` id that was seen recently is a
 * provisional copy Command folded into another call, so its credit is retired; the surviving call is credited on its own.
 */
final class MergeHousekeeping
{
    public function __construct(
        private readonly CommandSourceFeedClient $client,
        private readonly CallApplier $applier,
        private readonly RunLedger $ledger,
    ) {}

    public function due(UnitStatusSyncState $state, SyncContext $context): bool
    {
        return $state->last_merge_check_at === null
            || $state->last_merge_check_at->addSeconds((int) config('unit_status.merge_check_seconds', 300))->lessThanOrEqualTo($context->now);
    }

    /** @return int Calls retired as merged */
    public function run(UnitStatusSyncState $state, SyncContext $context): int
    {
        $retired = 0;
        $since = $context->now->subHours((int) config('unit_status.merge_lookback_hours', 24));
        // Every provisional call seen recently, and every one still counted as current however old: if Command folded
        // that copy away while the Hub could not read the feed, only a lookup retires it.
        $ids = UnitStatusCall::query()
            ->whereNull('external_id')
            ->whereNull('merged_at')
            ->whereNull('duplicate_of')
            ->where(static function ($query) use ($since): void {
                $query->where('last_seen_at', '>=', $since)
                    ->orWhere(static function ($open): void {
                        $open->where('section', 'active')->whereNull('closed_at')->whereNull('disappeared_at');
                    });
            })
            ->orderBy('source_call_id')
            ->pluck('source_call_id')
            ->all();

        foreach (array_chunk($ids, 100) as $chunk) {
            $page = $this->client->lookup($chunk);
            foreach ($page->calls as $call) {
                $this->applier->apply($call, $context);
            }
            foreach ($page->missing as $missingId) {
                $call = in_array($missingId, $chunk, true) ? UnitStatusCall::query()->find($missingId) : null;
                if ($call !== null && $call->external_id === null) {
                    $this->ledger->markMerged($call, $context->now);
                    $retired++;
                }
            }
        }
        $state->forceFill(['last_merge_check_at' => $context->now])->save();

        return $retired;
    }
}
