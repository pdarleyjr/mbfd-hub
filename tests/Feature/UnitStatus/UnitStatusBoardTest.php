<?php

declare(strict_types=1);

namespace Tests\Feature\UnitStatus;

use App\Models\UnitStatus\UnitStatusCoverageGap;
use App\Models\UnitStatus\UnitStatusRun;
use App\Services\UnitStatus\UnitStatusBoard;
use Carbon\CarbonImmutable;
use Tests\Support\DailyRosterSnapshotFactory;

final class UnitStatusBoardTest extends UnitStatusTestCase
{
    /** @return array<string, mixed> */
    private function board(?CarbonImmutable $at = null): array
    {
        return app(UnitStatusBoard::class)->build($at ?? now());
    }

    /** @param  array<string, mixed>  $board */
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

    /** @param  array<string, list<array<string, mixed>>>  $sections */
    private function publishRoster(array $sections, array $options = []): void
    {
        DailyRosterSnapshotFactory::publish(self::TODAY, $sections, $options);
    }

    /** @return list<array<string, mixed>> */
    private function fullCrew(): array
    {
        return [['employee_id' => 'EMP-SECRET-1', 'name' => 'ALPHA, ANN', 'rank' => 'Lieutenant'], ['employee_id' => 'EMP-SECRET-2', 'name' => 'BRAVO, BOB', 'rank' => 'Firefighter']];
    }

    public function test_the_board_always_lists_sixteen_units_in_five_stations_even_with_nothing_synced(): void
    {
        $board = $this->board();

        self::assertSame(1, $board['schemaVersion']);
        self::assertSame([1, 2, 3, 4, 6], array_column($board['stations'], 'number'));
        self::assertSame(['Station 1', 'Station 2', 'Station 3', 'Station 4', 'Station 6'], array_column($board['stations'], 'label'));
        $codes = array_merge(...array_map(static fn (array $station): array => array_column($station['units'], 'code'), $board['stations']));
        self::assertSame(['L1', 'E1', 'R1', 'R11', 'E2', 'R2', 'R22', 'CPT5', '300', 'L3', 'E3', 'R3', 'E4', 'R4', 'R44', 'FB6'], $codes);
        self::assertFalse($board['live']);
        self::assertSame('starting', $board['source']['state']);
        self::assertSame('Not confirmed', $this->unit($board, 'E2')['availability']['label']);
        self::assertSame('unit-r44', $this->unit($board, 'R44')['domId']);
        self::assertSame('none', $board['roster']['state']);
        self::assertStringContainsString('Personnel not confirmed', $board['roster']['message']);
    }

    public function test_an_unconfigured_feed_is_reported_as_such_and_never_green(): void
    {
        config(['unit_status.source.token' => '']);
        $this->publishRoster(['Station 2 / Rescue 2' => $this->fullCrew()]);

        $board = $this->board();

        self::assertSame('not_configured', $board['source']['state']);
        self::assertSame('unknown', $this->unit($board, 'R2')['availability']['key']);
    }

    public function test_available_needs_a_fresh_feed_a_current_roster_and_working_crew(): void
    {
        $this->markFeedFresh();
        $this->publishRoster([
            'Station 2 / Rescue 2' => $this->fullCrew(),
            'Station 2 / Engine 2' => [['employee_id' => 'EMP-SECRET-3', 'name' => 'CHARLIE, CY', 'availability' => 'not_working', 'code' => 'VAC']],
            'Station 3 / Engine 3' => [['employee_id' => 'EMP-SECRET-4', 'name' => 'DELTA, DEE', 'availability' => 'annotation']],
        ]);

        $board = $this->board();

        self::assertTrue($board['live']);
        self::assertSame('live', $board['source']['state']);
        $r2 = $this->unit($board, 'R2')['availability'];
        self::assertSame('available', $r2['key']);
        self::assertSame('Available', $r2['label']);
        self::assertSame('success', $r2['tone']);
        self::assertSame('not_staffed', $this->unit($board, 'E2')['availability']['key']);
        self::assertSame('not_staffed', $this->unit($board, 'E3')['availability']['key']);
        $unlisted = $this->unit($board, 'L1')['availability'];
        self::assertSame('no_active_call', $unlisted['key']);
        self::assertSame('Crew not confirmed', $unlisted['note']);
        self::assertSame('not_listed', $this->unit($board, 'L1')['crew']['state']);
    }

