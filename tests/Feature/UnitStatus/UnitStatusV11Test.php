<?php

declare(strict_types=1);

namespace Tests\Feature\UnitStatus;

use App\Models\UnitStatus\UnitStatusRun;
use App\Services\UnitStatus\UnitActivity;
use App\Services\UnitStatus\UnitStatusBoard;
use Illuminate\Support\Facades\DB;
use Tests\Support\DailyRosterSnapshotFactory;

/** v1.1 (contract section 10): phantom assignments, short names, shift totals and the pop-up card's numbers. */
final class UnitStatusV11Test extends UnitStatusTestCase
{
    /** @return array<string, mixed> */
    private function board(): array
    {
        return app(UnitStatusBoard::class)->build(now());
    }

    /** @return array<string, mixed> */
    private function unit(array $board, string $code): array
    {
        foreach ($board['stations'] as $station) {
            foreach ($station['units'] as $unit) {
                if ($unit['code'] === $code) {
                    return $unit;
                }
            }
        }
        self::fail('No unit '.$code);
    }

    /** One confirmed, non-voided run on a duty date, for a call that exists with a call type. */
    private function ranOn(int $number, string $code, string $dutyDate = self::TODAY, string $callType = 'Medical Emergency', array $run = [], string $status = 'Cleared From Incident'): void
    {
        $this->storeCall($number, [$code => ['status' => $status]], ['section' => 'recent', 'call_type' => $callType]);
        UnitStatusRun::query()->create($run + [
            'unit_code' => $code,
            'source_call_id' => self::callId($number),
            'external_id' => '26011'.str_pad((string) $number, 5, '0', STR_PAD_LEFT),
            'attributed_at' => self::et($dutyDate.' 09:00:00')->addMinutes($number),
            'attribution_basis' => 'received',
            'duty_date' => $dutyDate,
            'confirmed' => true,
            'provenance' => 'live',
        ]);
    }

    // 10.1 Phantom and stuck assignments

    public function test_the_water_rescue_that_everyone_had_cleared_from_does_not_hold_six_units(): void
    {
        $this->travelTo(self::et('2026-11-02 21:30:00'));
        $this->markFeedFresh();
        $crew = static fn (string $id): array => [['employee_id' => 'EMP-'.$id, 'name' => 'CREW, '.$id]];
        DailyRosterSnapshotFactory::publish(self::TODAY, [
            'Station 2 / Division Chief 300' => $crew('A'), 'Station 2 / Captain 5' => $crew('B'), 'Station 2 / Engine 2' => $crew('C'),
            'Station 6 / Fire Boat 6' => $crew('D'), 'Station 3 / Ladder 3' => $crew('E'), 'Station 3 / Rescue 3' => $crew('F'),
        ]);
        $this->storeCall(1, array_fill_keys(['300', 'CPT5', 'E2', 'FB6', 'L3', 'R3'], ['status' => 'Dispatched', 'confirmed' => false]), [
            'call_type' => 'Water Rescue', 'received_at' => self::et('2026-11-02 14:16:00'),
        ]);

        $board = $this->board();

        foreach (['300', 'CPT5', 'E2', 'FB6', 'L3', 'R3'] as $code) {
            $unit = $this->unit($board, $code);
            self::assertSame('available', $unit['availability']['key'], $code);
            self::assertSame([], $unit['currentCalls'], $code);
            self::assertNull($unit['incidentLabel'], $code);
            self::assertSame('No active call', $unit['assignmentSummary'], $code);
        }
    }

    public function test_a_question_mark_unit_is_never_on_a_call_and_never_shows_a_status(): void
    {
        $this->markFeedFresh();
        DailyRosterSnapshotFactory::publish(self::TODAY, ['Station 2 / Rescue 22' => [['employee_id' => 'EMP-1', 'name' => 'ALPHA, ANN']]]);
        $this->storeCall(1, ['R22' => ['status' => 'Dispatched', 'confirmed' => false]], ['received_at' => now()->subMinute()]);

        $r22 = $this->unit($this->board(), 'R22');

        self::assertSame('available', $r22['availability']['key']);
        self::assertSame([], $r22['currentCalls']);
        self::assertNull($r22['incidentLabel']);
        self::assertNull($r22['availability']['confirmed']);
    }

