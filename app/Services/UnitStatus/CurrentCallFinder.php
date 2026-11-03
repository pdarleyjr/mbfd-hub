<?php

declare(strict_types=1);

namespace App\Services\UnitStatus;

use App\Models\UnitStatus\UnitStatusCall;
use App\Models\UnitStatus\UnitStatusCallUnit;
use App\Support\UnitStatus\StatusFamily;
use Carbon\CarbonImmutable;

/**
 * Current calls (contract section 5.1): the call is active and not closed, disappeared, merged or a duplicate, the
 * unit has not cleared, and the call is not older than the current-call age limit. A closed call keeps its units'
 * last status, so the call itself must be live.
 */
final class CurrentCallFinder
{
    /**
     * `current` is newest first. `aged` lists units with an open call older than `current_call_max_age_hours`: a call
     * PulsePoint never closed is not evidence that the unit is still on it, so the unit is "not confirmed".
     *
     * @return array{current: array<string, list<array{call: UnitStatusCall, unit: UnitStatusCallUnit}>>, aged: array<string, true>}
     */
    public function find(CarbonImmutable $now): array
    {
        $cutoff = $now->subHours((int) config('unit_status.current_call_max_age_hours', 12));
        $calls = UnitStatusCall::query()
            ->where('section', 'active')
            ->whereNull('closed_at')
            ->whereNull('disappeared_at')
            ->whereNull('merged_at')
            ->whereNull('duplicate_of')
            ->with('units')
            ->get();

        $byUnit = [];
        $aged = [];
        foreach ($calls as $call) {
            $isAged = ($call->received_at ?? $call->first_seen_at)->lessThan($cutoff);
            foreach ($call->units as $unit) {
                if (StatusFamily::isCleared($unit->status)) {
                    continue;
                }
                if ($isAged) {
                    $aged[(string) $unit->unit_code] = true;
                } else {
                    $byUnit[(string) $unit->unit_code][] = ['call' => $call, 'unit' => $unit];
                }
            }
        }
        foreach ($byUnit as $code => $entries) {
            usort($entries, static fn (array $a, array $b): int => self::sortKey($b['call']) <=> self::sortKey($a['call']) ?: strcmp($a['call']->source_call_id, $b['call']->source_call_id));
            $byUnit[$code] = $entries;
        }
        // A unit with a fresh open call is on that call; the aged one only matters when nothing else is current.
        $aged = array_diff_key($aged, $byUnit);

        return ['current' => $byUnit, 'aged' => $aged];
    }

    /** Newest received first; a call PulsePoint gave no time for sorts by when the Hub first saw it. */
    private static function sortKey(UnitStatusCall $call): int
    {
        return ($call->received_at ?? $call->first_seen_at)->getTimestamp();
    }
}
