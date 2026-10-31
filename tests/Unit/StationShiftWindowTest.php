<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\HubTimezone;
use App\Support\StationShiftWindow;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StationShiftWindowTest extends TestCase
{
    /** @return array<string, array{string, string, string}> */
    public static function moments(): array
    {
        return [
            'exactly 7 AM starts a new window' => ['2026-10-30 07:00:00', '2026-10-30 07:00:00', '2026-10-31 07:00:00'],
            'one second before 7 AM still belongs to the day before' => ['2026-10-30 06:59:59', '2026-10-29 07:00:00', '2026-10-30 07:00:00'],
            'midnight stays in the window that began the morning before' => ['2026-10-30 00:00:00', '2026-10-29 07:00:00', '2026-10-30 07:00:00'],
            'evening' => ['2026-10-30 22:15:00', '2026-10-30 07:00:00', '2026-10-31 07:00:00'],
            'month boundary' => ['2026-11-01 03:00:00', '2026-10-31 07:00:00', '2026-11-01 07:00:00'],
            'morning after the clocks fell back' => ['2026-11-01 12:00:00', '2026-11-01 07:00:00', '2026-11-02 07:00:00'],
            'clocks fall back during the window' => ['2026-10-31 23:00:00', '2026-10-31 07:00:00', '2026-11-01 07:00:00'],
            'clocks spring forward during the window' => ['2027-03-14 03:30:00', '2027-03-13 07:00:00', '2027-03-14 07:00:00'],
        ];
    }

    #[DataProvider('moments')]
    public function test_the_window_runs_from_7_am_to_7_am_eastern(string $moment, string $start, string $end): void
    {
        $window = StationShiftWindow::containing(CarbonImmutable::parse($moment, HubTimezone::NAME));

        $this->assertSame($start, $window->start->setTimezone(HubTimezone::NAME)->format('Y-m-d H:i:s'));
        $this->assertSame($end, $window->end->setTimezone(HubTimezone::NAME)->format('Y-m-d H:i:s'));
    }

    public function test_a_moment_given_in_utc_is_placed_by_eastern_time(): void
    {
        // 10:30 UTC on Oct 30 is 6:30 AM Eastern, so it is still the previous window.
        $window = StationShiftWindow::containing(CarbonImmutable::parse('2026-10-30 10:30:00', 'UTC'));

        $this->assertSame('2026-10-29 07:00:00', $window->start->setTimezone(HubTimezone::NAME)->format('Y-m-d H:i:s'));
    }

    /** @return array<string, array{string, bool}> */
    public static function alertMoments(): array
    {
        return [
            'start of the window' => ['2026-10-30 07:00:00', false],
            'one second before noon' => ['2026-10-30 11:59:59', false],
            'noon' => ['2026-10-30 12:00:00', true],
            'evening' => ['2026-10-30 21:00:00', true],
            'after midnight, same window' => ['2026-10-31 02:00:00', true],
            'last second of the window' => ['2026-10-31 06:59:59', true],
            'next window has begun' => ['2026-10-31 07:00:00', false],
        ];
    }

    #[DataProvider('alertMoments')]
    public function test_the_alert_is_due_from_noon_until_the_window_ends(string $moment, bool $due): void
    {
        $now = CarbonImmutable::parse($moment, HubTimezone::NAME);

        $this->assertSame($due, StationShiftWindow::containing($now)->alertIsDue($now));
    }
}
