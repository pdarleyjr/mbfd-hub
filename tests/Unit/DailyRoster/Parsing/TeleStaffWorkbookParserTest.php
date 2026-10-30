<?php

declare(strict_types=1);

namespace Tests\Unit\DailyRoster\Parsing;

use App\Services\DailyRoster\Parsing\TeleStaffRosterEntry;
use App\Services\DailyRoster\Parsing\TeleStaffRosterReport;
use App\Services\DailyRoster\Parsing\TeleStaffWorkbookException;
use App\Services\DailyRoster\Parsing\TeleStaffWorkbookParser;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TeleStaffRosterWorkbookFactory as Factory;
use Tests\TestCase;

final class TeleStaffWorkbookParserTest extends TestCase
{
    private const HASH = 'source-hash';

    protected function tearDown(): void
    {
        Factory::cleanup();

        parent::tearDown();
    }

    public function test_golden_roster_normalizes_every_field(): void
    {
        $path = Factory::build([
            'otherBlocks' => false,
            'sections' => [
                'Division Chief / Division Chief 300' => [
                    Factory::employee('Division Chief', '90001', 'ADMS'),
                    Factory::employee(null, '90002', '+OT', '08:00', '20:00', 12, 'EXAMPLE JR, BRAVO (CERT1)'),
                    Factory::employee(null, '90002', '+OT', '20:00', '08:00', 12, 'EXAMPLE JR, BRAVO (CERT1)'),
                ],
                'Station 2 / Captain 5' => [
                    Factory::employee('Rescue Captain', '90003', "\u{2927}2WXON", '08:00', '08:00', 24, "O'SAMPLE-DEMO, CHARLIE"),
                ],
                'Station 2 / Air Truck 810' => [
                    Factory::vacancy('Air Tech', '?,?'),
                ],
                'Combat Float Pool / Combat Float Pool' => [
                    Factory::employee('{Float Supervisor}', '90004', '.PMS', '08:00', '20:00', 12, 'FIXTURE III, DELTA (CERT2)'),
                    Factory::vacancy(null, '-,-', '20:00', '08:00', 12),
                ],
            ],
        ]);

        $report = $this->parse($path);

        $this->assertSame('xlsx-1', $report->parserVersion);
        $this->assertSame(self::HASH, $report->sourceSha256);
        $this->assertSame('Roster Report', $report->sheetName);
        $this->assertSame('2026-10-30', $report->dutyDate);
        $this->assertSame('B', $report->shift);
        $this->assertSame('2026-10-30T12:00:00Z', $report->windowStartAt);
        $this->assertSame('2026-10-31T12:00:00Z', $report->windowEndAt);
        $this->assertSame('2026-10-30T13:14:15Z', $report->generatedAt);
        $this->assertSame('Miami Beach Fire Department / Suppression/Rescue / B Shift', $report->divisionTitle);
        $this->assertSame(1, $report->titleRow);
        $this->assertSame([], $report->otherBlocks);
        $this->assertSame(['unknown_code:18'], $report->warnings);
        $this->assertSame(self::goldenRoster(), $this->flatten($report));
    }

