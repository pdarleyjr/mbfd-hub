<?php

declare(strict_types=1);

namespace Tests\Feature\UnitStatus;

use App\Models\UnitStatus\UnitStatusCall;
use App\Models\UnitStatus\UnitStatusCallUnit;
use App\Models\UnitStatus\UnitStatusCoverageGap;
use App\Models\UnitStatus\UnitStatusRun;
use App\Models\UnitStatus\UnitStatusSyncState;
use App\Services\UnitStatus\SyncOutcome;
use Illuminate\Support\Facades\Http;

final class UnitStatusSyncTest extends UnitStatusTestCase
{
    public function test_applying_the_same_delta_twice_and_after_a_restart_leaves_identical_ledger_counts(): void
    {
        $page = $this->feedPage([$this->sourceCall(1, [['unitId' => 'E2', 'status' => 'Dispatched'], ['unitId' => 'R2', 'status' => 'Enroute']])]);
        $this->fakeFeed($page);

        $this->sync();
        $first = $this->ledgerCounts();
        $this->travel(1)->minutes();
        $this->sync();
        $this->sync();

        self::assertSame(['E2' => 1, 'R2' => 1], $first);
        self::assertSame($first, $this->ledgerCounts());
        self::assertSame(2, UnitStatusRun::query()->count());
        self::assertSame(1, UnitStatusCall::query()->count());
        self::assertSame(2, UnitStatusCallUnit::query()->count());
    }

    public function test_the_all_digit_unit_300_is_credited_like_any_other_unit(): void
    {
        // Regression: PHP turns the array key "300" into the integer 300, which once broke every sync with 300 on a call.
        $this->fakeFeed($this->feedPage([$this->sourceCall(1, [['unitId' => '300', 'status' => 'Enroute'], ['unitId' => 'CPT5', 'status' => '']])]));

        $outcome = $this->sync();

        self::assertSame(SyncOutcome::OK, $outcome->status);
        self::assertEqualsCanonicalizing(['300' => 1, 'CPT5' => 1], $this->ledgerCounts());
        self::assertSame('Enroute', UnitStatusCallUnit::query()->where('unit_code', '300')->value('status'));
    }

    public function test_a_unit_listed_twice_on_one_call_counts_once(): void
    {
        $this->fakeFeed($this->feedPage([$this->sourceCall(1, [['unitId' => 'E2', 'status' => 'Dispatched'], ['unitId' => 'E2', 'status' => 'On Scene']])]));

        $this->sync();

        self::assertSame(['E2' => 1], $this->ledgerCounts());
        self::assertSame('On Scene', UnitStatusCallUnit::query()->where('unit_code', 'E2')->value('status'));
    }

    public function test_a_multi_unit_call_credits_each_of_our_units_and_ignores_the_rest(): void
    {
        $this->fakeFeed($this->feedPage([$this->sourceCall(1, [
            ['unitId' => 'L1', 'status' => 'Dispatched'], ['unitId' => 'E1', 'status' => 'Enroute'],
            ['unitId' => 'R1', 'status' => 'On Scene'], ['unitId' => 'R11', 'status' => ''],
            ['unitId' => 'G1', 'status' => 'On Scene'], ['unitId' => '810', 'status' => 'Enroute'],
        ])]));

        $this->sync();

        self::assertSame(['E1' => 1, 'L1' => 1, 'R1' => 1, 'R11' => 1], $this->ledgerCounts());
    }

    public function test_a_duplicate_is_never_credited_and_voids_credit_it_already_had(): void
    {
        $first = $this->sourceCall(2, [['unitId' => 'E2', 'status' => 'Dispatched']]);
        $this->fakeFeedRouter();
        $this->deltaBody = $this->feedPage([$first]);
        $this->sync();
        self::assertSame(['E2' => 1], $this->ledgerCounts());

        $this->travel(2)->minutes();
        $later = array_merge($first, ['duplicateOf' => self::callId(99), 'updatedAt' => now()->toIso8601String()]);
        $arrivesAsDuplicate = $this->sourceCall(3, [['unitId' => 'R2', 'status' => 'Dispatched']], ['duplicateOf' => self::callId(99)]);
        $this->deltaBody = $this->feedPage([$later, $arrivesAsDuplicate]);
        $this->sync();

        self::assertSame([], $this->ledgerCounts());
        $voided = UnitStatusRun::query()->where('unit_code', 'E2')->first();
        self::assertSame('duplicate', $voided?->void_reason);
        self::assertNull(UnitStatusRun::query()->where('unit_code', 'R2')->first());
    }

