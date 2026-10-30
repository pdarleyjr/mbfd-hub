<?php

declare(strict_types=1);

namespace Tests\Unit\Roster;

use App\Services\Roster\RosterSpecialEventExtractor;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Tests\Support\RosterSheetFixture as Sheet;

final class RosterSpecialEventExtractorTest extends TestCase
{
    private const DATE = '2026-10-30';

    /** @return list<array<string, mixed>> */
    private function extract(array $rows, string $date = self::DATE, ?\Closure $ranks = null): array
    {
        return (new RosterSpecialEventExtractor($ranks))->extract($rows, $date);
    }

    /** @param  list<array<string, mixed>>  $events @return list<string> */
    private function keys(array $events): array
    {
        return array_column($events, 'sourceEventKey');
    }

    public function test_october_30_yields_exactly_the_two_real_off_duty_details(): void
    {
        $events = $this->extract(Sheet::october30());

        self::assertSame([
            'telestaff:2026-10-30:obsession-fire-show',
            'telestaff:2026-10-30:mila-fire-show',
        ], $this->keys($events));

        $obsession = $events[0];
        self::assertSame('off_duty_detail', $obsession['classification']);
        self::assertSame('Obsession Fire Show', $obsession['title']);
        self::assertSame('2026-10-30', $obsession['eventDate']);
        self::assertSame('Faena Theater', $obsession['location']);
        self::assertSame('complete', $obsession['quality']);
        self::assertCount(1, $obsession['groups']);
        self::assertSame('DETINSP29', $obsession['groups'][0]['groupKey']);
        self::assertSame('DETINSP29', $obsession['groups'][0]['unitDesignation']);
        self::assertSame('Faena Theater', $obsession['groups'][0]['locationText']);
        self::assertSame([
            'employeeId' => '10004',
            'name' => 'DELTA, TESTER',
            'rank' => null,
            'rankVerified' => false,
            'sourcePosition' => 'Fire Inspector INSP',
            'workCode' => '+OTD15FI',
            'startAt' => '2026-10-30T19:00:00-04:00',
            'endAt' => '2026-10-31T01:00:00-04:00',
        ], $obsession['groups'][0]['members'][0]);

        self::assertSame('2026-10-31T00:30:00-04:00', $events[1]['groups'][0]['members'][0]['endAt']);
    }

    public function test_rank_comes_only_from_the_injected_resolver_by_exact_id(): void
    {
        $seen = [];
        $events = $this->extract(Sheet::october30(), self::DATE, function (string $id) use (&$seen): ?string {
            $seen[] = $id;

            return $id === '10004' ? 'Fire Inspector' : null;
        });

        $first = $events[0]['groups'][0]['members'][0];
        self::assertSame('Fire Inspector', $first['rank']);
        self::assertTrue($first['rankVerified']);
        self::assertNull($events[1]['groups'][0]['members'][0]['rank']);
        self::assertSame(['10004', '10005'], $seen);
    }

    public function test_halloween_is_one_event_with_five_groups_eleven_members_and_dst_correct_times(): void
    {
        $events = $this->extract(Sheet::halloween(), '2026-10-31');

        self::assertCount(1, $events);
        $event = $events[0];
        self::assertSame('telestaff:2026-10-31:halloween-on-lincoln-rd', $event['sourceEventKey']);
        self::assertSame('special_event', $event['classification']);
        self::assertSame('Halloween on Lincoln Rd', $event['title']);
        self::assertSame('complete', $event['quality']);
        self::assertSame(['CMD1', 'G1', 'G2', 'R6', 'DETINDP2'], array_column($event['groups'], 'groupKey'));
        self::assertSame(11, array_sum(array_map(static fn (array $g): int => count($g['members']), $event['groups'])));

        $g1 = $event['groups'][1];
        self::assertSame('Lincoln Rd & Euclid Ave', $g1['locationText']);
        self::assertSame('ALS non-transport', $g1['detailText']);
        self::assertSame('Lakeview Dr. then Lincoln Road & Euclid Ave', $event['groups'][3]['locationText']);
        self::assertSame('DETINDP2', $event['groups'][4]['unitDesignation']);
        self::assertSame('am Setup / pm event', $event['groups'][4]['detailText']);
        self::assertSame('', $event['location']);

        $member = $g1['members'][0];
        self::assertSame('2026-10-31T16:30:00-04:00', $member['startAt']);
        self::assertSame('2026-11-01T02:00:00-05:00', $member['endAt']);
        $seconds = (new DateTimeImmutable($member['endAt']))->getTimestamp() - (new DateTimeImmutable($member['startAt']))->getTimestamp();
        self::assertSame(10.5, $seconds / 3600);

        $tagged = $event['groups'][2]['members'][0];
        self::assertSame('QUEBEC JR., DANA', $tagged['name']);
        self::assertSame('Command/Event Coordinator PM,INSP', $event['groups'][0]['members'][0]['sourcePosition']);
        self::assertSame('', $event['groups'][4]['members'][2]['sourcePosition']);
        self::assertSame('2026-10-31T10:00:00-04:00', $event['groups'][4]['members'][1]['startAt']);
    }