    /** @return list<array<string, mixed>> */
    private static function goldenRoster(): array
    {
        $day = ['startAt' => '2026-10-30T12:00:00Z', 'endAt' => '2026-10-31T12:00:00Z'];
        $firstHalf = ['startAt' => '2026-10-30T12:00:00Z', 'endAt' => '2026-10-31T00:00:00Z'];
        $secondHalf = ['startAt' => '2026-10-31T00:00:00Z', 'endAt' => '2026-10-31T12:00:00Z'];

        return [
            ['section' => 0, 'sectionRow' => 3, 'title' => 'Division Chief / Division Chief 300', 'parent' => 'Division Chief', 'unit' => 'Division Chief 300', 'seats' => [
                ['order' => 0, 'row' => 4, 'rank' => 'Division Chief', 'entries' => [
                    self::golden(4, false, 'employee', ['employeeId' => '90001', 'rawName' => 'TESTER, ALPHA (CERT1/CERT2)', 'displayName' => 'Alpha Tester', 'certifications' => ['CERT1', 'CERT2'], 'rawCode' => 'ADMS', 'baseCode' => 'ADMS', 'prefix' => null, 'availability' => 'not_working', 'category' => 'Leave', 'displayLabel' => 'ADMS', 'rawFrom' => '08:00', 'rawThrough' => '08:00', 'reportedHours' => 24.0, ...$day, 'reviewReasons' => []]),
                    self::golden(5, true, 'employee', ['employeeId' => '90002', 'rawName' => 'EXAMPLE JR, BRAVO (CERT1)', 'displayName' => 'Bravo Example Jr', 'certifications' => ['CERT1'], 'rawCode' => '+OT', 'baseCode' => '+OT', 'prefix' => null, 'availability' => 'working', 'category' => 'Overtime', 'displayLabel' => '+OT', 'rawFrom' => '08:00', 'rawThrough' => '20:00', 'reportedHours' => 12.0, ...$firstHalf, 'reviewReasons' => []]),
                    self::golden(6, true, 'employee', ['employeeId' => '90002', 'rawName' => 'EXAMPLE JR, BRAVO (CERT1)', 'displayName' => 'Bravo Example Jr', 'certifications' => ['CERT1'], 'rawCode' => '+OT', 'baseCode' => '+OT', 'prefix' => null, 'availability' => 'working', 'category' => 'Overtime', 'displayLabel' => '+OT', 'rawFrom' => '20:00', 'rawThrough' => '08:00', 'reportedHours' => 12.0, ...$secondHalf, 'reviewReasons' => []]),
                ]],
            ]],
            ['section' => 1, 'sectionRow' => 9, 'title' => 'Station 2 / Captain 5', 'parent' => 'Station 2', 'unit' => 'Captain 5', 'seats' => [
                ['order' => 0, 'row' => 10, 'rank' => 'Rescue Captain', 'entries' => [
                    self::golden(10, false, 'employee', ['employeeId' => '90003', 'rawName' => "O'SAMPLE-DEMO, CHARLIE", 'displayName' => "Charlie O'Sample-Demo", 'certifications' => [], 'rawCode' => "\u{2927}2WXON", 'baseCode' => '2WXON', 'prefix' => "\u{2927}", 'availability' => 'working', 'category' => 'Exchange', 'displayLabel' => '2WXON', 'rawFrom' => '08:00', 'rawThrough' => '08:00', 'reportedHours' => 24.0, ...$day, 'reviewReasons' => []]),
                ]],
            ]],
            ['section' => 2, 'sectionRow' => 13, 'title' => 'Station 2 / Air Truck 810', 'parent' => 'Station 2', 'unit' => 'Air Truck 810', 'seats' => [
                ['order' => 0, 'row' => 14, 'rank' => 'Air Tech', 'entries' => [
                    self::goldenVacancy(14, false, 'vacancy_open', '?,?', '08:00', '08:00', 24.0, $day),
                ]],
            ]],
            ['section' => 3, 'sectionRow' => 17, 'title' => 'Combat Float Pool / Combat Float Pool', 'parent' => 'Combat Float Pool', 'unit' => 'Combat Float Pool', 'seats' => [
                ['order' => 0, 'row' => 18, 'rank' => '{Float Supervisor}', 'entries' => [
                    self::golden(18, false, 'employee', ['employeeId' => '90004', 'rawName' => 'FIXTURE III, DELTA (CERT2)', 'displayName' => 'Delta Fixture III', 'certifications' => ['CERT2'], 'rawCode' => '.PMS', 'baseCode' => 'PMS', 'prefix' => '.', 'availability' => 'unknown', 'category' => 'Other', 'displayLabel' => '.PMS', 'rawFrom' => '08:00', 'rawThrough' => '20:00', 'reportedHours' => 12.0, ...$firstHalf, 'reviewReasons' => ['unknown_code']]),
                    self::goldenVacancy(19, true, 'vacancy_suppressed', '-,-', '20:00', '08:00', 12.0, $secondHalf),
                ]],
            ]],
        ];
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    private static function golden(int $row, bool $continuation, string $kind, array $fields): array
    {
        return ['sourceRow' => $row, 'isContinuation' => $continuation, 'kind' => $kind, ...$fields];
    }

    /**
     * @param  array{startAt: string, endAt: string}  $interval
     * @return array<string, mixed>
     */
    private static function goldenVacancy(int $row, bool $continuation, string $kind, string $name, string $from, string $through, float $hours, array $interval): array
    {
        return self::golden($row, $continuation, $kind, ['employeeId' => null, 'rawName' => $name, 'displayName' => null, 'certifications' => [], 'rawCode' => null, 'baseCode' => null, 'prefix' => null, 'availability' => 'vacant', 'category' => null, 'displayLabel' => null, 'rawFrom' => $from, 'rawThrough' => $through, 'reportedHours' => $hours, ...$interval, 'reviewReasons' => []]);
    }

    public function test_the_full_coverage_roster_keeps_all_21_sections_and_every_code(): void
    {
        $report = $this->parse(Factory::build());

        $this->assertSame(Factory::SECTION_TITLES, array_map(static fn ($section): string => $section->rawTitle, $report->sections));
        $this->assertSame(Factory::OBSERVED_CODES, $this->sortedByFactoryOrder($this->entries($report)));

        $classes = array_count_values(array_map(static fn (TeleStaffRosterEntry $entry): string => $entry->availability, $this->entries($report)));
        $this->assertSame(['annotation' => 4, 'not_working' => 19, 'unknown' => 4, 'working' => 7], $this->sortKeys($classes));
    }

    public function test_the_container_can_build_the_parser(): void
    {
        $report = app(TeleStaffWorkbookParser::class)->parse(Factory::build(), self::HASH);

        $this->assertSame('xlsx-1', $report->parserVersion);
    }

    public function test_other_blocks_are_counted_without_member_data(): void
    {
        $report = $this->parse(Factory::build());

        $this->assertCount(2, $report->otherBlocks);
        [$before, $after] = $report->otherBlocks;
        $this->assertSame([1, "Chief's Office", 'D Shift 4/10,D Shift 5/8', 1, 10], [$before->titleRow, $before->division, $before->shiftLabel, $before->sectionCount, $before->entryCount]);
        $this->assertSame(['Off Duty Detail', 'Off Duty Detail', 2, 2], [$after->division, $after->shiftLabel, $after->sectionCount, $after->entryCount]);
    }

    /** @return array<string, array{0: int}> */
    public static function rowShifts(): array
    {
        return ['seven rows down' => [7], 'ten rows up' => [-10]];
    }

    #[DataProvider('rowShifts')]
    public function test_a_row_shift_parses_to_the_same_roster(int $offset): void
    {
        $baseline = $this->parse(Factory::build());
        $shifted = $this->parse(Factory::build(['rowOffset' => $offset]));

        $this->assertSame($baseline->titleRow + $offset, $shifted->titleRow);
        $this->assertSame($baseline->normalizedSha256(), $shifted->normalizedSha256());
        $this->assertSame($baseline->layoutFingerprint, $shifted->layoutFingerprint);
    }

    public function test_extra_spacers_and_trailing_blanks_change_nothing(): void
    {
        $baseline = $this->parse(Factory::build());
        $spaced = $this->parse(Factory::build(['spacers' => 4, 'trailingBlanks' => 25]));

        $this->assertSame($baseline->normalizedSha256(), $spaced->normalizedSha256());
    }

    public function test_a_whole_table_column_shift_parses_to_the_same_roster(): void
    {
        $baseline = $this->parse(Factory::build());
        $shifted = $this->parse(Factory::build(['columnOffset' => 3]));

        $this->assertSame($baseline->normalizedSha256(), $shifted->normalizedSha256());
        $this->assertSame($baseline->layoutFingerprint, $shifted->layoutFingerprint);
    }

    public function test_a_table_pulled_to_the_first_column_still_parses(): void
    {
        $baseline = $this->parse(Factory::build());
        $shifted = $this->parse(Factory::build(['columnOffset' => -1]));

        $this->assertSame($baseline->normalizedSha256(), $shifted->normalizedSha256());
    }

    public function test_a_merged_title_still_opens_the_block(): void
    {
        $report = $this->parse(Factory::build(['customize' => static function (Spreadsheet $book, Worksheet $sheet, int $titleRow): void {
            $sheet->mergeCells("B{$titleRow}:I{$titleRow}");
        }]));

        $this->assertSame('B', $report->shift);
        $this->assertCount(21, $report->sections);
    }

    public function test_hidden_rows_are_still_read(): void
    {
        $baseline = $this->parse(Factory::build());
        $hidden = $this->parse(Factory::build(['customize' => static function (Spreadsheet $book, Worksheet $sheet, int $titleRow): void {
            $sheet->getRowDimension($titleRow + 3)->setVisible(false);
        }]));

        $this->assertSame($baseline->normalizedSha256(), $hidden->normalizedSha256());
    }

    public function test_the_layout_fingerprint_ignores_section_names(): void
    {
        $first = $this->parse(Factory::build(['sections' => Factory::minimalSections([Factory::employee('Firefighter', '90010', 'SP')])]));
        $second = $this->parse(Factory::build(['sections' => Factory::minimalSections([Factory::employee('Firefighter', '90010', 'SP')], 'Station 9 / Renamed Unit')]));

        $this->assertSame($first->layoutFingerprint, $second->layoutFingerprint);
        $this->assertNotSame($first->normalizedSha256(), $second->normalizedSha256());
    }

    public function test_the_layout_fingerprint_is_exactly_the_observed_labels_and_their_gaps(): void
    {
        $report = $this->parse(Factory::build(['rowOffset' => 3, 'columnOffset' => 2]));

        // Nothing but labels and gaps goes in, so a parser version bump cannot look like a layout change.
        $this->assertSame(
            hash('sha256', (string) json_encode([['Rank', 0], ['ID', 1], ['Name', 2], ['From', 5], ['Through', 6]], JSON_THROW_ON_ERROR)),
            $report->layoutFingerprint,
        );
    }

    public function test_the_layout_fingerprint_ignores_the_division_and_sheet_names(): void
    {
        $baseline = $this->parse(Factory::build());

        config(['daily_roster.workbook.division' => 'Renamed Division', 'daily_roster.workbook.sheet' => 'Renamed Sheet']);
        $renamed = $this->parse(Factory::build(['division' => 'Renamed Division']));

        $this->assertSame('Renamed Sheet', $renamed->sheetName);
        $this->assertSame($baseline->layoutFingerprint, $renamed->layoutFingerprint);
    }

    public function test_the_layout_fingerprint_changes_when_the_gap_between_columns_changes(): void
    {
        $baseline = $this->parse(Factory::build());
        $widened = $this->parse(Factory::build(['customize' => static function (Spreadsheet $book, Worksheet $sheet): void {
            // One more blank column between Name and the code; the roster itself reads the same.
            $sheet->insertNewColumnBefore('E');
        }]));

        $this->assertNotSame($baseline->layoutFingerprint, $widened->layoutFingerprint);
        $this->assertSame($baseline->normalizedSha256(), $widened->normalizedSha256());
    }

    public function test_section_order_and_source_rows_are_preserved(): void
    {
        $report = $this->parse(Factory::build(['rowOffset' => 2, 'spacers' => 2]));

        $this->assertSame(range(0, 20), array_map(static fn ($section): int => $section->order, $report->sections));
        $rows = array_map(static fn ($section): int => $section->sourceRow, $report->sections);
        $sorted = $rows;
        sort($sorted);
        $this->assertSame($sorted, $rows);
        $this->assertSame($report->titleRow + 2, $rows[0]);
        $this->assertSame($rows[0] + 1, $report->sections[0]->seats[0]->sourceRow);
    }

    public function test_identical_adjacent_ranks_are_distinct_seats_and_a_vacancy_opens_a_seat(): void
    {
        $report = $this->parse(Factory::build(['sections' => Factory::minimalSections([
            Factory::employee('Firefighter', '90010', 'SP'),
            Factory::employee('Firefighter', '90011', 'SP'),
            Factory::vacancy('Firefighter'),
        ])]));

        $seats = $report->sections[2]->seats;
        $this->assertCount(3, $seats);
        $this->assertSame([0, 1, 2], array_map(static fn ($seat): int => $seat->order, $seats));
        $this->assertSame('vacancy_suppressed', $seats[2]->entries[0]->kind);
        $this->assertFalse($seats[2]->entries[0]->isContinuation);
    }

    public function test_a_blank_rank_continues_the_seat_and_a_vacancy_can_continue_it(): void
    {
        $report = $this->parse(Factory::build(['sections' => Factory::minimalSections([
            Factory::employee('Firefighter', '90010', 'ADONIS DAY'),
            Factory::employee(null, '90011', 'SP', '08:00', '20:00', 12),
            Factory::vacancy(null, '-,-', '20:00', '08:00', 12),
        ])]));

        $seat = $report->sections[2]->seats[0];
        $this->assertSame([false, true, true], array_map(static fn (TeleStaffRosterEntry $entry): bool => $entry->isContinuation, $seat->entries));
    }

    public function test_duplicate_id_halves_stay_separate_rows_and_count_once(): void
    {
        $report = $this->parse(Factory::build(['sections' => Factory::minimalSections([
            Factory::employee('Firefighter', '90010', 'ADONIS DAY'),
            Factory::employee(null, '90011', '+OT', '08:00', '20:00', 12),
            Factory::employee(null, '90011', '+OT', '20:00', '08:00', 12),
        ])]));

        $this->assertSame(5, $report->assignmentCount());
        $this->assertSame(4, $report->uniqueEmployeeCount());
        $this->assertSame([], $report->warnings);
    }

    public function test_an_annotation_row_does_not_count_as_a_seat_overlap(): void
    {
        $report = $this->parse(Factory::build(['sections' => Factory::minimalSections([
            Factory::employee('Firefighter', '90010', 'SP'),
            Factory::employee(null, '90010', '^OOC'),
        ])]));

        $entry = $report->sections[2]->seats[0]->entries[1];
        $this->assertSame('annotation', $entry->availability);
        $this->assertSame('Incentive', $entry->category);
        $this->assertSame([], $report->warnings);
    }

    public function test_two_working_rows_at_once_warn_with_the_row_number_only(): void
    {
        $report = $this->parse(Factory::build(['otherBlocks' => false, 'sections' => Factory::minimalSections([
            Factory::employee('Firefighter', '90010', 'SP'),
            Factory::employee(null, '90011', 'SP'),
        ])]));

        $this->assertSame(['seat_overlap:13'], $report->warnings);
    }

    public function test_a_leading_absence_is_not_an_overlap(): void
    {
        $report = $this->parse(Factory::build(['sections' => Factory::minimalSections([
            Factory::employee('Firefighter', '90010', 'VACA'),
            Factory::employee(null, '90011', 'SP'),
        ])]));

        $this->assertSame([], $report->warnings);
    }

    public function test_an_unknown_code_is_not_a_failure_and_raises_a_row_review(): void
    {
        $report = $this->parse(Factory::build(['otherBlocks' => false, 'sections' => Factory::minimalSections([
            Factory::employee('Firefighter', '90010', 'NEWCODE'),
        ])]));

        $entry = $report->sections[2]->seats[0]->entries[0];
        $this->assertSame('unknown', $entry->availability);
        $this->assertSame('NEWCODE', $entry->displayLabel);
        $this->assertSame(['unknown_code'], $entry->reviewReasons);
        $this->assertSame(['unknown_code:12'], $report->warnings);
    }

    public function test_a_numeric_id_normalizes_to_text(): void
    {
        $report = $this->parse(Factory::build(['sections' => Factory::minimalSections([
            Factory::employee('Firefighter', 90010, 'SP'),
            Factory::employee('Firefighter', 90011.0, 'SP'),
        ])]));

        $this->assertSame('90010', $report->sections[2]->seats[0]->entries[0]->employeeId);
        $this->assertSame('90011', $report->sections[2]->seats[1]->entries[0]->employeeId);
    }

    /** @return array<string, array{0: string|int|float}> */
    public static function malformedIds(): array
    {
        return [
            'fractional number' => [90010.5],
            'too short' => ['123'],
            'too long' => ['1234567'],
            'letters' => ['A9001'],
            'too long as a number' => [12345678],
        ];
    }

    #[DataProvider('malformedIds')]
    public function test_a_malformed_id_fails_closed(string|int|float $id): void
    {
        $this->assertRowFails('id_malformed', [Factory::employee('Firefighter', $id, 'SP')]);
    }

    public function test_a_name_without_an_id_fails_closed(): void
    {
        $this->assertRowFails('id_malformed', [Factory::employee('Firefighter', null, 'SP')]);
    }

    public function test_a_vacancy_with_an_id_fails_closed(): void
    {
        $this->assertRowFails('vacancy_has_id', [['Firefighter', '90010', '-,-', null, '08:00', '08:00', 24]]);
    }

    public function test_a_vacancy_with_a_code_fails_closed(): void
    {
        $this->assertRowFails('row_unrecognized', [['Firefighter', null, '?,?', 'SP', '08:00', '08:00', 24]]);
    }

    public function test_an_id_without_a_code_fails_closed(): void
    {
        $this->assertRowFails('id_without_code', [Factory::employee('Firefighter', '90010', null)]);
    }

    public function test_a_blank_rank_at_the_start_of_a_section_fails_closed(): void
    {
        $this->assertRowFails('seat_without_rank', [Factory::employee(null, '90010', 'SP')]);
    }

    /** @return array<string, array{0: array<int, mixed>}> */
    public static function unrecognizedRows(): array
    {
        return [
            'only a rank' => [[0 => 'Firefighter']],
            'text in the gap column' => [[0 => 'Firefighter', 1 => '90010', 2 => 'TESTER, ALPHA', 3 => 'SURPRISE', 4 => 'SP', 5 => '08:00', 6 => '08:00', 7 => 24]],
            'text past the hours column' => [[0 => 'Firefighter', 1 => '90010', 2 => 'TESTER, ALPHA', 4 => 'SP', 5 => '08:00', 6 => '08:00', 7 => 24, 8 => 'SURPRISE']],
            'malformed time' => [[0 => 'Firefighter', 1 => '90010', 2 => 'TESTER, ALPHA', 4 => 'SP', 5 => '8 AM', 6 => '08:00', 7 => 24]],
            'hours are text' => [[0 => 'Firefighter', 1 => '90010', 2 => 'TESTER, ALPHA', 4 => 'SP', 5 => '08:00', 6 => '08:00', 7 => 'all day']],
            'missing hours' => [[0 => 'Firefighter', 1 => '90010', 2 => 'TESTER, ALPHA', 4 => 'SP', 5 => '08:00', 6 => '08:00']],
            'a name that is only certifications' => [[0 => 'Firefighter', 1 => '90010', 2 => '(CERT1)', 4 => 'SP', 5 => '08:00', 6 => '08:00', 7 => 24]],
            'an oversized cell' => [[0 => str_repeat('R', 600), 1 => '90010', 2 => 'TESTER, ALPHA', 4 => 'SP', 5 => '08:00', 6 => '08:00', 7 => 24]],
        ];
    }

    /** @param  array<int, mixed>  $raw */
    #[DataProvider('unrecognizedRows')]
    public function test_an_unrecognized_row_fails_closed_with_its_row_number(array $raw): void
    {
        $this->assertRowFails('row_unrecognized', [['raw' => $raw]]);
    }

    public function test_a_section_title_without_a_separator_fails_closed(): void
    {
        $this->assertFails('row_unrecognized', Factory::build(['sections' => [
            'Division Chief / Division Chief 300' => [Factory::employee('Division Chief', '90001', 'SP')],
            'Station 2 / Captain 5' => [Factory::employee('Captain', '90002', 'SP')],
            'No Separator Here' => [Factory::employee('Firefighter', '90003', 'SP')],
        ]]));
    }

    public function test_a_renamed_column_fails_closed(): void
    {
        $this->assertFails('header_unrecognized', Factory::build([
            'customize' => static function (Spreadsheet $book, Worksheet $sheet, int $titleRow): void {
                $sheet->setCellValueExplicit('C'.($titleRow + 1), 'Employee', 'str');
            },
        ]));
    }

    public function test_a_header_that_moved_within_the_block_fails_closed(): void
    {
        $this->assertFails('header_unrecognized', Factory::build([
            'otherBlocks' => false,
            'sections' => Factory::minimalSections([]),
            'customize' => static function (Spreadsheet $book, Worksheet $sheet): void {
                $sheet->setCellValueExplicit('H6', null, 'null');
                $sheet->setCellValueExplicit('J6', 'Through', 'str');
            },
        ]));
    }

    public function test_a_primary_block_with_no_header_fails_closed(): void
    {
        $this->assertFails('header_unrecognized', Factory::build([
            'otherBlocks' => false,
            'sections' => [],
        ]));
    }

    public function test_a_header_with_no_section_after_it_fails_closed(): void
    {
        $this->assertFails('row_unrecognized', Factory::build([
            'otherBlocks' => false,
            'customize' => static function (Spreadsheet $book, Worksheet $sheet): void {
                $row = $sheet->getHighestDataRow() + 1;
                foreach (['B' => 'Rank', 'C' => 'ID', 'D' => 'Name', 'G' => 'From', 'H' => 'Through'] as $column => $label) {
                    $sheet->setCellValueExplicit($column.$row, $label, 'str');
                }
            },
        ]));
    }

    public function test_an_empty_section_is_kept_and_warned(): void
    {
        $report = $this->parse(Factory::build(['otherBlocks' => false, 'sections' => Factory::minimalSections([])]));

        $this->assertSame([], $report->sections[2]->seats);
        $this->assertSame(['empty_section:11'], $report->warnings);
    }

    public function test_missing_date_fails(): void
    {
        $this->assertFails('date_missing', Factory::build(['header' => null]));
    }

    public function test_an_impossible_date_fails(): void
    {
        $this->assertFails('date_missing', Factory::build(['header' => Factory::headerFor('13/45/2026')]));
    }

    public function test_missing_footer_fails(): void
    {
        $this->assertFails('generated_missing', Factory::build(['footer' => null]));
    }

    public function test_an_unreadable_footer_time_fails(): void
    {
        $this->assertFails('generated_missing', Factory::build(['footer' => Factory::footerFor('10/30/2026 25:99:00')]));
    }

    public function test_a_footer_within_two_days_passes(): void
    {
        $report = $this->parse(Factory::build(['generatedAt' => '11/01/2026 09:14:15']));

        $this->assertSame('2026-11-01T14:14:15Z', $report->generatedAt);
    }

    public function test_a_footer_far_from_the_date_fails(): void
    {
        $this->assertFails('generated_far_from_date', Factory::build(['generatedAt' => '11/02/2026 09:14:15']));
    }

    public function test_the_ambiguous_fall_back_hour_parses_and_warns(): void
    {
        $report = $this->parse(Factory::build(['generatedAt' => '11/01/2026 01:30:00']));

        $this->assertSame('2026-11-01T05:30:00Z', $report->generatedAt);
        $this->assertContains('generated_time_ambiguous', $report->warnings);
    }

    public function test_an_ordinary_footer_time_does_not_warn(): void
    {
        $this->assertNotContains('generated_time_ambiguous', $this->parse(Factory::build())->warnings);
    }

    public function test_a_shift_the_calendar_disagrees_with_fails(): void
    {
        $this->assertFails('shift_calendar_mismatch', Factory::build(['shift' => 'A']));
    }

    public function test_a_disabled_rotation_fails_rather_than_guessing(): void
    {
        $path = Factory::build();
        config(['captain5.shift_rotation.enabled' => false]);

        $this->assertFails('shift_calendar_mismatch', $path);
    }

    public function test_an_unreadable_shift_label_fails(): void
    {
        $this->assertFails('shift_unreadable', Factory::build(['shiftLabel' => 'D Shift 4/10']));
    }

    public function test_no_primary_block_fails(): void
    {
        $this->assertFails('block_not_found', Factory::build(['division' => 'Some Other Division']));
    }

    public function test_two_primary_blocks_fail(): void
    {
        $this->assertFails('block_ambiguous', Factory::build(['extraBlock' => 'B Shift']));
    }

    public function test_a_missing_command_300_section_fails(): void
    {
        $this->assertFails('section_missing_command_300', Factory::build(['sections' => [
            'Station 2 / Captain 5' => [Factory::employee('Captain', '90002', 'SP')],
        ]]));
    }

    public function test_a_missing_captain_5_section_fails(): void
    {
        $this->assertFails('section_missing_captain_5', Factory::build(['sections' => [
            'Division Chief / Division Chief 300' => [Factory::employee('Division Chief', '90001', 'SP')],
        ]]));
    }

    public function test_section_roles_match_the_unit_exactly(): void
    {
        $this->assertFails('section_missing_captain_5', Factory::build(['sections' => [
            'Division Chief / Division Chief 300' => [Factory::employee('Division Chief', '90001', 'SP')],
            'Station 2 / Captain 55' => [Factory::employee('Captain', '90002', 'SP')],
        ]]));
    }

    public function test_hours_that_match_nothing_fail(): void
    {
        $this->assertRowFails('hours_mismatch', [Factory::employee('Firefighter', '90010', 'SP', '08:00', '08:00', 23)]);
    }

    public function test_hours_within_the_tolerance_pass(): void
    {
        $report = $this->parse(Factory::build(['sections' => Factory::minimalSections([
            Factory::employee('Firefighter', '90010', 'SP', '08:00', '08:00', 24.04),
        ])]));

        $this->assertSame(24.04, $report->sections[2]->seats[0]->entries[0]->reportedHours);
    }

    public function test_reported_hours_keep_the_two_decimals_the_snapshot_stores(): void
    {
        $report = $this->parse(Factory::build(['sections' => Factory::minimalSections([
            Factory::employee('Firefighter', '90010', 'SP', '08:00', '15:20', 7.3333),
        ])]));

        $this->assertSame(7.33, $report->sections[2]->seats[0]->entries[0]->reportedHours);
    }

    public function test_a_row_past_the_end_of_the_duty_day_fails(): void
    {
        $this->assertRowFails('interval_outside_window', [Factory::employee('Firefighter', '90010', 'SP', '07:00', '09:00', 2)]);
    }

    public function test_early_morning_rows_belong_to_the_next_calendar_day(): void
    {
        $report = $this->parse(Factory::build(['sections' => Factory::minimalSections([
            Factory::employee('Firefighter', '90010', 'SP', '00:00', '08:00', 8),
        ])]));

        $entry = $report->sections[2]->seats[0]->entries[0];
        $this->assertSame('2026-10-31T04:00:00Z', $entry->startAt);
        $this->assertSame('2026-10-31T12:00:00Z', $entry->endAt);
    }

    public function test_the_two_twelve_hour_halves_of_an_ordinary_day(): void
    {
        $report = $this->parse(Factory::build(['sections' => Factory::minimalSections([
            Factory::employee('Firefighter', '90010', 'SP', '08:00', '20:00', 12),
            Factory::employee(null, '90011', 'SP', '20:00', '08:00', 12),
        ])]));

        [$day, $night] = $report->sections[2]->seats[0]->entries;
        $this->assertSame(['2026-10-30T12:00:00Z', '2026-10-31T00:00:00Z'], [$day->startAt, $day->endAt]);
        $this->assertSame(['2026-10-31T00:00:00Z', '2026-10-31T12:00:00Z'], [$night->startAt, $night->endAt]);
    }

    /** @return array<string, array{0: int}> */
    public static function fallBackDayHours(): array
    {
        return ['wall clock' => [24], 'elapsed' => [25]];
    }

    #[DataProvider('fallBackDayHours')]
    public function test_the_fall_back_duty_day_accepts_either_reported_hours(int $hours): void
    {
        $report = $this->parse(Factory::build(['date' => '2026-10-31', 'sections' => Factory::minimalSections([
            Factory::employee('Firefighter', '90010', 'SP', '08:00', '08:00', $hours),
        ])]));

        $this->assertSame('2026-10-31T12:00:00Z', $report->windowStartAt);
        $this->assertSame('2026-11-01T13:00:00Z', $report->windowEndAt);
        $this->assertSame('2026-11-01T13:00:00Z', $report->sections[2]->seats[0]->entries[0]->endAt);
    }

    /** @return array<string, array{0: int}> */
    public static function fallBackNightHours(): array
    {
        return ['wall clock' => [12], 'elapsed' => [13]];
    }

    #[DataProvider('fallBackNightHours')]
    public function test_the_fall_back_night_half_accepts_either_reported_hours(int $hours): void
    {
        $report = $this->parse(Factory::build(['date' => '2026-10-31', 'sections' => Factory::minimalSections([
            Factory::employee('Firefighter', '90010', 'ADONIS DAY', '08:00', '08:00', 24),
            Factory::employee(null, '90011', 'SP', '20:00', '08:00', $hours),
        ])]));

        $entry = $report->sections[2]->seats[0]->entries[1];
        $this->assertSame('2026-11-01T00:00:00Z', $entry->startAt);
        $this->assertSame('2026-11-01T13:00:00Z', $entry->endAt);
    }

    public function test_the_fall_back_day_still_rejects_unrelated_hours(): void
    {
        $this->assertFails('hours_mismatch', Factory::build(['date' => '2026-10-31', 'sections' => Factory::minimalSections([
            Factory::employee('Firefighter', '90010', 'SP', '08:00', '08:00', 23),
        ])]));
    }

    /** @return array<string, array{0: int}> */
    public static function springForwardHours(): array
    {
        return ['wall clock' => [24], 'elapsed' => [23]];
    }

    #[DataProvider('springForwardHours')]
    public function test_the_spring_forward_duty_day_is_23_elapsed_hours(int $hours): void
    {
        $report = $this->parse(Factory::build(['date' => '2027-03-13', 'sections' => Factory::minimalSections([
            Factory::employee('Firefighter', '90010', 'SP', '08:00', '08:00', $hours),
        ])]));

        $this->assertSame('2027-03-13T13:00:00Z', $report->windowStartAt);
        $this->assertSame('2027-03-14T12:00:00Z', $report->windowEndAt);
        $this->assertSame('2027-03-14T12:00:00Z', $report->sections[2]->seats[0]->entries[0]->endAt);
    }

    /** @return array<string, array{0: string, 1: string, 2: list<string>}> */
    public static function names(): array
    {
        return [
            'plain' => ['TESTER, ALPHA', 'Alpha Tester', []],
            'with certifications' => ['TESTER, ALPHA (CERT1/CERT2)', 'Alpha Tester', ['CERT1', 'CERT2']],
            'suffix' => ['TESTER JR, ALPHA', 'Alpha Tester Jr', []],
            'suffix with a period' => ['TESTER JR., ALPHA (CERT1)', 'Alpha Tester Jr.', ['CERT1']],
            'roman suffix' => ['TESTER III, ALPHA', 'Alpha Tester III', []],
            'hyphen' => ['TESTER-EXAMPLE, ALPHA', 'Alpha Tester-Example', []],
            'two surnames' => ['TESTER EXAMPLE, ALPHA', 'Alpha Tester Example', []],
            'apostrophe' => ["O'TESTER, ALPHA", "Alpha O'Tester", []],
            'accent' => ['TÉSTER, ÁLPHA', 'Álpha Téster', []],
            'no comma' => ['ALPHA TESTER', 'Alpha Tester', []],
        ];
    }

    /** @param  list<string>  $certifications */
    #[DataProvider('names')]
    public function test_a_name_keeps_its_raw_form_and_gains_a_display_form(string $raw, string $display, array $certifications): void
    {
        $report = $this->parse(Factory::build(['sections' => Factory::minimalSections([
            Factory::employee('Firefighter', '90010', 'SP', '08:00', '08:00', 24, $raw),
        ])]));

        $entry = $report->sections[2]->seats[0]->entries[0];
        $this->assertSame($raw, $entry->rawName);
        $this->assertSame($display, $entry->displayName);
        $this->assertSame($certifications, $entry->certifications);
    }

    public function test_rank_text_is_never_pattern_matched(): void
    {
        $report = $this->parse(Factory::build(['sections' => Factory::minimalSections([
            Factory::employee('zzzRescue Captain', '90010', 'SP'),
            Factory::employee('{Fleet Operations Supervisor}', '90011', 'SP'),
        ])]));

        $this->assertSame(
            ['zzzRescue Captain', '{Fleet Operations Supervisor}'],
            array_map(static fn ($seat): string => $seat->rawRank, $report->sections[2]->seats),
        );
    }

    public function test_formula_text_stored_as_a_string_is_never_evaluated(): void
    {
        $report = $this->parse(Factory::build(['sections' => Factory::minimalSections([
            Factory::employee('=1+1', '90010', 'SP'),
        ])]));

        $this->assertSame('=1+1', $report->sections[2]->seats[0]->rawRank);
    }

    public function test_exceptions_carry_codes_and_rows_but_never_cell_text(): void
    {
        $path = Factory::build(['sections' => Factory::minimalSections([
            Factory::employee('Firefighter', 'BAD-ID', 'SP', '08:00', '08:00', 24, 'SECRETNAME, PRIVATE'),
        ])]);

        try {
            $this->parse($path);
            $this->fail('Expected the workbook to fail.');
        } catch (TeleStaffWorkbookException $exception) {
            $this->assertSame('id_malformed', $exception->errorCode);
            $this->assertIsInt($exception->sourceRow);
            $this->assertStringNotContainsString('SECRETNAME', $exception->getMessage());
            $this->assertStringNotContainsString('BAD-ID', $exception->getMessage());
        }
    }

    public function test_warnings_never_contain_names_or_ids(): void
    {
        $report = $this->parse(Factory::build(['sections' => Factory::minimalSections([
            Factory::employee('Firefighter', '90010', 'NEWCODE', '08:00', '08:00', 24, 'SECRETNAME, PRIVATE'),
            Factory::employee(null, '90011', 'SP'),
            Factory::employee(null, '90012', 'SP'),
        ])]));

        foreach ($report->warnings as $warning) {
            $this->assertMatchesRegularExpression('/^[a-z_]+:\d+$/', $warning);
        }
    }

    private function parse(string $path): TeleStaffRosterReport
    {
        return (new TeleStaffWorkbookParser)->parse($path, self::HASH);
    }

    /** @param  list<array<int|string, mixed>>  $rows */
    private function assertRowFails(string $errorCode, array $rows): void
    {
        $path = Factory::build(['otherBlocks' => false, 'sections' => Factory::minimalSections($rows)]);

        try {
            $this->parse($path);
            $this->fail("Expected {$errorCode}, the workbook parsed.");
        } catch (TeleStaffWorkbookException $exception) {
            $this->assertSame($errorCode, $exception->errorCode);
            $this->assertSame(12, $exception->sourceRow);
        }
    }

    private function assertFails(string $errorCode, string $path): void
    {
        try {
            $this->parse($path);
            $this->fail("Expected {$errorCode}, the workbook parsed.");
        } catch (TeleStaffWorkbookException $exception) {
            $this->assertSame($errorCode, $exception->errorCode);
        }
    }

    /** @return list<TeleStaffRosterEntry> */
    private function entries(TeleStaffRosterReport $report): array
    {
        $entries = [];
        foreach ($report->sections as $section) {
            foreach ($section->seats as $seat) {
                $entries = [...$entries, ...$seat->entries];
            }
        }

        return $entries;
    }

    /**
     * @param  list<TeleStaffRosterEntry>  $entries
     * @return list<string>
     */
    private function sortedByFactoryOrder(array $entries): array
    {
        usort($entries, static fn (TeleStaffRosterEntry $a, TeleStaffRosterEntry $b): int => $a->employeeId <=> $b->employeeId);

        return array_map(static fn (TeleStaffRosterEntry $entry): string => (string) $entry->rawCode, $entries);
    }

    /**
     * @param  array<string, int>  $counts
     * @return array<string, int>
     */
    private function sortKeys(array $counts): array
    {
        ksort($counts);

        return $counts;
    }

    /** @return list<array<string, mixed>> */
    private function flatten(TeleStaffRosterReport $report): array
    {
        $flattened = [];
        foreach ($report->sections as $section) {
            $seats = [];
            foreach ($section->seats as $seat) {
                $seats[] = [
                    'order' => $seat->order,
                    'row' => $seat->sourceRow,
                    'rank' => $seat->rawRank,
                    'entries' => array_map(static fn (TeleStaffRosterEntry $entry): array => [
                        'sourceRow' => $entry->sourceRow,
                        'isContinuation' => $entry->isContinuation,
                        'kind' => $entry->kind,
                        'employeeId' => $entry->employeeId,
                        'rawName' => $entry->rawName,
                        'displayName' => $entry->displayName,
                        'certifications' => $entry->certifications,
                        'rawCode' => $entry->rawCode,
                        'baseCode' => $entry->baseCode,
                        'prefix' => $entry->prefix,
                        'availability' => $entry->availability,
                        'category' => $entry->category,
                        'displayLabel' => $entry->displayLabel,
                        'rawFrom' => $entry->rawFrom,
                        'rawThrough' => $entry->rawThrough,
                        'reportedHours' => $entry->reportedHours,
                        'startAt' => $entry->startAt,
                        'endAt' => $entry->endAt,
                        'reviewReasons' => $entry->reviewReasons,
                    ], $seat->entries),
                ];
            }
            $flattened[] = [
                'section' => $section->order,
                'sectionRow' => $section->sourceRow,
                'title' => $section->rawTitle,
                'parent' => $section->parent,
                'unit' => $section->unit,
                'seats' => $seats,
            ];
        }

        return $flattened;
    }
}