    public function test_a_provisional_call_that_command_folded_into_another_loses_its_credit(): void
    {
        $this->fakeFeedRouter();
        $this->deltaBody = $this->feedPage([$this->sourceCall(4, [['unitId' => 'E3', 'status' => 'Dispatched']], ['externalId' => null, 'provisional' => true])]);
        $this->sync();
        self::assertSame(['E3' => 1], $this->ledgerCounts());

        $this->travel(6)->minutes();
        $this->lookupMissing = [self::callId(4)];
        $this->sync();

        self::assertSame([], $this->ledgerCounts());
        self::assertNotNull(UnitStatusCall::query()->find(self::callId(4))?->merged_at);
        self::assertSame('merged', UnitStatusRun::query()->first()?->void_reason);
    }

    public function test_a_provisional_call_that_still_exists_keeps_its_credit(): void
    {
        $this->fakeFeedRouter();
        $call = $this->sourceCall(4, [['unitId' => 'E3', 'status' => 'Dispatched']], ['externalId' => null, 'provisional' => true]);
        $this->deltaBody = $this->feedPage([$call]);
        $this->lookupCalls = [$call];
        $this->sync();
        $this->travel(6)->minutes();
        $this->sync();

        self::assertSame(['E3' => 1], $this->ledgerCounts());
    }

    public function test_two_hub_rows_with_one_external_id_void_the_older_one(): void
    {
        $this->fakeFeedRouter();
        $this->deltaBody = $this->feedPage([$this->sourceCall(5, [['unitId' => 'E4', 'status' => 'Dispatched']], ['externalId' => '2601999999'])]);
        $this->sync();
        $this->travel(1)->minutes();
        $this->deltaBody = $this->feedPage([$this->sourceCall(6, [['unitId' => 'E4', 'status' => 'Dispatched']], ['externalId' => '2601999999', 'updatedAt' => now()->toIso8601String(), 'lastSeenAt' => now()->toIso8601String()])]);
        $this->sync();

        self::assertSame(['E4' => 1], $this->ledgerCounts());
        self::assertNotNull(UnitStatusCall::query()->find(self::callId(5))?->merged_at);
        self::assertNull(UnitStatusCall::query()->find(self::callId(6))?->merged_at);
        self::assertSame('merged', UnitStatusRun::query()->where('source_call_id', self::callId(5))->value('void_reason'));
    }

    public function test_a_question_mark_unit_is_unconfirmed_until_it_is_seen_without_one(): void
    {
        $call = $this->sourceCall(7, [['unitId' => '?R3', 'status' => 'Dispatched']]);
        $this->fakeFeedRouter();
        $this->deltaBody = $this->feedPage([$call]);
        $this->sync();

        self::assertSame([], $this->ledgerCounts());
        self::assertFalse((bool) UnitStatusRun::query()->first()?->confirmed);

        $this->travel(1)->minutes();
        $this->deltaBody = $this->feedPage([array_merge($call, ['units' => [['unitId' => 'R3', 'status' => 'Enroute']], 'updatedAt' => now()->toIso8601String()])]);
        $this->sync();

        self::assertSame(['R3' => 1], $this->ledgerCounts());
        self::assertSame(1, UnitStatusRun::query()->count());

        // Never back to unconfirmed once seen without the question mark.
        $this->travel(1)->minutes();
        $this->deltaBody = $this->feedPage([array_merge($call, ['units' => [['unitId' => '?R3', 'status' => 'On Scene']], 'updatedAt' => now()->toIso8601String()])]);
        $this->sync();
        self::assertSame(['R3' => 1], $this->ledgerCounts());
    }