    public function test_raising_the_grace_setting_restores_a_short_window_for_question_mark_units(): void
    {
        config(['unit_status.unconfirmed_grace_minutes' => 10]);
        $this->markFeedFresh();
        $this->storeCall(1, ['R22' => ['status' => 'Dispatched', 'confirmed' => false]], ['received_at' => now()->subMinutes(3)]);

        $r22 = $this->unit($this->board(), 'R22');
        self::assertSame('on_call', $r22['availability']['key']);
        self::assertSame('Dispatched', $r22['availability']['label']);
        // The board never says unconfirmed, even when the setting lets a question-mark unit through.
        self::assertTrue($r22['availability']['confirmed']);
        self::assertTrue($r22['currentCalls'][0]['unitConfirmed']);

        $this->travel(7)->minutes();
        $this->markFeedFresh();
        self::assertSame('on_call', $this->unit($this->board(), 'R22')['availability']['key'], 'exactly ten minutes is still inside the grace');

        $this->travel(1)->minutes();
        $this->markFeedFresh();
        $after = $this->unit($this->board(), 'R22');
        self::assertNotSame('on_call', $after['availability']['key']);
        self::assertNotSame('unknown', $after['availability']['key']);
        self::assertSame([], $after['currentCalls']);
    }

    public function test_a_confirmed_unit_that_moved_on_stays_on_the_call(): void
    {
        $this->markFeedFresh();
        $this->storeCall(1, ['E4' => ['status' => 'Enroute'], 'R4' => ['status' => 'On Scene'], 'R44' => ['status' => 'Transport']], ['received_at' => now()->subHours(3)]);

        $board = $this->board();

        foreach (['E4' => 'En Route', 'R4' => 'On Scene', 'R44' => 'Transporting'] as $code => $word) {
            self::assertSame('on_call', $this->unit($board, $code)['availability']['key'], $code);
            self::assertSame($word, $this->unit($board, $code)['availability']['label'], $code);
        }
    }

    public function test_a_confirmed_unit_stuck_at_dispatched_for_over_ten_minutes_is_not_on_the_call(): void
    {
        $this->markFeedFresh();
        $this->storeCall(1, ['E1' => ['status' => 'Dispatched'], 'E2' => ['status' => 'Dispatched'], 'E3' => ['status' => 'Acknowledged'], 'L1' => ['status' => ''], 'L3' => ['status' => 'Acknowledged']], ['received_at' => now()->subMinutes(9)]);
        $this->storeCall(2, ['R1' => ['status' => 'Dispatched'], 'R2' => ['status' => 'Acknowledged'], 'R3' => ['status' => ''], 'R4' => ['status' => 'Enroute']], ['received_at' => now()->subMinutes(11)]);
        $this->storeCall(3, ['R44' => ['status' => 'Dispatched']], ['received_at' => now()->subMinutes(10)]);

        $board = $this->board();

        foreach (['E1', 'E2', 'E3', 'L1', 'L3', 'R44'] as $code) {
            self::assertSame('on_call', $this->unit($board, $code)['availability']['key'], $code.' at 9 or exactly 10 minutes');
        }
        self::assertSame('Assigned', $this->unit($board, 'L1')['availability']['label']);
        foreach (['R1', 'R2', 'R3'] as $code) {
            self::assertSame([], $this->unit($board, $code)['currentCalls'], $code.' at 11 minutes');
            self::assertNotSame('on_call', $this->unit($board, $code)['availability']['key'], $code);
        }
        // A unit that really responded moved on to En Route and is not affected.
        self::assertSame('En Route', $this->unit($board, 'R4')['availability']['label']);
    }

    public function test_superseding_follows_the_newer_confirmed_call_and_ignores_question_mark_listings(): void
    {
        $this->markFeedFresh();
        $this->storeCall(1, ['E1' => ['status' => 'Enroute']], ['received_at' => now()->subMinutes(8)]);
        $this->storeCall(2, ['E1' => ['status' => 'Dispatched', 'confirmed' => false]], ['received_at' => now()->subMinutes(2)]);
        $this->storeCall(3, ['E2' => ['status' => 'On Scene']], ['received_at' => now()->subMinutes(9)]);
        $this->storeCall(4, ['E2' => ['status' => 'Enroute']], ['received_at' => now()->subMinutes(2)]);

        $board = $this->board();

        self::assertSame([self::callId(1)], array_column($this->unit($board, 'E1')['currentCalls'], 'sourceCallId'));
        self::assertSame([self::callId(4)], array_column($this->unit($board, 'E2')['currentCalls'], 'sourceCallId'));
    }

