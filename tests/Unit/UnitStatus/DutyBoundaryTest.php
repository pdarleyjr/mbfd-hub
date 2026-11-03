<?php

declare(strict_types=1);

namespace Tests\Unit\UnitStatus;

use App\Support\Captain5\DutyCalendar;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/** The shift count is "runs whose duty date is today", so these boundaries decide which shift a run belongs to. */
final class DutyBoundaryTest extends TestCase
{
    private function dutyDate(string $easternMoment): string
    {
        return (new DutyCalendar)->dutyDate(CarbonImmutable::parse($easternMoment, 'America/New_York'))->format('Y-m-d');
    }

    public function test_07_59_belongs_to_the_previous_duty_day_and_08_00_starts_the_next(): void
    {
        self::assertSame('2026-11-01', $this->dutyDate('2026-11-02 07:59:59'));
        self::assertSame('2026-11-02', $this->dutyDate('2026-11-02 08:00:00'));
        self::assertSame('2026-11-02', $this->dutyDate('2026-11-03 07:59:59'));
    }

    /** Clocks fall back at 02:00 on Nov 1, so the duty day that starts on Oct 31 at 08:00 EDT runs 25 hours. */
    public function test_the_duty_day_across_fall_back_is_a_25_hour_window(): void
    {
        [$start, $end] = (new DutyCalendar)->window('2026-10-31');

        self::assertSame(25, (int) $start->diffInHours($end));
        self::assertSame('2026-10-31 12:00:00', $start->format('Y-m-d H:i:s'));
        self::assertSame('2026-11-01 13:00:00', $end->format('Y-m-d H:i:s'));
        self::assertSame(24, (int) (new DutyCalendar)->window('2026-11-01')[0]->diffInHours((new DutyCalendar)->window('2026-11-01')[1]));
    }

    /** Clocks spring forward at 02:00 on Mar 8, so the duty day that starts on Mar 7 at 08:00 EST runs 23 hours. */
    public function test_the_duty_day_across_spring_forward_is_a_23_hour_window(): void
    {
        [$start, $end] = (new DutyCalendar)->window('2026-03-07');

        self::assertSame(23, (int) $start->diffInHours($end));
        self::assertSame('2026-03-07 13:00:00', $start->format('Y-m-d H:i:s'));
        self::assertSame('2026-03-08 12:00:00', $end->format('Y-m-d H:i:s'));
    }

    public function test_duty_date_follows_wall_clock_across_the_clock_changes(): void
    {
        self::assertSame('2026-11-01', $this->dutyDate('2026-11-02 07:59:00'));
        self::assertSame('2026-03-07', $this->dutyDate('2026-03-08 07:59:00'));
        self::assertSame('2026-03-08', $this->dutyDate('2026-03-08 08:00:00'));
    }
}