    public function test_a_call_attributed_before_tracking_began_earns_no_credit_even_when_updated_later(): void
    {
        $before = $this->sourceCall(8, [['unitId' => 'E1', 'status' => 'On Scene']], [
            'receivedAt' => self::et('2026-11-02 07:30:00')->toIso8601String(),
            'firstSeenAt' => self::et('2026-11-02 07:31:00')->toIso8601String(),
        ]);
        $this->fakeFeedRouter();
        $this->deltaBody = $this->feedPage([$before]);
        $this->sync();
        $this->travel(1)->minutes();
        $this->deltaBody = $this->feedPage([array_merge($before, ['updatedAt' => now()->toIso8601String(), 'lastSeenAt' => now()->toIso8601String()])]);
        $this->sync();

        self::assertSame(0, UnitStatusRun::query()->count());
        self::assertSame(1, UnitStatusCallUnit::query()->count());
    }

    public function test_provenance_is_backfill_before_the_first_sync_and_live_after_it(): void
    {
        $this->fakeFeedRouter();
        $this->deltaBody = $this->feedPage([$this->sourceCall(9, [['unitId' => 'E1', 'status' => 'On Scene']])]);
        $this->sync();
        $this->travel(10)->minutes();
        $this->deltaBody = $this->feedPage([$this->sourceCall(10, [['unitId' => 'E1', 'status' => 'Dispatched']], [
            'receivedAt' => now()->subMinutes(2)->toIso8601String(),
            'firstSeenAt' => now()->subMinutes(1)->toIso8601String(),
            'lastSeenAt' => now()->toIso8601String(),
            'updatedAt' => now()->toIso8601String(),
        ])]);
        $this->sync();

        self::assertSame('backfill', UnitStatusRun::query()->where('source_call_id', self::callId(9))->value('provenance'));
        self::assertSame('live', UnitStatusRun::query()->where('source_call_id', self::callId(10))->value('provenance'));
    }

    public function test_a_unit_added_to_a_known_call_is_attributed_at_that_deliverys_last_seen_time(): void
    {
        $call = $this->sourceCall(11, [['unitId' => 'E1', 'status' => 'Dispatched']], [
            'receivedAt' => self::et('2026-11-02 09:00:00')->toIso8601String(),
            'lastSeenAt' => self::et('2026-11-02 09:05:00')->toIso8601String(),
            'updatedAt' => self::et('2026-11-02 09:05:00')->toIso8601String(),
        ]);
        $this->fakeFeedRouter();
        $this->deltaBody = $this->feedPage([$call]);
        $this->sync();
        $this->travel(1)->minutes();
        $this->deltaBody = $this->feedPage([array_merge($call, [
            'units' => [['unitId' => 'E1', 'status' => 'On Scene'], ['unitId' => 'R1', 'status' => 'Dispatched']],
            'lastSeenAt' => self::et('2026-11-02 10:10:00')->toIso8601String(),
            'updatedAt' => self::et('2026-11-02 10:10:00')->toIso8601String(),
        ])]);
        $this->sync();

        $e1 = UnitStatusRun::query()->where('unit_code', 'E1')->first();
        $r1 = UnitStatusRun::query()->where('unit_code', 'R1')->first();
        self::assertSame('received', $e1?->attribution_basis);
        self::assertEquals(self::et('2026-11-02 09:00:00'), $e1?->attributed_at);
        self::assertSame('unit_added', $r1?->attribution_basis);
        self::assertEquals(self::et('2026-11-02 10:10:00'), $r1?->attributed_at);
    }

    public function test_a_call_without_a_received_time_is_attributed_at_first_seen(): void
    {
        $this->fakeFeed($this->feedPage([$this->sourceCall(12, [['unitId' => 'E1', 'status' => '']], [
            'receivedAt' => null,
            'firstSeenAt' => self::et('2026-11-02 09:20:00')->toIso8601String(),
        ])]));
        $this->sync();

        $run = UnitStatusRun::query()->first();
        self::assertSame('first_seen', $run?->attribution_basis);
        self::assertEquals(self::et('2026-11-02 09:20:00'), $run?->attributed_at);
        self::assertSame(['E1' => 1], $this->ledgerCounts());
    }

