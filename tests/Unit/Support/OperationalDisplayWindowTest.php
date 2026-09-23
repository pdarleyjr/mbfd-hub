<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\OperationalDisplayWindow;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OperationalDisplayWindowTest extends TestCase
{
    #[DataProvider('boundaryTimes')]
    public function test_it_uses_the_eight_am_new_york_operational_boundary(
        string $now,
        string $expectedStart,
        string $expectedEnd,
    ): void {
        $window = OperationalDisplayWindow::forNow($now);

        self::assertSame($expectedStart, $window->start->format('Y-m-d H:i:s P'));
        self::assertSame($expectedEnd, $window->end->format('Y-m-d H:i:s P'));
        self::assertSame('America/New_York', $window->start->getTimezone()->getName());
        self::assertTrue($window->contains($window->start));
        self::assertTrue($window->contains($window->end->subSecond()));
        self::assertFalse($window->contains($window->end));
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function boundaryTimes(): iterable
    {
        yield '07:59 remains in the prior period' => [
            '2026-09-24 07:59:59 America/New_York',
            '2026-09-23 08:00:00 -04:00',
            '2026-09-24 08:00:00 -04:00',
        ];

        yield '08:00 starts the new period' => [
            '2026-09-24 08:00:00 America/New_York',
            '2026-09-24 08:00:00 -04:00',
            '2026-09-25 08:00:00 -04:00',
        ];

        yield 'spring DST period preserves local boundaries' => [
            '2026-03-08 07:59:59 America/New_York',
            '2026-03-07 08:00:00 -05:00',
            '2026-03-08 08:00:00 -04:00',
        ];

        yield 'fall DST period preserves local boundaries' => [
            '2026-11-01 07:59:59 America/New_York',
            '2026-10-31 08:00:00 -04:00',
            '2026-11-01 08:00:00 -05:00',
        ];
    }

    public function test_dst_windows_have_the_correct_elapsed_duration_without_shifting_local_eight_am(): void
    {
        $spring = OperationalDisplayWindow::forNow('2026-03-08 07:59:59 America/New_York');
        $fall = OperationalDisplayWindow::forNow('2026-11-01 07:59:59 America/New_York');

        self::assertSame(23.0, $spring->start->diffInHours($spring->end));
        self::assertSame(25.0, $fall->start->diffInHours($fall->end));
    }
}
