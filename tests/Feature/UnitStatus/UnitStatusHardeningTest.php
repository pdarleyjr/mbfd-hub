<?php

declare(strict_types=1);

namespace Tests\Feature\UnitStatus;

use App\Models\UnitStatus\UnitStatusCall;
use App\Models\UnitStatus\UnitStatusRun;
use App\Models\UnitStatus\UnitStatusSyncState;
use App\Services\UnitStatus\UnitStatusBoard;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\DailyRosterSnapshotFactory;

/** Review fixes: ghost calls, signed-out refreshes, backlog, immediate merge check, board cache, chip semantics. */
final class UnitStatusHardeningTest extends UnitStatusTestCase
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

    /** @return list<list<string>> the ids of every lookup request, in order */
    private function lookups(): array
    {
        return array_values(array_map(
            static fn (array $query): array => explode(',', (string) $query['ids']),
            array_filter($this->sentQueries(), static fn (array $query): bool => isset($query['ids'])),
        ));
    }

    /**
     * A Command that really pages: rows ordered by (updatedAt, id), cursor semantics as in the contract.
     *
     * @param  list<array<string, mixed>>  $rows  already ordered
     */
    private function fakePagingFeed(array $rows): void
    {
        $stamps = array_map(static fn (array $row): int => CarbonImmutable::parse($row['updatedAt'])->getTimestamp(), $rows);
        Http::fake(['cmd-api:8200/*' => function (Request $request) use ($rows, $stamps) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            if (isset($query['ids'])) {
                return Http::response($this->feedPage([], ['mode' => 'lookup', 'missing' => []]), 200);
            }
            $since = CarbonImmutable::parse($query['updatedSince'])->getTimestamp();
            $after = $query['afterId'] ?? null;
            $limit = (int) $query['limit'];
            $matching = [];
            foreach ($rows as $index => $row) {
                $newer = $stamps[$index] > $since || ($after !== null && $stamps[$index] === $since && strcmp($row['id'], $after) > 0);
                if ($newer) {
                    $matching[] = $row;
                }
            }
            $page = array_slice($matching, 0, $limit);
            $last = $page === [] ? null : $page[array_key_last($page)];

            return Http::response($this->feedPage($page, [
                'hasMore' => count($matching) > $limit,
                'nextCursor' => ['updatedSince' => $last['updatedAt'] ?? $query['updatedSince'], 'afterId' => $last['id'] ?? $after],
            ]), 200);
        }]);
    }

    // 1. Ghost current calls

    public function test_a_provisional_call_the_hub_missed_the_merge_of_is_retired_by_a_lookup_however_old(): void
    {
        $this->fakeFeedRouter();
        $this->deltaBody = $this->feedPage([$this->sourceCall(1, [['unitId' => 'E1', 'status' => 'On Scene']], ['externalId' => null, 'provisional' => true])]);
        $this->sync();
        self::assertSame(['E1' => 1], $this->ledgerCounts());

        // A closed provisional call from days ago is not current and must not be looked up.
        $this->storeCall(2, ['E2' => ['status' => 'On Scene']], ['external_id' => null, 'closed_at' => now()->subDays(3), 'last_seen_at' => now()->subDays(3)]);

        $this->travel(25)->hours();
        $this->deltaBody = $this->feedPage([]);
        $this->lookupMissing = [self::callId(1)];
        $this->sync();

        $lookups = $this->lookups();
        self::assertSame([self::callId(1)], end($lookups));
        self::assertNotNull(UnitStatusCall::query()->find(self::callId(1))?->merged_at);
        self::assertSame([], $this->ledgerCounts());
        self::assertSame('merged', UnitStatusRun::query()->where('unit_code', 'E1')->value('void_reason'));
    }

    public function test_lookups_are_paged_at_one_hundred_ids(): void
    {
        $this->fakeFeedRouter();
        $this->deltaBody = $this->feedPage([]);
        foreach (range(1, 250) as $n) {
            $this->storeCall($n, [], ['external_id' => null]);
        }

        $this->sync();

        self::assertSame([100, 100, 50], array_map('count', $this->lookups()));
    }

    public function test_a_unit_whose_only_open_call_is_over_twelve_hours_old_is_not_confirmed_and_never_available(): void
    {
        $this->markFeedFresh();
        DailyRosterSnapshotFactory::publish(self::TODAY, ['Station 1 / Engine 1' => [['employee_id' => 'EMP-SECRET-1', 'name' => 'ALPHA, ANN']]]);
        $this->storeCall(1, ['E1' => ['status' => 'On Scene']], ['received_at' => now()->subHours(13)]);

        $e1 = $this->unit($this->board(), 'E1');

        self::assertSame('unknown', $e1['availability']['key']);
        self::assertSame('Not confirmed', $e1['availability']['label']);
        self::assertSame('Call open more than 12 hours in PulsePoint', $e1['availability']['note']);
        self::assertSame([], $e1['currentCalls']);
        self::assertSame('Not confirmed', $e1['assignmentSummary']);
        self::assertNull($e1['availability']['confirmed']);
    }

    public function test_the_age_limit_is_twelve_hours_from_received_with_first_seen_as_the_fallback(): void
    {
        $this->markFeedFresh();
        $this->storeCall(1, ['E1' => ['status' => 'On Scene']], ['received_at' => now()->subHours(12)->addMinute()]);
        $this->storeCall(2, ['E2' => ['status' => 'On Scene']], ['received_at' => null, 'first_seen_at' => now()->subHours(12)->subMinute()]);
        $this->storeCall(3, ['R1' => ['status' => 'On Scene']], ['received_at' => now()->subHours(20)]);
        $this->storeCall(4, ['R1' => ['status' => 'Enroute']], ['received_at' => now()->subHours(1)]);

        $board = $this->board();

        self::assertSame('on_call', $this->unit($board, 'E1')['availability']['key']);
        self::assertSame('unknown', $this->unit($board, 'E2')['availability']['key']);
        // A fresh call wins; the stale one is ignored rather than shown as a conflict.
        $r1 = $this->unit($board, 'R1');
        self::assertSame('on_call', $r1['availability']['key']);
        self::assertCount(1, $r1['currentCalls']);
        self::assertFalse($r1['conflict']);
    }

    // 2. Signed-out background refresh

    public function test_a_signed_out_background_refresh_gets_a_401_json_not_a_redirect(): void
    {
        foreach (['/unit-status/board', '/unit-status/data', '/unit-status/units/E2/runs'] as $url) {
            $response = $this->get($url, ['X-Hub-Background-Refresh' => '1'])->assertStatus(401)->assertExactJson(['error' => 'signed_out']);
            $header = (string) $response->headers->get('Cache-Control');
            self::assertStringContainsString('no-store', $header);
            self::assertStringContainsString('private', $header);
        }
    }

    public function test_a_normal_signed_out_request_still_redirects_to_login(): void
    {
        foreach (['/unit-status', '/unit-status/board', '/unit-status/data', '/unit-status/units/E2/runs'] as $url) {
            $this->get($url)->assertRedirect('/login');
        }
        $this->get('/unit-status/data', ['X-Hub-Background-Refresh' => '0'])->assertRedirect('/login');
        $this->get('/unit-status', ['X-Hub-Background-Refresh' => '1'])->assertRedirect('/login');
    }

    public function test_a_signed_in_background_refresh_is_served_normally(): void
    {
        $this->markFeedFresh();
        $this->actingAsCanonicalFixture();

        $this->get('/unit-status/data', ['X-Hub-Background-Refresh' => '1'])->assertOk()->assertJsonPath('schemaVersion', 1);
    }

    // 3. Backlog

    public function test_more_than_two_thousand_rows_inside_one_overlap_window_still_advance_across_runs(): void
    {
        config(['unit_status.run_budget_seconds' => 1000]);
        $rows = [];
        foreach (range(1, 2300) as $n) {
            $stamp = self::et('2026-11-02 10:00:00')->addSeconds(intdiv($n, 50))->toIso8601String();
            $rows[] = $this->sourceCall($n, [], ['updatedAt' => $stamp, 'lastSeenAt' => $stamp]);
        }
        $this->fakePagingFeed($rows);

        $first = $this->sync();
        $cursor = UnitStatusSyncState::current();
        self::assertSame(10, $first->pages);
        self::assertSame(2000, UnitStatusCall::query()->count());
        self::assertTrue($cursor?->backlog);
        $afterFirst = [$cursor?->cursor_updated_since?->toIso8601String(), $cursor?->cursor_after_id];

        $this->travel(1)->minutes();
        $second = $this->sync();

        $queries = $this->sentQueries();
        $resume = $queries[10];
        self::assertSame(CarbonImmutable::parse($afterFirst[0])->getTimestamp(), CarbonImmutable::parse($resume['updatedSince'])->getTimestamp());
        self::assertSame($afterFirst[1], $resume['afterId']);
        self::assertSame(300, $second->rowsApplied);
        self::assertSame(2300, UnitStatusCall::query()->count());
        self::assertFalse((bool) UnitStatusSyncState::current()?->backlog);
        self::assertSame($rows[2299]['id'], UnitStatusSyncState::current()?->cursor_after_id);
    }

    public function test_a_backlog_makes_the_feed_delayed_and_says_so(): void
    {
        config(['unit_status.max_pages' => 1]);
        $this->fakePagingFeed(array_map(
            fn (int $n): array => $this->sourceCall($n, [], ['updatedAt' => self::et('2026-11-02 10:00:00')->addSeconds($n)->toIso8601String()]),
            range(1, 450),
        ));
        $this->sync();
        DailyRosterSnapshotFactory::publish(self::TODAY, ['Station 1 / Engine 1' => [['employee_id' => 'EMP-SECRET-1', 'name' => 'ALPHA, ANN']]]);

        $board = $this->board();

        self::assertTrue((bool) UnitStatusSyncState::current()?->backlog);
        self::assertSame('delayed', $board['source']['state']);
        self::assertFalse($board['source']['fresh']);
        self::assertFalse($board['live']);
        self::assertStringContainsString('Catching up with PulsePoint calls', $board['source']['message']);
        self::assertSame('Catching up with PulsePoint calls', $this->unit($board, 'E1')['availability']['note']);
        self::assertSame('unknown', $this->unit($board, 'E1')['availability']['key']);

        $this->sync();
        $this->sync();
        $after = $this->board();
        self::assertFalse((bool) UnitStatusSyncState::current()?->backlog);
        self::assertSame('live', $after['source']['state']);
    }

    public function test_the_time_budget_stops_paging_and_counts_as_a_backlog(): void
    {
        config(['unit_status.run_budget_seconds' => 0]);
        $this->fakePagingFeed(array_map(
            fn (int $n): array => $this->sourceCall($n, [], ['updatedAt' => self::et('2026-11-02 10:00:00')->addSeconds($n)->toIso8601String()]),
            range(1, 450),
        ));

        $run = $this->sync();

        self::assertSame(1, $run->pages);
        self::assertTrue((bool) UnitStatusSyncState::current()?->backlog);
        for ($i = 0; $i < 5 && UnitStatusSyncState::current()?->backlog; $i++) {
            $this->travel(1)->minutes();
            $this->sync();
        }
        self::assertSame(450, UnitStatusCall::query()->count());
        self::assertFalse((bool) UnitStatusSyncState::current()?->backlog);
    }

    // 4. Immediate merge check

    public function test_a_call_that_learns_its_id_triggers_the_merge_check_at_once(): void
    {
        $this->fakeFeedRouter();
        $keeper = $this->sourceCall(1, [['unitId' => 'E3', 'status' => 'Dispatched']], ['externalId' => null, 'provisional' => true]);
        $copy = $this->sourceCall(2, [['unitId' => 'E4', 'status' => 'Dispatched']], ['externalId' => null, 'provisional' => true]);
        $this->deltaBody = $this->feedPage([$keeper, $copy]);
        $this->sync();
        $lookupsBefore = count($this->lookups());

        $this->travel(1)->minutes();
        $this->deltaBody = $this->feedPage([array_merge($keeper, ['externalId' => '2601777777', 'provisional' => false, 'updatedAt' => now()->toIso8601String()])]);
        $this->lookupMissing = [self::callId(2)];
        $this->sync();

        self::assertGreaterThan($lookupsBefore, count($this->lookups()));
        self::assertNotNull(UnitStatusCall::query()->find(self::callId(2))?->merged_at);
        self::assertSame(['E3' => 1], $this->ledgerCounts());
    }

    public function test_an_ordinary_run_does_not_look_up_before_the_five_minutes_are_up(): void
    {
        $this->fakeFeedRouter();
        $this->deltaBody = $this->feedPage([$this->sourceCall(1, [['unitId' => 'E3', 'status' => 'Dispatched']], ['externalId' => null, 'provisional' => true])]);
        $this->sync();
        $before = count($this->lookups());

        $this->travel(1)->minutes();
        $this->sync();

        self::assertSame($before, count($this->lookups()));
    }

    // 7. Board cache

    public function test_the_board_is_shared_for_ten_seconds_and_rebuilt_on_a_new_sync_or_a_new_bucket(): void
    {
        config(['unit_status.board_cache_seconds' => 10]);
        $this->markFeedFresh();
        $first = $this->board();

        $this->storeCall(1, ['E2' => ['status' => 'Enroute']]);
        $again = $this->board();
        self::assertSame($first['etag'], $again['etag']);
        self::assertSame($first['generatedAt'], $again['generatedAt']);

        $this->markFeedFresh('live', now()->addSecond());
        self::assertNotSame($first['etag'], $this->board()['etag']);

        $this->storeCall(2, ['E3' => ['status' => 'Enroute']]);
        $cached = $this->board();
        $this->travel(11)->seconds();
        $this->markFeedFresh();
        self::assertSame('on_call', $this->unit($this->board(), 'E3')['availability']['key']);
        self::assertNotSame($cached['etag'], $this->board()['etag']);
    }

    public function test_the_etag_in_the_response_comes_from_the_cached_payload(): void
    {
        config(['unit_status.board_cache_seconds' => 10]);
        $this->markFeedFresh();
        $this->actingAsCanonicalFixture();

        $one = $this->get('/unit-status/data')->assertOk();
        $this->get('/unit-status/data', ['If-None-Match' => $one->headers->get('ETag')])->assertStatus(304);
    }

    // 8. Chip semantics

    public function test_confirmed_is_null_when_there_is_no_call_and_a_boolean_when_there_is(): void
    {
        $this->markFeedFresh();
        DailyRosterSnapshotFactory::publish(self::TODAY, [
            'Station 1 / Ladder 1' => [['employee_id' => 'EMP-SECRET-1', 'name' => 'ALPHA, ANN']],
            'Station 1 / Rescue 1' => [['employee_id' => 'EMP-SECRET-2', 'name' => 'BRAVO, BOB', 'availability' => 'not_working']],
        ]);
        $this->storeCall(1, ['E1' => ['status' => 'On Scene']], ['received_at' => now()->subMinutes(5)]);

        $board = $this->board();

        self::assertNull($this->unit($board, 'L1')['availability']['confirmed']);
        self::assertNull($this->unit($board, 'R1')['availability']['confirmed']);
        self::assertNull($this->unit($board, 'E3')['availability']['confirmed']);
        self::assertTrue($this->unit($board, 'E1')['availability']['confirmed']);

        $this->markFeedFresh('live', now()->subMinutes(10));
        self::assertNull($this->unit($this->board(), 'L1')['availability']['confirmed']);
    }
}
