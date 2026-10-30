<?php

declare(strict_types=1);

namespace Tests\Unit\Roster;

use App\Services\Roster\RosterSpecialEventExtractor;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PHPUnit\Framework\TestCase;

/**
 * Runs the extractor on the real Oct 30 2026 TeleStaff workbook. The file holds
 * real personnel, so it is never committed: set MBFD_REAL_ROSTER_XLSX to its path
 * to run this locally. Skipped everywhere else.
 */
final class RosterSpecialEventExtractorRealWorkbookTest extends TestCase
{
    public function test_the_real_october_30_workbook_yields_exactly_obsession_and_mila(): void
    {
        $path = getenv('MBFD_REAL_ROSTER_XLSX');
        if (! is_string($path) || $path === '' || ! is_file($path)) {
            self::markTestSkipped('Set MBFD_REAL_ROSTER_XLSX to the real Dailyroster.xlsx to run this check.');
        }

        $reader = new Xlsx;
        $reader->setReadDataOnly(true);
        $sheet = $reader->load($path)->getSheetByName('Roster Report');
        self::assertNotNull($sheet);
        $rows = $sheet->toArray(null, true, false, false);

        $events = (new RosterSpecialEventExtractor)->extract($rows, '2026-10-30');

        self::assertSame(
            ['telestaff:2026-10-30:obsession-fire-show', 'telestaff:2026-10-30:mila-fire-show'],
            array_column($events, 'sourceEventKey'),
        );
        self::assertSame(['complete', 'complete'], array_column($events, 'quality'));
        self::assertSame(['off_duty_detail', 'off_duty_detail'], array_column($events, 'classification'));
        foreach ($events as $event) {
            self::assertCount(1, $event['groups']);
            self::assertCount(1, $event['groups'][0]['members']);
            self::assertMatchesRegularExpression('/^\d+$/', $event['groups'][0]['members'][0]['employeeId']);
        }
        self::assertSame('DETINSP29', $events[0]['groups'][0]['unitDesignation']);
        self::assertSame('Faena Theater', $events[0]['location']);
        self::assertSame('DETINSP30', $events[1]['groups'][0]['unitDesignation']);
        self::assertSame('1636 Meridian Ave', $events[1]['location']);
        self::assertSame('2026-10-30T19:00:00-04:00', $events[0]['groups'][0]['members'][0]['startAt']);
        self::assertSame('2026-10-31T01:00:00-04:00', $events[0]['groups'][0]['members'][0]['endAt']);
        self::assertSame('2026-10-31T00:30:00-04:00', $events[1]['groups'][0]['members'][0]['endAt']);
    }
}