    public function test_a_question_mark_unit_on_a_very_old_call_is_just_available_not_unconfirmed(): void
    {
        $this->markFeedFresh();
        DailyRosterSnapshotFactory::publish(self::TODAY, ['Station 1 / Engine 1' => [['employee_id' => 'EMP-1', 'name' => 'ALPHA, ANN']]]);
        $this->storeCall(1, ['E1' => ['status' => 'Dispatched', 'confirmed' => false]], ['received_at' => now()->subHours(20)]);

        self::assertSame('available', $this->unit($this->board(), 'E1')['availability']['key']);
    }

    public function test_a_confirmed_unit_on_an_open_call_older_than_twelve_hours_is_still_not_confirmed(): void
    {
        $this->markFeedFresh();
        $this->storeCall(1, ['E1' => ['status' => 'On Scene']], ['received_at' => now()->subHours(13)]);

        self::assertSame('unknown', $this->unit($this->board(), 'E1')['availability']['key']);
    }

    // Unconfirmed is never counted or shown

    public function test_unconfirmed_runs_are_in_no_number_and_no_counter(): void
    {
        $this->markFeedFresh();
        $this->ranOn(1, 'E2');
        $this->ranOn(2, 'E2', run: ['confirmed' => false]);
        $this->ranOn(3, 'E2', run: ['voided_at' => now(), 'void_reason' => 'duplicate']);

        $board = $this->board();
        $e2 = $this->unit($board, 'E2');

        self::assertSame(['shift' => 1, 'lifetime' => 1, 'backfilledLifetime' => 0], $e2['runs']);
        self::assertSame(1, $e2['profile']['shiftRuns']);
        self::assertSame(1, $e2['profile']['lifetimeRuns']);
        self::assertCount(1, $e2['profile']['todayCalls']);
        self::assertSame(1, $board['totals']['total']);
        self::assertStringNotContainsString('nconfirmedRuns', (string) json_encode($board));
        self::assertArrayNotHasKey('shiftUnconfirmed', $e2['runs']);
        self::assertArrayNotHasKey('lifetimeUnconfirmed', $e2['runs']);
    }

    // 10.3 Short crew names

    public function test_crew_entries_carry_a_short_name_and_nothing_from_the_raw_name(): void
    {
        $this->markFeedFresh();
        DailyRosterSnapshotFactory::publish(self::TODAY, ['Station 2 / Engine 2' => [
            ['employee_id' => 'EMP-SECRET-1', 'name' => 'Benson, Mark', 'rank' => 'Lieutenant'],
            ['employee_id' => 'EMP-SECRET-2', 'name' => 'Yacum Jr, David', 'rank' => 'Firefighter DE'],
            ['employee_id' => 'EMP-SECRET-3', 'name' => 'Cruz, Ana', 'rank' => '.Firefighter'],
            ['employee_id' => 'EMP-SECRET-4', 'name' => 'Rey, Lou', 'rank' => 'Driver Engineer', 'from' => '19:00', 'through' => '08:00'],
        ]]);
        DB::table('roster_assignments')->where('employee_id', 'EMP-SECRET-2')->update(['raw_name' => 'Yacum Jr, David (PARAMEDIC/HAZMAT)']);
        DB::table('roster_assignments')->where('employee_id', 'EMP-SECRET-1')->update(['rank_label' => null, 'seat_rank' => null]);

        $crew = $this->unit($this->board(), 'E2')['crew'];

        self::assertSame(['Benson', 'DE Yacum Jr', 'FF Cruz'], array_column($crew['onTruck'], 'short'));
        self::assertSame(['Driver Engineer Rey'], array_column($crew['later'], 'short'));
        $json = (string) json_encode($crew);
        foreach (['PARAMEDIC', 'HAZMAT', 'EMP-SECRET', 'employee_id', 'raw_name'] as $leak) {
            self::assertStringNotContainsString($leak, $json, $leak);
        }
        foreach ($crew['onTruck'] as $person) {
            self::assertStringNotContainsString(',', $person['short']);
            self::assertStringNotContainsString('David', $person['short']);
        }
    }