    public function test_a_stale_feed_is_never_green_even_with_a_full_roster(): void
    {
        $this->markFeedFresh('live', now()->subSeconds(151));
        $this->publishRoster(['Station 2 / Rescue 2' => $this->fullCrew()]);

        $board = $this->board();

        $r2 = $this->unit($board, 'R2')['availability'];
        self::assertSame('unknown', $r2['key']);
        self::assertSame('Not confirmed', $r2['label']);
        self::assertSame('warning', $r2['tone']);
        self::assertTrue($r2['stale']);
        self::assertSame('PulsePoint feed delayed', $r2['note']);
        self::assertSame(now()->subSeconds(151)->utc()->format('Y-m-d\TH:i:s\Z'), $r2['observedAt']);
        self::assertFalse($board['live']);
        self::assertSame('delayed', $board['source']['state']);
    }

    public function test_the_edge_of_freshness_is_150_seconds(): void
    {
        $this->markFeedFresh('live', now()->subSeconds(150));
        self::assertTrue($this->board()['source']['fresh']);

        $this->markFeedFresh('live', now()->subSeconds(151));
        self::assertFalse($this->board()['source']['fresh']);
    }

    public function test_a_collector_that_is_not_live_makes_every_unit_unconfirmed(): void
    {
        foreach (['delayed' => 'PulsePoint feed delayed', 'unavailable' => 'PulsePoint feed unavailable', 'starting' => 'PulsePoint feed starting'] as $state => $note) {
            $this->markFeedFresh($state);
            $this->publishRoster(['Station 2 / Rescue 2' => $this->fullCrew()]);
            $unit = $this->unit($this->board(), 'R2')['availability'];

            self::assertSame('unknown', $unit['key'], $state);
            self::assertSame($note, $unit['note'], $state);
        }
    }

    public function test_a_hub_that_cannot_reach_the_feed_says_so(): void
    {
        $state = $this->markFeedFresh('live', now()->subMinutes(10));
        $state->forceFill(['consecutive_failures' => 4])->save();

        $board = $this->board();

        self::assertSame('unreachable', $board['source']['state']);
        self::assertSame('Hub cannot reach the feed', $this->unit($board, 'E1')['availability']['note']);
        self::assertStringContainsString('Last update', $board['source']['message']);
    }

    public function test_without_a_roster_for_today_there_is_no_green_and_no_names(): void
    {
        $this->markFeedFresh();
        DailyRosterSnapshotFactory::publish('2026-11-01', ['Station 2 / Rescue 2' => $this->fullCrew()]);

        $board = $this->board();

        $r2 = $this->unit($board, 'R2');
        self::assertSame('no_active_call', $r2['availability']['key']);
        self::assertSame('Crew not confirmed', $r2['availability']['note']);
        self::assertSame('not_posted', $r2['crew']['state']);
        self::assertSame([], $r2['crew']['onTruck']);
        self::assertStringNotContainsString('ALPHA', (string) json_encode($board));
        self::assertFalse($board['live']);
    }

    public function test_a_delayed_roster_shows_the_crew_as_delayed_and_never_green(): void
    {
        config(['daily_roster.cadence.expected_interval_minutes' => 5]);
        $this->markFeedFresh();
        $this->publishRoster(['Station 2 / Rescue 2' => $this->fullCrew()], ['generated_at' => now()->subHour()]);

        $board = $this->board();

        self::assertSame('delayed', $board['roster']['state']);
        $r2 = $this->unit($board, 'R2');
        self::assertSame('delayed', $r2['crew']['state']);
        self::assertSame('ALPHA, ANN', $r2['crew']['onTruck'][0]['name']);
        self::assertNotNull($r2['crew']['note']);
        self::assertSame('no_active_call', $r2['availability']['key']);
        self::assertFalse($board['live']);
    }

