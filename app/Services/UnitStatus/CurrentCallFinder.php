<?php

declare(strict_types=1);

namespace App\Services\UnitStatus;

use App\Models\UnitStatus\UnitStatusCall;
use App\Models\UnitStatus\UnitStatusCallUnit;
use App\Support\UnitStatus\StatusFamily;
use Carbon\CarbonImmutable;

/**
 * Current calls (contract sections 5.1 and 10.1). The call must be active and not closed, disappeared, merged or a
 * duplicate, and the unit must not have cleared. PulsePoint also leaves calls open and units unconfirmed long after
 * the fact, so a unit is placed on a call only when all of these hold, none of which changes Command's data:
 *
 * 1. A "?" unit is never on a call (`unconfirmed_grace_minutes` = 0); a raised value allows it that many minutes.
 * 2. A confirmed unit still Dispatched, Acknowledged, blank or unrecognised `stalled_dispatch_minutes` after it was
 *    listed on the call (the later of the call's receipt and the unit joining) has moved on.
 * 3. A unit on two current calls is on the newer one only.
 * 4. A call open longer than `current_call_max_age_hours` is not evidence: the unit is "not confirmed", not available.
 *
 * A unit whose calls all drop out under 1-3 simply has no current call.
 */
final class CurrentCallFinder
{
    /**
     * @return array{current: array<string, list<array{call: UnitStatusCall, unit: UnitStatusCallUnit}>>, aged: array<string, true>}
     */
    public function find(CarbonImmutable $now): array
    {
        $calls = UnitStatusCall::query()
            ->where('section', 'active')
            ->whereNull('closed_at')
            ->whereNull('disappeared_at')
            ->whereNull('merged_at')
            ->whereNull('duplicate_of')
            ->with('units')
            ->get();

        $candidates = [];
        foreach ($calls as $call) {
            foreach ($call->units as $unit) {
                if ($this->placesUnitOnCall($call, $unit, $now)) {
                    $candidates[(string) $unit->unit_code][] = ['call' => $call, 'unit' => $unit];
                }
            }
        }

        $cutoff = $now->subHours((int) config('unit_status.current_call_max_age_hours', 12));
        $current = [];
        $aged = [];
        foreach ($candidates as $code => $entries) {
            usort($entries, static fn (array $a, array $b): int => self::sortKey($b['call']) <=> self::sortKey($a['call']) ?: $b['call']->first_seen_at->getTimestamp() <=> $a['call']->first_seen_at->getTimestamp() ?: strcmp($a['call']->source_call_id, $b['call']->source_call_id));
            $newest = $entries[0];
            if (self::anchor($newest['call'])->lessThan($cutoff)) {
                $aged[$code] = true;
            } else {
                $current[$code] = [$newest];
            }
        }

        return ['current' => $current, 'aged' => $aged];
    }

    private function placesUnitOnCall(UnitStatusCall $call, UnitStatusCallUnit $unit, CarbonImmutable $now): bool
    {
        if (StatusFamily::isCleared($unit->status)) {
            return false;
        }
        if (! $unit->confirmed) {
            $grace = (int) config('unit_status.unconfirmed_grace_minutes', 0);

            return $grace > 0 && self::anchor($call)->diffInMinutes($now, false) <= $grace;
        }
        // Waiting means Dispatched, Acknowledged, blank or any word the board does not recognise. The clock starts when
        // the unit joined the call, so a unit added late to an older call is not dropped the moment it appears.
        $family = StatusFamily::family($unit->status);
        $waiting = $family === 'dispatched' || $family === StatusFamily::UNKNOWN;
        $listedSince = max(self::anchor($call), $unit->first_seen_on_call_at);

        return ! ($waiting && $listedSince->diffInMinutes($now, false) > (int) config('unit_status.stalled_dispatch_minutes', 10));
    }

    /** When the call was received; a call PulsePoint gave no time for is dated by when the Hub first saw it. */
    private static function anchor(UnitStatusCall $call): CarbonImmutable
    {
        return $call->received_at ?? $call->first_seen_at;
    }

    private static function sortKey(UnitStatusCall $call): int
    {
        return self::anchor($call)->getTimestamp();
    }
}