    // 10.4 Shift totals

    public function test_totals_split_rescue_and_combat_and_classify_command_units_by_call_type(): void
    {
        $this->markFeedFresh();
        $this->ranOn(1, 'R1');
        $this->ranOn(2, 'R1', callType: 'Cardiac Arrest');
        $this->ranOn(3, 'E1', callType: 'Fire Alarm');
        $this->ranOn(4, 'E1', callType: 'Medical Emergency');
        $this->ranOn(5, 'CPT5', callType: 'Medical Emergency');
        $this->ranOn(6, 'CPT5', callType: 'Fire Alarm');
        $this->ranOn(7, 'FB6', callType: 'Water Rescue');
        $this->ranOn(8, '300', callType: 'Interfacility Transfer');
        // Not this shift, not confirmed, voided: none of them count.
        $this->ranOn(9, 'R2', dutyDate: '2026-11-01');
        $this->ranOn(10, 'R2', run: ['confirmed' => false]);
        $this->ranOn(11, 'R2', run: ['voided_at' => now(), 'void_reason' => 'merged']);

        $totals = $this->board()['totals'];

        // Rescue: R1 x2, CPT5 (medical), 300 (interfacility). Combat: E1 x2, CPT5 (fire alarm), FB6 (water rescue).
        self::assertSame(['total' => 8, 'rescue' => 4, 'combat' => 4], array_diff_key($totals, ['busiest' => 1]));
        self::assertSame(['codes' => ['E1', 'R1', 'CPT5'], 'runs' => 2], $totals['busiest']);
    }

    public function test_the_busiest_unit_is_one_unit_or_every_tied_unit_or_nobody(): void
    {
        $this->markFeedFresh();
        self::assertSame(['total' => 0, 'rescue' => 0, 'combat' => 0, 'busiest' => null], $this->board()['totals']);

        $this->ranOn(1, 'E3');
        self::assertSame(['codes' => ['E3'], 'runs' => 1], $this->board()['totals']['busiest']);

        $this->ranOn(2, 'R22');
        self::assertSame(['codes' => ['R22', 'E3'], 'runs' => 1], $this->board()['totals']['busiest']);

        $this->ranOn(3, 'R22');
        self::assertSame(['codes' => ['R22'], 'runs' => 2], $this->board()['totals']['busiest']);
    }

    public function test_a_multi_unit_call_counts_once_for_each_unit(): void
    {
        $this->markFeedFresh();
        $this->storeCall(1, ['E1' => ['status' => 'Cleared From Incident'], 'R1' => ['status' => 'Cleared From Incident']], ['section' => 'recent', 'call_type' => 'Medical Emergency']);
        foreach (['E1', 'R1'] as $code) {
            UnitStatusRun::query()->create(['unit_code' => $code, 'source_call_id' => self::callId(1), 'attributed_at' => self::et('2026-11-02 09:00:00'), 'attribution_basis' => 'received', 'duty_date' => self::TODAY, 'confirmed' => true, 'provenance' => 'live']);
        }

        $totals = $this->board()['totals'];

        self::assertSame(2, $totals['total']);
        self::assertSame(1, $totals['rescue']);
        self::assertSame(1, $totals['combat']);
    }

    public function test_the_note_says_when_counting_began(): void
    {
        $this->markFeedFresh();

        self::assertSame('Counts began Nov 2, 2026 at 8:00 AM and keep adding up from there.', $this->board()['tracking']['note']);
    }

    // 10.5 The pop-up card's numbers