    public function test_spring_forward_offsets_change_across_the_overnight_window(): void
    {
        $rows = Sheet::section('Off Duty Details / 05 - Night Detail {DET5, Ocean Dr}', [
            Sheet::slot('Fire Inspector INSP', 30001, 'ZULU, MIKE', '+OTD', '22:00', '04:00', 6),
        ]);
        $events = $this->extract([Sheet::block('Miami Beach Fire Department / Off Duty Detail / Off Duty Detail'), ...$rows], '2027-03-13');

        $member = $events[0]['groups'][0]['members'][0];
        self::assertSame('2027-03-13T22:00:00-05:00', $member['startAt']);
        self::assertSame('2027-03-14T04:00:00-04:00', $member['endAt']);
    }

    public function test_inserted_rows_and_shifted_columns_do_not_change_the_result(): void
    {
        $baseline = $this->extract(Sheet::october30());
        $junk = [Sheet::block('Report generated 10/30/2026'), Sheet::blank(), [1 => 'Page 1'], []];
        $shifted = array_map(static fn (array $row): array => array_values(array_merge([null, null], array_values($row))), Sheet::october30());
        // Re-spread each row so cells keep their order but land in different columns.
        $spread = array_map(static function (array $row): array {
            $out = [];
            $col = 2;
            foreach ($row as $value) {
                $out[$col] = $value;
                $col += 2;
            }

            return $out;
        }, Sheet::october30());

        self::assertSame($baseline, $this->extract([...$junk, ...Sheet::october30(), ...$junk]));
        self::assertSame($baseline, $this->extract($spread));
        self::assertSame($this->keys($baseline), $this->keys($this->extract($shifted)));
    }

    public function test_deleted_blank_rows_keep_the_events_and_deleted_headers_downgrade_quality(): void
    {
        $noBlanks = array_values(array_filter(Sheet::october30(), static fn (array $row): bool => $row !== Sheet::blank()));
        self::assertSame($this->extract(Sheet::october30()), $this->extract($noBlanks));

        $noHeaders = array_values(array_filter(Sheet::october30(), static fn (array $row): bool => $row !== Sheet::header()));
        $events = $this->extract($noHeaders);
        self::assertSame(['telestaff:2026-10-30:obsession-fire-show', 'telestaff:2026-10-30:mila-fire-show'], $this->keys($events));
        self::assertSame(['partial', 'partial'], array_column($events, 'quality'));
    }

    public function test_numeric_cells_and_day_fraction_times_from_a_spreadsheet_reader_work(): void
    {
        $rows = Sheet::section('Off Duty Details / 29 - Obsession Fire Show {DETINSP29, Faena Theater}', [
            [1 => 'Fire Inspector INSP', 2 => 19125, 3 => 'DELTA, TESTER (AO)', 5 => '+OTD15FI', 6 => 19 / 24, 7 => 1 / 24, 8 => 6.0],
        ]);

        $member = $this->extract([Sheet::block('Miami Beach Fire Department / Off Duty Detail / Off Duty Detail'), ...$rows])[0]['groups'][0]['members'][0];

        self::assertSame('19125', $member['employeeId']);
        self::assertSame('2026-10-30T19:00:00-04:00', $member['startAt']);
        self::assertSame('2026-10-31T01:00:00-04:00', $member['endAt']);
    }

    public function test_duplicate_rows_collapse_to_one_member(): void
    {
        $row = Sheet::slot('Fire Inspector INSP', 10004, 'DELTA, TESTER', '+OTD15FI', '19:00', '01:00', 6);
        $rows = [
            Sheet::block('Miami Beach Fire Department / Off Duty Detail / Off Duty Detail'),
            ...Sheet::section('Off Duty Details / 29 - Obsession Fire Show {DETINSP29, Faena Theater}', [$row, $row]),
            ...Sheet::section('Off Duty Details / 29 - Obsession Fire Show {DETINSP29, Faena Theater}', [$row]),
        ];

        $events = $this->extract($rows);

        self::assertCount(1, $events);
        self::assertCount(1, $events[0]['groups']);
        self::assertCount(1, $events[0]['groups'][0]['members']);
    }