    public function test_an_active_call_shows_the_pulsepoint_status_word_and_its_family(): void
    {
        $this->markFeedFresh();
        $this->storeCall(1, ['E2' => ['status' => 'On Scene'], 'R2' => ['status' => ''], 'R3' => ['status' => 'Dispatched', 'confirmed' => false]]);

        $board = $this->board();

        $e2 = $this->unit($board, 'E2');
        self::assertSame('on_call', $e2['availability']['key']);
        self::assertSame('On Scene', $e2['availability']['label']);
        self::assertSame('on_scene', $e2['availability']['family']);
        self::assertSame('rgb(204,0,0)', $e2['availability']['indicatorColor']);
        self::assertSame('source', $e2['availability']['tone']);
        self::assertTrue($e2['availability']['confirmed']);
        self::assertSame('Medical Emergency – 1230 OCEAN AVE, MIAMI BEACH, FL', $e2['assignmentSummary']);
        self::assertSame('Assigned', $this->unit($board, 'R2')['availability']['label']);
        self::assertFalse($this->unit($board, 'R3')['availability']['confirmed']);
        $call = $e2['currentCalls'][0];
        self::assertSame('#2601100001', $call['idLabel']);
        self::assertSame('9:15 AM ET', $call['receivedLabel']);
        self::assertSame('https://web.pulsepoint.org/DeepLink.aspx?incidentid=1', $call['sourceUrl']);
        self::assertNull($call['location']);
    }

    public function test_a_provisional_call_has_no_id_yet_and_other_units_are_listed(): void
    {
        $this->markFeedFresh();
        $this->storeCall(2, ['E3' => ['status' => 'Enroute']], [
            'external_id' => null,
            'lat' => 25.78, 'lng' => -80.13, 'location_quality' => 'approximate',
            'units_json' => [['unitId' => 'E3', 'status' => 'Enroute'], ['unitId' => 'G1', 'status' => 'On Scene'], ['unitId' => 'G1', 'status' => 'Enroute'], ['unitId' => 'R3', 'status' => 'Dispatched']],
        ]);

        $call = $this->unit($this->board(), 'E3')['currentCalls'][0];

        self::assertSame('ID pending', $call['idLabel']);
        self::assertNull($call['externalId']);
        self::assertSame(['lat' => 25.78, 'lng' => -80.13, 'quality' => 'approximate'], $call['location']);
        self::assertSame([['designation' => 'G1', 'status' => 'On Scene'], ['designation' => 'R3', 'status' => 'Dispatched']], $call['otherUnits']);
    }

    public function test_a_stale_feed_still_shows_the_cached_call_but_marks_it_stale(): void
    {
        $this->markFeedFresh('live', now()->subMinutes(5));
        $this->storeCall(1, ['E2' => ['status' => 'Enroute']]);

        $availability = $this->unit($this->board(), 'E2')['availability'];

        self::assertSame('on_call', $availability['key']);
        self::assertTrue($availability['stale']);
        self::assertStringStartsWith('May be out of date.', (string) $availability['note']);
    }

    public function test_overlapping_active_calls_are_a_conflict_in_a_deterministic_order(): void
    {
        $this->markFeedFresh();
        $this->storeCall(1, ['E1' => ['status' => 'On Scene']], ['received_at' => self::et('2026-11-02 09:00:00')]);
        $this->storeCall(3, ['E1' => ['status' => 'Enroute']], ['received_at' => self::et('2026-11-02 10:00:00')]);
        $this->storeCall(2, ['E1' => ['status' => 'Dispatched']], ['received_at' => self::et('2026-11-02 10:00:00')]);

        $e1 = $this->unit($this->board(), 'E1');

        self::assertTrue($e1['conflict']);
        self::assertSame([self::callId(2), self::callId(3), self::callId(1)], array_column($e1['currentCalls'], 'sourceCallId'));
        self::assertSame('Dispatched', $e1['availability']['label']);
        self::assertSame($e1, $this->unit($this->board(), 'E1'));
    }

