<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Models\CapitalProject;
use App\Models\SingleGasMeter;
use App\Models\Under25kProject;
use App\Support\HubTimezone;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class HubCalendarDateTest extends TestCase
{
    #[DataProvider('boundaries')]
    public function test_current_calendar_date_and_date_only_statuses_use_eastern_midnight(
        string $instant, string $today, string $dueDate, string $midnight, bool $past,
    ): void {
        $this->travelTo(CarbonImmutable::parse($instant));
        $writtenDate = CarbonImmutable::parse($dueDate, 'UTC');

        self::assertSame($today.' 00:00:00 +00:00', HubTimezone::today()->format('Y-m-d H:i:s P'));
        self::assertSame($midnight, HubTimezone::calendarDate($writtenDate)->format('Y-m-d H:i:s P'));
        self::assertSame($dueDate.' 00:00:00 +00:00', $writtenDate->format('Y-m-d H:i:s P'));
        self::assertSame($past, (new SingleGasMeter(['expiration_date' => $dueDate]))->isExpired());
        self::assertSame($past, (new CapitalProject(['target_completion_date' => $dueDate]))->is_overdue);
        self::assertSame($past, (new Under25kProject(['target_completion_date' => $dueDate]))->is_overdue);
    }

    /** @return iterable<string, array{string, string, string, string, bool}> */
    public static function boundaries(): iterable
    {
        yield 'UTC midnight is still the preceding Eastern day' => ['2026-10-26T00:30:00Z', '2026-10-25', '2026-10-26', '2026-10-26 00:00:00 -04:00', false];
        yield 'just before Eastern midnight' => ['2026-10-26T03:59:59Z', '2026-10-25', '2026-10-26', '2026-10-26 00:00:00 -04:00', false];
        yield 'exact Eastern midnight preserves strict isPast' => ['2026-10-26T04:00:00Z', '2026-10-26', '2026-10-26', '2026-10-26 00:00:00 -04:00', false];
        yield 'after midnight preserves the existing due-day rule' => ['2026-10-26T04:00:01Z', '2026-10-26', '2026-10-26', '2026-10-26 00:00:00 -04:00', true];
        yield 'before spring DST day starts' => ['2026-03-08T04:30:00Z', '2026-03-07', '2026-03-08', '2026-03-08 00:00:00 -05:00', false];
        yield 'after spring jump uses midnight offset' => ['2026-03-08T07:00:01Z', '2026-03-08', '2026-03-08', '2026-03-08 00:00:00 -05:00', true];
        yield 'before fall DST day starts' => ['2026-11-01T03:30:00Z', '2026-10-31', '2026-11-01', '2026-11-01 00:00:00 -04:00', false];
        yield 'after fall repeat uses midnight offset' => ['2026-11-01T06:00:01Z', '2026-11-01', '2026-11-01', '2026-11-01 00:00:00 -04:00', true];
    }

    public function test_meter_remaining_days_are_whole_calendar_days_and_completed_projects_stay_complete(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-26T00:30:00Z'));

        self::assertSame(2, (new SingleGasMeter(['expiration_date' => '2026-10-27']))->daysUntilExpiration());
        self::assertSame(0, (new SingleGasMeter)->daysUntilExpiration());
        self::assertFalse((new CapitalProject(['target_completion_date' => '2026-10-24', 'actual_completion' => '2026-10-24']))->is_overdue);
        self::assertFalse((new Under25kProject(['target_completion_date' => '2026-10-24', 'actual_completion_date' => '2026-10-24']))->is_overdue);
    }
}