    public function test_truncated_sheets_never_invent_events_and_flag_partial_data(): void
    {
        $rows = Sheet::october30();
        $obsessionMember = null;
        foreach ($rows as $index => $row) {
            if (($row[3] ?? '') === 'DELTA, TESTER (AO/CSTE/DE)') {
                $obsessionMember = $index;
            }
        }
        self::assertNotNull($obsessionMember);

        $afterMember = $this->extract(array_slice($rows, 0, $obsessionMember + 1));
        self::assertSame(['telestaff:2026-10-30:obsession-fire-show'], $this->keys($afterMember));
        self::assertSame('partial', $afterMember[0]['quality']);

        self::assertSame([], $this->extract(array_slice($rows, 0, $obsessionMember))); // heading only
        self::assertSame([], $this->extract([]));
        self::assertSame([], $this->extract(array_slice($rows, 0, 5)));
    }

    public function test_only_off_duty_and_special_event_blocks_are_eligible(): void
    {
        $rows = [
            Sheet::block('Miami Beach Fire Department / Prevention / D Shift'),
            ...Sheet::section('Prevention / Special Events', [Sheet::slot('SE Coordinator', 10002, 'BRAVO, TESTER', 'SP', '08:00', '18:00', 10)]),
            ...Sheet::section('Special Events / 07 - Looks Like An Event', [Sheet::slot('Captain', 10009, 'GOLF, TESTER', 'SP', '08:00', '18:00', 10)]),
            Sheet::block('Miami Beach Fire Department / USAR Deployment / USAR Deployment'),
            ...Sheet::section('Strike Team 1A / Real Looking Team', [Sheet::slot('Captain', 10010, 'HOTEL, TESTER', 'SP', '08:00', '18:00', 10)]),
        ];

        self::assertSame([], $this->extract($rows));
    }

    public function test_placeholders_and_defaults_are_never_events_or_members(): void
    {
        $rows = [
            Sheet::block('Miami Beach Fire Department / Special Event / Special Events'),
            ...Sheet::section('Special Events / Placeholder For Deployment Only', [Sheet::slot('Deploy Here', 10011, 'INDIA, TESTER', '', '08:00', '16:00', 8)]),
            ...Sheet::section('Chief Non-Working Event / Chief Non-Working Event', [Sheet::placeholder('Fire Chief', '?,?')]),
            ...Sheet::section('Recall/ICS / ICS', [Sheet::placeholder('Firefighter', '?,?')]),
            ...Sheet::section('Temporary Special Assignment / Temporary Special Assignment', [Sheet::placeholder('Captain', '-,-', '08:00', '16:00', 8)]),
            ...Sheet::section('Special Events / 09 - Default Time Only', [
                Sheet::slot('Captain', 10012, 'JULIET, TESTER', '', '00:00', '00:00', 24),
                Sheet::slot('Captain', null, 'KILO, TESTER', '', '08:00', '16:00', 8),
            ]),
        ];

        $events = $this->extract($rows);

        self::assertSame([], $events);
    }

    public function test_a_named_person_without_an_id_is_dropped_and_marks_the_event_partial(): void
    {
        $rows = [
            Sheet::block('Miami Beach Fire Department / Special Event / Special Events'),
            ...Sheet::section('Special Events / 10 - Beach Concert {C1; Lummus Park}', [
                Sheet::slot('Captain', 40001, 'LIMA, TESTER', 'SP', '12:00', '20:00', 8),
                Sheet::slot('Lieutenant', null, 'MIKE, TESTER', 'SP', '12:00', '20:00', 8),
            ]),
        ];

        $events = $this->extract($rows);

        self::assertCount(1, $events[0]['groups'][0]['members']);
        self::assertSame('partial', $events[0]['quality']);
    }

    public function test_equal_times_mean_a_full_day_only_when_hours_say_so(): void
    {
        $rows = [
            Sheet::block('Miami Beach Fire Department / Special Event / Special Events'),
            ...Sheet::section('Special Events / 11 - Marathon {M1; Collins Ave}', [
                Sheet::slot('Captain', 50001, 'NOVA, TESTER', 'SP', '08:00', '08:00', 24),
                Sheet::slot('Captain', 50002, 'OMEGA, TESTER', 'SP', '08:00', '08:00', 8),
            ]),
        ];

        $members = $this->extract($rows)[0]['groups'][0]['members'];

        self::assertSame('2026-10-31T08:00:00-04:00', $members[0]['endAt']);
        self::assertNull($members[1]['startAt']);
    }

    public function test_an_invalid_roster_date_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->extract([], '10/30/2026');
    }
}