    public function test_the_duty_date_flips_at_eight_oclock_eastern(): void
    {
        $this->travelTo(self::et('2026-11-03 09:00:00'));
        $this->fakeFeed($this->feedPage([
            $this->sourceCall(13, [['unitId' => 'E1', 'status' => 'Dispatched']], ['receivedAt' => self::et('2026-11-03 07:59:00')->toIso8601String()]),
            $this->sourceCall(14, [['unitId' => 'E2', 'status' => 'Dispatched']], ['receivedAt' => self::et('2026-11-03 08:00:00')->toIso8601String()]),
        ]));
        $this->sync();

        self::assertSame('2026-11-02', substr((string) UnitStatusRun::query()->where('unit_code', 'E1')->value('duty_date'), 0, 10));
        self::assertSame('2026-11-03', substr((string) UnitStatusRun::query()->where('unit_code', 'E2')->value('duty_date'), 0, 10));
    }

    public function test_the_first_request_reaches_back_twelve_hours_and_later_ones_overlap_the_cursor(): void
    {
        $call = $this->sourceCall(15, [['unitId' => 'E1', 'status' => 'Dispatched']]);
        $this->fakeFeed($this->feedPage([$call]));
        $this->sync();
        $this->travel(1)->minutes();
        $this->sync();

        [$first, $second] = $this->sentQueries();
        self::assertSame('2026-11-02T01:00:00.000000+00:00', $first['updatedSince']);
        self::assertArrayNotHasKey('afterId', $first);
        self::assertSame(self::et('2026-11-02 10:25:00')->subSeconds(120)->format('Y-m-d\TH:i:s.u\+00:00'), $second['updatedSince']);
        self::assertArrayNotHasKey('afterId', $second);
    }

    public function test_an_empty_page_never_drags_the_cursor_back(): void
    {
        $this->fakeFeedRouter();
        $this->deltaBody = $this->feedPage([$this->sourceCall(16, [['unitId' => 'E1', 'status' => 'Dispatched']])]);
        $this->sync();
        $cursor = UnitStatusSyncState::current()?->cursor_updated_since;

        $this->travel(1)->minutes();
        $this->deltaBody = $this->feedPage([], ['nextCursor' => ['updatedSince' => $cursor?->subSeconds(120)->toIso8601String(), 'afterId' => null]]);
        $this->sync();

        self::assertEquals($cursor, UnitStatusSyncState::current()?->cursor_updated_since);
    }

    public function test_pages_are_followed_until_there_are_no_more_and_overlapping_rows_are_harmless(): void
    {
        $one = $this->sourceCall(17, [['unitId' => 'E1', 'status' => 'Dispatched']]);
        $two = $this->sourceCall(18, [['unitId' => 'E2', 'status' => 'Dispatched']], ['updatedAt' => self::et('2026-11-02 10:26:00')->toIso8601String()]);
        $this->fakeFeed(
            $this->feedPage([$one], ['hasMore' => true]),
            $this->feedPage([$one, $two]),
        );

        $outcome = $this->sync();

        self::assertSame(SyncOutcome::OK, $outcome->status);
        self::assertSame(2, $outcome->pages);
        self::assertSame(['E1' => 1, 'E2' => 1], $this->ledgerCounts());
        $queries = $this->sentQueries();
        self::assertTrue(\Carbon\CarbonImmutable::parse($queries[1]['updatedSince'])->equalTo(\Carbon\CarbonImmutable::parse($one['updatedAt'])));
        self::assertSame($one['id'], $queries[1]['afterId']);
        self::assertSame($two['id'], UnitStatusSyncState::current()?->cursor_after_id);
    }

    public function test_a_run_reads_at_most_ten_pages(): void
    {
        $this->fakeFeed($this->feedPage([$this->sourceCall(19, [['unitId' => 'E1', 'status' => 'Dispatched']])], ['hasMore' => true]));

        $outcome = $this->sync();

        self::assertSame(10, $outcome->pages);
        self::assertSame(10, $this->feedRequestCount());
    }