    public function test_calls_that_are_over_do_not_count_as_current(): void
    {
        $this->markFeedFresh();
        $this->publishRoster(['Station 1 / Ladder 1' => $this->fullCrew()]);
        $this->storeCall(1, ['L1' => ['status' => 'On Scene']], ['closed_at' => self::et('2026-11-02 10:00:00')]);
        $this->storeCall(2, ['L1' => ['status' => 'On Scene']], ['disappeared_at' => self::et('2026-11-02 10:00:00')]);
        $this->storeCall(3, ['L1' => ['status' => 'On Scene']], ['merged_at' => self::et('2026-11-02 10:00:00')]);
        $this->storeCall(4, ['L1' => ['status' => 'On Scene']], ['duplicate_of' => self::callId(99)]);
        $this->storeCall(5, ['L1' => ['status' => 'On Scene']], ['section' => 'recent']);
        $this->storeCall(6, ['L1' => ['status' => 'On Scene']], ['section' => 'unknown']);

        $l1 = $this->unit($this->board(), 'L1');

        self::assertSame([], $l1['currentCalls']);
        self::assertFalse($l1['conflict']);
        self::assertSame('available', $l1['availability']['key']);
        self::assertSame('No active call', $l1['assignmentSummary']);
    }

    public function test_a_cleared_unit_is_not_current_even_while_the_call_is_active(): void
    {
        $this->markFeedFresh();
        $this->publishRoster(['Station 3 / Rescue 3' => $this->fullCrew()]);
        $this->storeCall(1, ['R3' => ['status' => 'Cleared From Incident'], 'E3' => ['status' => 'On Scene']]);

        $board = $this->board();

        self::assertSame('available', $this->unit($board, 'R3')['availability']['key']);
        self::assertSame('on_call', $this->unit($board, 'E3')['availability']['key']);
    }

    public function test_the_crew_comes_only_from_working_rows_in_their_time_window(): void
    {
        $this->markFeedFresh();
        $this->publishRoster(['Station 1 / Rescue 1' => [
            ['employee_id' => 'EMP-SECRET-1', 'name' => 'ALPHA, ANN', 'rank' => 'Captain', 'seat' => 0],
            ['employee_id' => 'EMP-SECRET-1', 'name' => 'ALPHA, ANN', 'rank' => 'Captain', 'seat' => 0, 'continuation' => true],
            ['employee_id' => 'EMP-SECRET-2', 'name' => 'BRAVO, BOB', 'rank' => 'Firefighter', 'seat' => 1, 'from' => '08:00', 'through' => '14:00'],
            ['employee_id' => 'EMP-SECRET-3', 'name' => 'CHARLIE, CY', 'rank' => 'Firefighter', 'seat' => 1, 'from' => '19:00', 'through' => '08:00'],
            ['employee_id' => 'EMP-SECRET-4', 'name' => 'DELTA, DEE', 'rank' => 'Firefighter', 'seat' => 2, 'availability' => 'not_working', 'code' => 'SICK'],
            ['employee_id' => 'EMP-SECRET-5', 'name' => 'ECHO, ED', 'rank' => 'Firefighter', 'seat' => 3, 'availability' => 'annotation', 'code' => 'TRN'],
            ['employee_id' => 'EMP-SECRET-6', 'name' => 'FOXTROT, FAY', 'rank' => 'Firefighter', 'seat' => 4, 'availability' => 'unknown'],
            ['employee_id' => 'EMP-SECRET-7', 'name' => 'GOLF, GUS', 'rank' => 'Firefighter', 'seat' => 5, 'from' => '08:00', 'through' => '10:00'],
            ['kind' => 'vacancy_open', 'rank' => 'Firefighter', 'seat' => 6],
            ['kind' => 'vacancy_suppressed', 'rank' => 'Firefighter', 'seat' => 7],
        ]]);

        $crew = $this->unit($this->board(), 'R1')['crew'];

        self::assertSame('confirmed', $crew['state']);
        self::assertSame([['rank' => 'Captain', 'name' => 'ALPHA, ANN'], ['rank' => 'Firefighter', 'name' => 'BRAVO, BOB']], $crew['onTruck']);
        self::assertSame([['rank' => 'Firefighter', 'name' => 'CHARLIE, CY', 'from' => '19:00', 'through' => '08:00']], $crew['later']);
        self::assertSame(1, $crew['vacancies']);
        self::assertNotNull($crew['asOf']);
    }

