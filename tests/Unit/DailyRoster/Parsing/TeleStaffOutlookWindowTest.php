<?php

declare(strict_types=1);

namespace Tests\Unit\DailyRoster\Parsing;

use App\Services\DailyRoster\Parsing\TeleStaffWorkbookException;
use App\Services\DailyRoster\Parsing\TeleStaffWorkbookParser;
use Tests\Support\TeleStaffRosterWorkbookFactory as Factory;
use Tests\TestCase;

/** The 7-day outlook's workbooks fall up to six days after their export; the 5-minute export keeps its two. */
final class TeleStaffOutlookWindowTest extends TestCase
{
    private const HASH = 'source-hash';

    protected function tearDown(): void
    {
        Factory::cleanup();

        parent::tearDown();
    }

    public function test_by_default_a_duty_date_three_days_after_the_export_still_fails(): void
    {
        $this->expectFailure('generated_far_from_date', static fn () => (new TeleStaffWorkbookParser)
            ->parse(Factory::build(['generatedAt' => '10/27/2026 09:14:15']), self::HASH));
    }

    public function test_the_outlook_window_reads_a_day_six_days_after_its_export(): void
    {
        $report = (new TeleStaffWorkbookParser)->parse(Factory::build(['generatedAt' => '10/24/2026 07:00:05']), self::HASH, 7);

        $this->assertSame('2026-10-30', $report->dutyDate);
        $this->assertSame('2026-10-24T11:00:05Z', $report->generatedAt);
    }

    public function test_the_outlook_window_still_rejects_a_day_beyond_it(): void
    {
        $this->expectFailure('generated_far_from_date', static fn () => (new TeleStaffWorkbookParser)
            ->parse(Factory::build(['generatedAt' => '10/22/2026 09:14:15']), self::HASH, 7));
    }

    public function test_the_outlook_window_never_widens_the_past_side(): void
    {
        $this->expectFailure('generated_far_from_date', static fn () => (new TeleStaffWorkbookParser)
            ->parse(Factory::build(['generatedAt' => '11/02/2026 09:14:15']), self::HASH, 7));
    }

    private function expectFailure(string $code, callable $parse): void
    {
        try {
            $parse();
            $this->fail("Expected {$code}.");
        } catch (TeleStaffWorkbookException $exception) {
            $this->assertSame($code, $exception->errorCode);
        }
    }
}