    public function test_the_circuit_opens_after_three_failures_and_backs_off(): void
    {
        $this->fakeFeedStatus(503);

        $this->sync();
        $this->sync();
        self::assertNull(UnitStatusSyncState::current()?->circuit_open_until);
        $this->sync();
        self::assertSame(now()->addSeconds(30)->toIso8601String(), UnitStatusSyncState::current()?->circuit_open_until?->toIso8601String());

        $skipped = $this->sync();
        self::assertSame(SyncOutcome::SKIPPED, $skipped->status);
        self::assertSame(3, $this->feedRequestCount());

        $delays = [];
        foreach ([60, 120, 240, 300, 300] as $expected) {
            $this->travel((int) ceil(now()->diffInSeconds(UnitStatusSyncState::current()?->circuit_open_until, true)) + 1)->seconds();
            $this->sync();
            $delays[] = (int) now()->diffInSeconds(UnitStatusSyncState::current()?->circuit_open_until, true);
        }
        self::assertSame([60, 120, 240, 300, 300], $delays);
        self::assertSame(8, UnitStatusSyncState::current()?->consecutive_failures);
        self::assertSame('feed_not_configured', UnitStatusSyncState::current()?->last_error_class);
    }

    public function test_a_success_closes_the_circuit_and_clears_the_failures(): void
    {
        $this->fakeFeedRouter();
        $this->deltaStatus = 500;
        $this->sync();
        $this->sync();
        $this->sync();
        self::assertNotNull(UnitStatusSyncState::current()?->circuit_open_until);

        $this->travel(31)->seconds();
        $this->deltaStatus = 200;
        $this->deltaBody = $this->feedPage([]);

        self::assertSame(SyncOutcome::OK, $this->sync()->status);
        $state = UnitStatusSyncState::current();
        self::assertSame(0, $state?->consecutive_failures);
        self::assertNull($state?->circuit_open_until);
        self::assertNull($state?->last_error_class);
        self::assertNotNull($state?->last_success_at);
    }

    public function test_an_unknown_schema_version_is_a_failed_run_that_stores_nothing(): void
    {
        $this->fakeFeed($this->feedPage([$this->sourceCall(20, [['unitId' => 'E1', 'status' => 'Dispatched']])], ['schemaVersion' => 2]));

        $outcome = $this->sync();

        self::assertSame(SyncOutcome::FEED_FAILED, $outcome->status);
        self::assertSame('bad_schema', UnitStatusSyncState::current()?->last_error_class);
        self::assertSame(0, UnitStatusCall::query()->count());
        self::assertNull(UnitStatusSyncState::current()?->last_success_at);
    }

    public function test_a_malformed_row_is_skipped_and_counted_without_failing_the_run(): void
    {
        $good = $this->sourceCall(21, [['unitId' => 'E1', 'status' => 'Dispatched']]);
        $this->fakeFeed($this->feedPage([['id' => 'nonsense', 'units' => 'x'], 'not even an object', $good]));

        $outcome = $this->sync();

        self::assertSame(SyncOutcome::OK, $outcome->status);
        self::assertSame(1, $outcome->rowsApplied);
        self::assertSame(2, $outcome->rowsSkipped);
        self::assertSame(2, UnitStatusSyncState::current()?->rows_skipped_total);
        self::assertSame(['E1' => 1], $this->ledgerCounts());
    }

    public function test_coverage_gaps_open_when_the_feed_is_not_live_and_close_when_it_returns(): void
    {
        $this->fakeFeedRouter();
        $this->deltaBody = $this->feedPage([], ['health' => $this->liveHealth('delayed')]);
        $this->sync();
        $gap = UnitStatusCoverageGap::query()->first();
        self::assertSame('feed_delayed', $gap?->reason);
        self::assertNull($gap?->ended_at);

        $this->travel(1)->minutes();
        $this->sync();
        self::assertSame(1, UnitStatusCoverageGap::query()->count());

        $this->travel(1)->minutes();
        $this->deltaBody = $this->feedPage([], ['health' => $this->liveHealth('live')]);
        $this->sync();
        self::assertNotNull(UnitStatusCoverageGap::query()->first()?->ended_at);
        self::assertSame('live', UnitStatusSyncState::current()?->source_state);
    }