    public function test_the_busiest_day_is_the_duty_day_with_most_runs_and_ties_go_to_the_latest(): void
    {
        $this->markFeedFresh();
        foreach ([1, 2, 3] as $n) {
            $this->ranOn($n, 'E2', dutyDate: '2026-11-01');
        }
        foreach ([4, 5] as $n) {
            $this->ranOn($n, 'E2');
        }
        $this->ranOn(6, 'E2', dutyDate: '2026-10-31', run: ['confirmed' => false]);
        $this->ranOn(7, 'E2', dutyDate: '2026-10-31', run: ['voided_at' => now(), 'void_reason' => 'duplicate']);
        $this->ranOn(8, 'E2', dutyDate: '2026-10-31');

        $profile = $this->unit($this->board(), 'E2')['profile'];

        self::assertSame(['runs' => 3, 'date' => '2026-11-01', 'label' => 'Nov 1, 2026'], $profile['busiestDay']);
        self::assertSame(6, $profile['lifetimeRuns']);
        self::assertSame(2, $profile['shiftRuns']);

        $this->ranOn(9, 'E2');
        self::assertSame(['runs' => 3, 'date' => '2026-11-02', 'label' => 'Nov 2, 2026'], $this->unit($this->board(), 'E2')['profile']['busiestDay']);
        self::assertNull($this->unit($this->board(), 'E3')['profile']['busiestDay']);
    }

    public function test_today_calls_are_newest_first_for_this_shift_only_with_the_current_one_marked(): void
    {
        $this->markFeedFresh();
        $this->ranOn(1, 'E2', callType: 'Fire Alarm', run: ['attributed_at' => self::et('2026-11-02 08:30:00')]);
        $this->ranOn(2, 'E2', callType: 'Medical Emergency', run: ['attributed_at' => self::et('2026-11-02 14:16:00')]);
        $this->ranOn(3, 'E2', dutyDate: '2026-11-01');
        $this->storeCall(4, ['E2' => ['status' => 'Enroute']], ['call_type' => 'Structure Fire', 'external_id' => null, 'received_at' => now()->subMinutes(5)]);
        UnitStatusRun::query()->create(['unit_code' => 'E2', 'source_call_id' => self::callId(4), 'attributed_at' => self::et('2026-11-02 10:25:00'), 'attribution_basis' => 'received', 'duty_date' => self::TODAY, 'confirmed' => true, 'provenance' => 'live']);

        $e2 = $this->unit($this->board(), 'E2');

        self::assertSame(['2:16 PM ET', '10:25 AM ET', '8:30 AM ET'], array_column($e2['profile']['todayCalls'], 'timeLabel'));
        self::assertSame(['Medical Emergency', 'Structure Fire', 'Fire Alarm'], array_column($e2['profile']['todayCalls'], 'callType'));
        self::assertSame([false, true, false], array_column($e2['profile']['todayCalls'], 'current'));
        self::assertSame(['#2601100002', 'ID pending', '#2601100001'], array_column($e2['profile']['todayCalls'], 'idLabel'));
        self::assertSame(['Cleared', 'En Route', 'Cleared'], array_column($e2['profile']['todayCalls'], 'unitStatus'));
        self::assertSame('2601100002', $e2['profile']['todayCalls'][0]['externalId']);
        self::assertNull($e2['profile']['todayCalls'][1]['externalId']);
        self::assertStringStartsWith('https://web.pulsepoint.org/', (string) $e2['profile']['todayCalls'][0]['sourceUrl']);
        self::assertSame(['attributedAt', 'timeLabel', 'callType', 'idLabel', 'externalId', 'unitStatus', 'sourceUrl', 'current'], array_keys($e2['profile']['todayCalls'][0]));
        self::assertSame(3, $e2['profile']['shiftRuns']);
        self::assertSame(4, $e2['profile']['lifetimeRuns']);
    }

    public function test_incident_label_is_the_primary_calls_id_or_null(): void
    {
        $this->markFeedFresh();
        $this->storeCall(1, ['E1' => ['status' => 'Enroute']]);
        $this->storeCall(2, ['E2' => ['status' => 'Enroute']], ['external_id' => null]);

        $board = $this->board();

        self::assertSame('#2601100001', $this->unit($board, 'E1')['incidentLabel']);
        self::assertSame('ID pending', $this->unit($board, 'E2')['incidentLabel']);
        self::assertNull($this->unit($board, 'E3')['incidentLabel']);
    }