    public function test_a_relief_takes_over_at_the_changeover_time(): void
    {
        $this->markFeedFresh();
        $this->publishRoster(['Station 1 / Rescue 1' => [
            ['employee_id' => 'EMP-SECRET-1', 'name' => 'ALPHA, ANN', 'from' => '08:00', 'through' => '14:00'],
            ['employee_id' => 'EMP-SECRET-2', 'name' => 'BRAVO, BOB', 'from' => '14:00', 'through' => '08:00'],
        ]]);

        $this->travelTo(self::et('2026-11-02 13:59:59'));
        self::assertSame(['ALPHA, ANN'], array_column($this->unit($this->board(), 'R1')['crew']['onTruck'], 'name'));
        $this->travelTo(self::et('2026-11-02 14:00:00'));
        $this->markFeedFresh();
        self::assertSame(['BRAVO, BOB'], array_column($this->unit($this->board(), 'R1')['crew']['onTruck'], 'name'));
    }

    public function test_a_unit_whose_crew_is_all_off_is_not_staffed(): void
    {
        $this->markFeedFresh();
        $this->publishRoster(['Station 4 / Rescue 44' => [['employee_id' => 'EMP-SECRET-1', 'name' => 'ALPHA, ANN', 'availability' => 'not_working', 'code' => 'VAC']]]);

        $r44 = $this->unit($this->board(), 'R44');

        self::assertSame('not_staffed', $r44['availability']['key']);
        self::assertSame('none_working', $r44['crew']['state']);
        self::assertSame([], $r44['crew']['onTruck']);
        self::assertStringNotContainsString('ALPHA', (string) json_encode($r44));
    }

    public function test_the_payload_never_contains_roster_internals(): void
    {
        $this->markFeedFresh();
        $this->publishRoster(['Station 2 / Rescue 2' => $this->fullCrew() + [2 => ['employee_id' => 'EMP-SECRET-9', 'name' => 'ZULU, ZED', 'availability' => 'not_working', 'code' => 'SICK', 'category' => 'leave', 'certifications' => ['PARAMEDIC']]]]);

        $json = (string) json_encode($this->board());

        foreach (['EMP-SECRET', 'employee_id', 'employeeId', 'raw_code', 'base_code', 'category', 'review_reasons', 'certifications', 'raw_name', 'SICK', 'PARAMEDIC', 'ZULU'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $json, $forbidden);
        }
        self::assertStringContainsString('ALPHA, ANN', $json);
    }

