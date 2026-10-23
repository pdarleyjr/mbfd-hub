<?php

declare(strict_types=1);

namespace App\Support\Captain5;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Duty-day arithmetic for Captain 5 (docs/captain5-ems-operations-center.md section 1).
 *
 * A duty day runs from `duty_day_start_hour` (08:00 America/New_York) to the same hour the
 * next morning. The on-duty shift letter follows the A/B/C 24-hour rotation anchored in
 * config; when the rotation is disabled or misconfigured the shift is unknown (null), never
 * guessed.
 */
final class DutyCalendar
{
    public function timezone(): string
    {
        return (string) config('captain5.timezone', 'America/New_York');
    }

    /** The calendar date (Y-m-d, local) of the duty day containing $moment. */
    public function dutyDate(?DateTimeInterface $moment = null): CarbonImmutable
    {
        $local = CarbonImmutable::instance($moment ?? now())->setTimezone($this->timezone());

        // Wall-clock comparison, not elapsed hours, so the two DST Sundays resolve like window() does.
        return $local->hour < $this->startHour() ? $local->subDay()->startOfDay() : $local->startOfDay();
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} UTC start/end of a duty date */
    public function window(DateTimeInterface|string $dutyDate): array
    {
        $date = is_string($dutyDate)
            ? CarbonImmutable::createFromFormat('!Y-m-d', $dutyDate, $this->timezone())
            : CarbonImmutable::instance($dutyDate)->setTimezone($this->timezone())->startOfDay();
        $start = $date->setTime($this->startHour(), 0);

        return [$start->utc(), $start->addDay()->utc()];
    }

    /** On-duty shift letter for a duty date, or null when the rotation is not configured. */
    public function shiftFor(DateTimeInterface|string $dutyDate): ?string
    {
        $rotation = (array) config('captain5.shift_rotation', []);
        $order = array_values(array_filter((array) ($rotation['order'] ?? []), 'is_string'));
        $anchorShift = (string) ($rotation['anchor_shift'] ?? '');
        $anchorDate = (string) ($rotation['anchor_date'] ?? '');
        if (! ($rotation['enabled'] ?? false) || $order === [] || ! in_array($anchorShift, $order, true)
            || ! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $anchorDate)) {
            return null;
        }

        $date = is_string($dutyDate) ? $dutyDate : CarbonImmutable::instance($dutyDate)->setTimezone($this->timezone())->format('Y-m-d');
        $days = (int) CarbonImmutable::createFromFormat('!Y-m-d', $anchorDate, 'UTC')
            ->diffInDays(CarbonImmutable::createFromFormat('!Y-m-d', $date, 'UTC'), false);
        $count = count($order);
        $index = ((array_search($anchorShift, $order, true) + $days) % $count + $count) % $count;

        return $order[$index];
    }

    /** "A Shift" / "B Shift" ... from a bid shift label; null for D Shift, Days or unknown. */
    public static function letterFromBidShift(?string $label): ?string
    {
        return preg_match('/^\s*([ABC])\s+Shift\s*$/i', (string) $label, $match) === 1 ? strtoupper($match[1]) : null;
    }

    private function startHour(): int
    {
        return max(0, min(23, (int) config('captain5.duty_day_start_hour', 8)));
    }
}