    public function test_an_unreachable_command_is_not_a_coverage_gap(): void
    {
        $this->fakeFeedStatus(503);
        $this->sync();
        $this->sync();

        self::assertSame(0, UnitStatusCoverageGap::query()->count());
    }

    public function test_the_tracking_start_is_written_once_and_never_reset(): void
    {
        $this->fakeFeed($this->feedPage([]));
        $this->sync();
        $start = UnitStatusSyncState::current()?->tracking_starts_at;
        $firstSynced = UnitStatusSyncState::current()?->first_synced_at;
        config(['unit_status.tracking_starts_at' => '2026-12-25 08:00']);
        $this->travel(5)->minutes();
        $this->sync();

        self::assertEquals(self::et('2026-11-02 08:00'), $start);
        self::assertEquals($start, UnitStatusSyncState::current()?->tracking_starts_at);
        self::assertEquals($firstSynced, UnitStatusSyncState::current()?->first_synced_at);
    }

    public function test_the_command_does_nothing_and_exits_cleanly_when_the_flag_is_off_or_unconfigured(): void
    {
        Http::fake();
        config(['unit_status.enabled' => false]);
        $this->artisan('unit-status:sync')->expectsOutputToContain('skipped')->assertExitCode(0);

        config(['unit_status.enabled' => true, 'unit_status.source.token' => '']);
        $this->artisan('unit-status:sync')->expectsOutputToContain('not configured')->assertExitCode(0);

        config(['unit_status.source.token' => self::TOKEN, 'unit_status.source.url' => 'http://evil.example']);
        $this->artisan('unit-status:sync')->expectsOutputToContain('not configured')->assertExitCode(0);

        Http::assertNothingSent();
        self::assertNull(UnitStatusSyncState::current());
    }

    public function test_the_command_runs_a_sync_when_configured(): void
    {
        $this->fakeFeed($this->feedPage([$this->sourceCall(22, [['unitId' => 'E1', 'status' => 'Dispatched']])]));

        $this->artisan('unit-status:sync')->expectsOutputToContain('ok')->assertExitCode(0);

        self::assertSame(['E1' => 1], $this->ledgerCounts());
    }

    public function test_a_hub_side_failure_is_recorded_and_fails_the_command(): void
    {
        $this->fakeFeed($this->feedPage([$this->sourceCall(23, [['unitId' => 'E1', 'status' => 'Dispatched']])]));
        \Illuminate\Support\Facades\Schema::drop('unit_status_call_units');

        $this->artisan('unit-status:sync')->assertExitCode(1);

        self::assertSame('hub_error', UnitStatusSyncState::current()?->last_error_class);
    }

    public function test_the_schedule_runs_the_sync_every_minute_and_never_as_a_sub_minute_event(): void
    {
        $event = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
            ->first(static fn ($event): bool => str_contains((string) $event->command, 'unit-status:sync'));

        self::assertNotNull($event);
        self::assertSame('* * * * *', $event->expression);
        // schedule:run is a shell loop with `sleep 60`; a sub-minute task would make it block until the minute ends.
        $repeat = new \ReflectionProperty($event, 'repeatSeconds');
        self::assertNull($repeat->getValue($event));
        self::assertTrue($event->withoutOverlapping);
        self::assertTrue($event->onOneServer);
        self::assertSame(2, $event->expiresAt);
    }

    public function test_the_token_is_never_written_to_logs_or_exception_messages(): void
    {
        $this->fakeFeedStatus(401);
        $log = [];
        \Illuminate\Support\Facades\Log::listen(function ($message) use (&$log): void {
            $log[] = $message->message.json_encode($message->context);
        });

        $this->sync();

        self::assertStringNotContainsString(self::TOKEN, implode("\n", $log));
        self::assertStringNotContainsString(self::TOKEN, (string) UnitStatusSyncState::current()?->last_error_class);
        self::assertSame('unauthorized', UnitStatusSyncState::current()?->last_error_class);
    }
}
