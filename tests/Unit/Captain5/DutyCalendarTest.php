<?php

declare(strict_types=1);

namespace Tests\Unit\Captain5;

use App\Support\Captain5\DutyCalendar;
use Carbon\CarbonImmutable;
use Tests\TestCase;

final class DutyCalendarTest extends TestCase
{
    public function test_duty_day_runs_from_eight_to_eight_eastern(): void
    {
        $calendar = new DutyCalendar;
        self::assertSame('2026-10-20', $calendar->dutyDate(CarbonImmutable::parse('2026-10-20 08:00:00', 'America/New_York'))->format('Y-m-d'));
        self::assertSame('2026-10-20', $calendar->dutyDate(CarbonImmutable::parse('2026-10-21 07:59:59', 'America/New_York'))->format('Y-m-d'));
        self::assertSame('2026-10-19', $calendar->dutyDate(CarbonImmutable::parse('2026-10-20 07:59:59', 'America/New_York'))->format('Y-m-d'));
        // UTC input is converted (12:30Z = 08:30 EDT).
        self::assertSame('2026-10-20', $calendar->dutyDate(CarbonImmutable::parse('2026-10-20 12:30:00', 'UTC'))->format('Y-m-d'));

        [$start, $end] = $calendar->window('2026-10-20');
        self::assertSame('2026-10-20T12:00:00+00:00', $start->toIso8601String());
        self::assertSame('2026-10-21T12:00:00+00:00', $end->toIso8601String());
    }

    public function test_rotation_matches_the_production_inspection_evidence(): void
    {
        $calendar = new DutyCalendar;
        // Shift values recorded on production Daily Checkouts (duty-day adjusted), 2026-10-23 check.
        foreach (['2026-03-09' => 'A', '2026-03-17' => 'C', '2026-03-18' => 'A', '2026-04-05' => 'A',
            '2026-09-06' => 'B', '2026-09-23' => 'A', '2026-09-24' => 'B', '2026-10-21' => 'B'] as $date => $shift) {
            self::assertSame($shift, $calendar->shiftFor($date), $date);
        }
        self::assertSame('C', $calendar->shiftFor('2026-03-08'), 'Works before the anchor too');
    }

    public function test_rotation_can_be_disabled_and_never_guesses(): void
    {
        config(['captain5.shift_rotation.enabled' => false]);
        self::assertNull((new DutyCalendar)->shiftFor('2026-10-20'));
        config(['captain5.shift_rotation.enabled' => true, 'captain5.shift_rotation.anchor_date' => 'not-a-date']);
        self::assertNull((new DutyCalendar)->shiftFor('2026-10-20'));
    }

    public function test_bid_shift_labels(): void
    {
        self::assertSame('A', DutyCalendar::letterFromBidShift('A Shift'));
        self::assertSame('C', DutyCalendar::letterFromBidShift(' c shift '));
        self::assertNull(DutyCalendar::letterFromBidShift('D Shift'));
        self::assertNull(DutyCalendar::letterFromBidShift('Days'));
        self::assertNull(DutyCalendar::letterFromBidShift(null));
    }
}
