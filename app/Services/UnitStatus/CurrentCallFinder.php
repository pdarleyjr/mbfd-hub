<?php

declare(strict_types=1);

namespace App\Services\UnitStatus;

use App\Models\UnitStatus\UnitStatusCall;
use App\Models\UnitStatus\UnitStatusCallUnit;
use App\Support\UnitStatus\StatusFamily;

/**
 * Current calls (contract section 5.1): the call is active and not closed, disappeared, merged or a duplicate, and the
 * unit has not cleared. A closed call keeps its units' last status, so the call itself must be live.
 */
final class CurrentCallFinder
{
    /** @return array<string, list<array{call: UnitStatusCall, unit: UnitStatusCallUnit}>> unit code => newest first */
    public function byUnit(): array
    {
        $calls = UnitStatusCall::query()
            ->where('section', 'active')
            ->whereNull('closed_at')
            ->whereNull('disappeared_at')
            ->whereNull('merged_at')
            ->whereNull('duplicate_of')
            ->with('units')
            ->get();

        $byUnit = [];
        foreach ($calls as $call) {
            foreach ($call->units as $unit) {
                if (! StatusFamily::isCleared($unit->status)) {
                    $byUnit[$unit->unit_code][] = ['call' => $call, 'unit' => $unit];
                }
            }
        }
        foreach ($byUnit as $code => $entries) {
            usort($entries, static fn (array $a, array $b): int => self::sortKey($b['call']) <=> self::sortKey($a['call']) ?: strcmp($a['call']->source_call_id, $b['call']->source_call_id));
            $byUnit[$code] = $entries;
        }

        return $byUnit;
    }

    /** Newest received first; a call PulsePoint gave no time for sorts by when the Hub first saw it. */
    private static function sortKey(UnitStatusCall $call): int
    {
        return ($call->received_at ?? $call->first_seen_at)->getTimestamp();
    }
}