    public function test_the_numbers_for_all_units_come_from_a_fixed_number_of_queries(): void
    {
        foreach (['L1', 'E1', 'R1', 'R11', 'E2', 'R2', 'R22', 'CPT5', '300', 'L3', 'E3', 'R3', 'E4', 'R4', 'R44', 'FB6'] as $i => $code) {
            $this->ranOn($i + 1, $code);
        }

        DB::enableQueryLog();
        $result = app(UnitActivity::class)->forDutyDate(self::TODAY);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        self::assertCount(16, $result['units']);
        self::assertSame(16, $result['totals']['total']);
        self::assertLessThanOrEqual(7, $queries);
    }

    public function test_the_payload_has_no_employee_ids_or_raw_names(): void
    {
        $this->markFeedFresh();
        DailyRosterSnapshotFactory::publish(self::TODAY, ['Station 2 / Engine 2' => [['employee_id' => 'EMP-SECRET-1', 'name' => 'Benson, Mark', 'rank' => 'Lieutenant', 'certifications' => ['PARAMEDIC'], 'code' => 'SECRETCODE', 'category' => 'SECRETCATEGORY']]]);
        DB::table('roster_assignments')->update(['raw_name' => 'Benson, Mark (PARAMEDIC/ALS)']);

        $json = (string) json_encode($this->board());

        foreach (['EMP-SECRET', 'employee_id', 'raw_name', 'SECRETCODE', 'SECRETCATEGORY', 'PARAMEDIC', 'ALS)'] as $leak) {
            self::assertStringNotContainsString($leak, $json, $leak);
        }
        self::assertStringContainsString('Lt. Benson', $json);
    }

    // Review fixes (v1.1.1)

    public function test_a_unit_added_late_to_an_older_call_gets_its_own_ten_minutes(): void
    {
        $this->markFeedFresh();
        DailyRosterSnapshotFactory::publish(self::TODAY, ['Station 1 / Ladder 1' => [['employee_id' => 'EMP-1', 'name' => 'ALPHA, ANN']]]);
        $this->storeCall(1, ['L1' => ['status' => '', 'joined' => now()->subMinutes(2)]], ['received_at' => now()->subMinutes(40)]);

        $l1 = $this->unit($this->board(), 'L1');
        self::assertSame('on_call', $l1['availability']['key']);
        self::assertSame('Assigned', $l1['availability']['label']);

        $this->travel(8)->minutes();
        $this->markFeedFresh();
        self::assertSame('on_call', $this->unit($this->board(), 'L1')['availability']['key'], 'exactly ten minutes since it joined');

        $this->travel(1)->minutes();
        $this->markFeedFresh();
        $after = $this->unit($this->board(), 'L1');
        self::assertSame([], $after['currentCalls']);
        self::assertNotSame('on_call', $after['availability']['key']);
    }

    public function test_a_status_word_the_board_does_not_know_is_treated_as_waiting(): void
    {
        $this->markFeedFresh();
        $this->storeCall(1, ['E1' => ['status' => 'Responding']], ['received_at' => now()->subMinutes(5)]);
        $this->storeCall(2, ['E2' => ['status' => 'Responding']], ['received_at' => now()->subMinutes(11)]);

        $board = $this->board();

        self::assertSame('on_call', $this->unit($board, 'E1')['availability']['key']);
        self::assertSame('Assigned', $this->unit($board, 'E1')['availability']['label']);
        self::assertSame([], $this->unit($board, 'E2')['currentCalls']);
    }

    public function test_the_surname_never_comes_from_the_raw_name(): void
    {
        $this->markFeedFresh();
        DailyRosterSnapshotFactory::publish(self::TODAY, ['Station 2 / Engine 2' => [
            ['employee_id' => 'EMP-SECRET-1', 'name' => 'Mark Benson', 'rank' => 'Lieutenant'],
        ]]);
        DB::table('roster_assignments')->update(['raw_name' => 'Mark Benson (PARAMEDIC, ALS)']);

        $crew = $this->unit($this->board(), 'E2')['crew'];

        self::assertSame(['Lt. Benson'], array_column($crew['onTruck'], 'short'));
        self::assertStringNotContainsString('PARAMEDIC', (string) json_encode($crew));
        self::assertArrayNotHasKey('name', $crew['onTruck'][0]);
        self::assertStringNotContainsString('Mark', (string) json_encode($crew));
    }
}