    public function test_run_counts_separate_this_shift_lifetime_unconfirmed_and_backfilled(): void
    {
        $this->markFeedFresh();
        $make = static fn (int $n, array $a): UnitStatusRun => UnitStatusRun::query()->create($a + [
            'unit_code' => 'E2', 'source_call_id' => self::callId($n), 'attributed_at' => self::et('2026-11-02 09:00:00'),
            'attribution_basis' => 'received', 'duty_date' => '2026-11-02', 'confirmed' => true, 'provenance' => 'live',
        ]);
        $make(1, []);
        $make(2, ['provenance' => 'backfill']);
        $make(3, ['confirmed' => false]);
        $make(4, ['duty_date' => '2026-11-01']);
        $make(5, ['duty_date' => '2026-11-01', 'confirmed' => false]);
        $make(6, ['voided_at' => now(), 'void_reason' => 'duplicate']);
        $make(7, ['unit_code' => 'R2']);

        $board = $this->board();

        self::assertSame(['shift' => 2, 'shiftUnconfirmed' => 1, 'lifetime' => 3, 'lifetimeUnconfirmed' => 2, 'backfilledLifetime' => 1], $this->unit($board, 'E2')['runs']);
        self::assertSame(1, $this->unit($board, 'R2')['runs']['shift']);
        self::assertSame(0, $this->unit($board, 'E1')['runs']['lifetime']);
        self::assertSame($board['stations'], $this->board()['stations']);
    }

    public function test_the_shift_count_rolls_over_at_eight_while_lifetime_keeps_counting(): void
    {
        $this->markFeedFresh();
        UnitStatusRun::query()->create([
            'unit_code' => 'E2', 'source_call_id' => self::callId(1), 'attributed_at' => self::et('2026-11-02 07:59:00'),
            'attribution_basis' => 'received', 'duty_date' => '2026-11-01', 'confirmed' => true, 'provenance' => 'live',
        ]);

        $this->travelTo(self::et('2026-11-02 07:59:30'));
        $this->markFeedFresh();
        self::assertSame(1, $this->unit($this->board(), 'E2')['runs']['shift']);

        $this->travelTo(self::et('2026-11-02 08:00:00'));
        $this->markFeedFresh();
        $after = $this->unit($this->board(), 'E2')['runs'];
        self::assertSame(0, $after['shift']);
        self::assertSame(1, $after['lifetime']);
    }

    public function test_the_etag_ignores_the_generation_time_but_follows_the_content(): void
    {
        $this->markFeedFresh();
        $one = $this->board();
        $this->travelTo(now()->addSeconds(10));
        $two = $this->board();

        self::assertNotSame($one['generatedAt'], $two['generatedAt']);
        self::assertSame($one['etag'], $two['etag']);

        $this->storeCall(1, ['E2' => ['status' => 'Enroute']]);
        self::assertNotSame($one['etag'], $this->board()['etag']);
    }

    public function test_tracking_the_duty_window_and_the_coverage_gaps_are_described(): void
    {
        $this->markFeedFresh();
        UnitStatusCoverageGap::query()->create(['started_at' => self::et('2026-11-02 09:00:00'), 'ended_at' => self::et('2026-11-02 09:10:00'), 'reason' => 'feed_delayed']);
        UnitStatusCoverageGap::query()->create(['started_at' => self::et('2026-11-02 10:00:00'), 'reason' => 'feed_unavailable']);

        $board = $this->board();

        self::assertSame('Nov 2, 8:00 AM – Nov 3, 8:00 AM ET', $board['dutyWindow']['label']);
        self::assertSame('2026-11-02', $board['dutyWindow']['dutyDate']);
        self::assertSame('2026-11-02T13:00:00Z', $board['dutyWindow']['startsAt']);
        self::assertSame('2026-11-03T13:00:00Z', $board['dutyWindow']['endsAt']);
        self::assertSame('Nov 2, 8:00 AM ET', $board['tracking']['startsLabel']);
        self::assertTrue($board['tracking']['openGap']);
        self::assertCount(2, $board['tracking']['gaps']);
        self::assertSame('feed_unavailable', $board['tracking']['gaps'][0]['reason']);
        self::assertStringContainsString('A run is a PulsePoint call that listed this unit', $board['tracking']['definition']);
        self::assertSame('PulsePoint is advisory. Verify with dispatch.', $board['advisory']);
    }
}
