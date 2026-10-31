<?php

declare(strict_types=1);

namespace Tests\Feature\DailyRoster;

use App\Models\DailyRoster\RosterActiveVersion;
use App\Models\DailyRoster\RosterImport;
use App\Models\DailyRoster\RosterSnapshot;
use App\Models\User;
use App\Services\DailyRoster\RosterFreshnessResolver;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\DailyRosterSnapshotFactory;
use Tests\TestCase;

/** What an open page receives when an export arrives: nothing but what actually changed. */
final class RosterLiveUpdatesTest extends TestCase
{
    use RefreshDatabase;

    private const VERSION = '/api/daily-roster/version';

    private const CHANGES = '/daily-roster/changes';

    private const NOW = '2026-10-30 10:30:00';

    private const TODAY = '2026-10-30';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(CarbonImmutable::parse(self::NOW, 'America/New_York'));
    }

    public function test_an_export_with_no_changes_keeps_the_version_so_no_page_downloads_the_roster(): void
    {
        $this->publishRoster($this->crew());
        $this->actingAsCanonicalFixture();
        $before = $this->getJson(self::VERSION)->assertOk();

        $this->confirmExport('2026-10-30 10:35:00');

        $after = $this->get(self::VERSION, ['If-None-Match' => '"'.$before->json('etag').'"', 'Accept' => 'application/json'])->assertOk();
        self::assertSame($before->json('version'), $after->json('version'));
        self::assertNotSame($before->json('etag'), $after->json('etag'));
    }

    public function test_an_export_with_no_changes_still_moves_the_times_the_member_reads(): void
    {
        $this->publishRoster($this->crew());
        $this->actingAsCanonicalFixture();

        $this->confirmExport('2026-10-30 10:35:00');

        $facts = $this->getJson(self::VERSION)->assertOk()->json('facts');
        self::assertSame('Oct 30, 10:35 AM EDT', $facts['updated']);
        self::assertSame('Oct 30, 10:35 AM EDT', $facts['generated']);
    }

    public function test_the_version_endpoint_answers_304_until_anything_at_all_moves(): void
    {
        $this->publishRoster($this->crew());
        $this->actingAsCanonicalFixture();
        $etag = (string) $this->getJson(self::VERSION)->json('etag');

        $this->get(self::VERSION, ['If-None-Match' => '"'.$etag.'"'])->assertStatus(304);

        $this->confirmExport('2026-10-30 10:35:00');
        $this->get(self::VERSION, ['If-None-Match' => '"'.$etag.'"'])->assertOk();
    }

    public function test_the_page_says_when_the_roster_itself_last_changed_once_that_differs_from_the_last_update(): void
    {
        $this->publishRoster($this->crew());
        $this->actingAsCanonicalFixture();

        $this->get('/daily-roster')->assertOk()->assertSee('data-roster-fact-row="changed" hidden', false);

        $this->confirmExport('2026-10-30 12:15:00');

        // Past the idle limit, as any member coming back to the page later would be.
        $this->actingAsCanonicalUser(auth()->user());
        $this->get('/daily-roster')->assertOk()
            ->assertSeeInOrder(['Last updated', 'Oct 30, 12:15 PM EDT'])
            ->assertSeeInOrder(['Roster last changed', 'Oct 30, 10:30 AM EDT'])
            ->assertDontSee('data-roster-fact-row="changed" hidden', false);
    }

    public function test_one_changed_seat_sends_that_seat_and_nothing_else(): void
    {
        $from = $this->publishRoster($this->crew());
        $this->publishRoster($this->crew(ladderName: 'CHANGED, PERSON'));
        $this->actingAsCanonicalFixture();

        $changes = $this->getJson($this->changesUrl($from))->assertOk();

        self::assertSame('changes', $changes->json('mode'));
        self::assertSame([], $changes->json('sections'));
        self::assertCount(1, $changes->json('seats'));
        self::assertSame('Station 1 / Ladder 1', $changes->json('seats.0.section'));
        self::assertSame(1, $changes->json('seats.0.seat'));
        self::assertStringContainsString('CHANGED, PERSON', $changes->json('seats.0.html'));
        self::assertStringContainsString('data-seat-index="1"', $changes->json('seats.0.html'));

        $payload = (string) $changes->getContent();
        self::assertStringNotContainsString('UNCHANGED, ONE', $payload);
        self::assertStringNotContainsString('UNCHANGED, TWO', $payload);
        self::assertStringNotContainsString('UNCHANGED, THREE', $payload);
        self::assertSame(1, $changes->json('changed'));
        self::assertSame(app(RosterFreshnessResolver::class)->status()->version, $changes->json('version'));
        self::assertSame(RosterActiveVersion::query()->value('roster_snapshot_id'), $changes->json('snapshot'));
    }

    public function test_a_new_export_no_member_could_tell_apart_changes_no_spots(): void
    {
        $from = $this->publishRoster($this->crew());
        $this->publishRoster($this->crew());
        $this->actingAsCanonicalFixture();

        $changes = $this->getJson($this->changesUrl($from))->assertOk();

        self::assertSame('changes', $changes->json('mode'));
        self::assertSame(0, $changes->json('changed'));
        self::assertSame([], $changes->json('seats'));
    }

    public function test_a_seat_gaining_a_row_also_sends_the_new_entry_count_for_its_section(): void
    {
        $from = $this->publishRoster($this->crew());
        $sections = $this->crew();
        $sections['Station 1 / Ladder 1'][] = ['employee_id' => '50009', 'name' => 'ADDED, COVER', 'code' => '+OT', 'seat' => 1, 'continuation' => true];
        $this->publishRoster($sections);
        $this->actingAsCanonicalFixture();

        $changes = $this->getJson($this->changesUrl($from))->assertOk();

        self::assertCount(1, $changes->json('seats'));
        self::assertStringContainsString('ADDED, COVER', $changes->json('seats.0.html'));
        self::assertSame([['section' => 'Station 1 / Ladder 1', 'label' => '3 entries']], $changes->json('counts'));
    }

    public function test_a_section_that_gained_a_seat_is_replaced_whole_and_only_that_section(): void
    {
        $from = $this->publishRoster($this->crew());
        $sections = $this->crew();
        $sections['Station 1 / Ladder 1'][] = ['employee_id' => '50009', 'name' => 'NEWSEAT, PERSON', 'seat' => 9];
        $this->publishRoster($sections);
        $this->actingAsCanonicalFixture();

        $changes = $this->getJson($this->changesUrl($from))->assertOk();

        self::assertSame('changes', $changes->json('mode'));
        self::assertSame([], $changes->json('seats'));
        self::assertSame('Station 1 / Ladder 1', $changes->json('sections.0.section'));
        self::assertStringContainsString('NEWSEAT, PERSON', $changes->json('sections.0.html'));
        self::assertStringNotContainsString('UNCHANGED, ONE', (string) $changes->getContent());
    }

    public function test_the_summary_legend_and_filters_travel_with_the_change(): void
    {
        $from = $this->publishRoster($this->crew());
        $sections = $this->crew();
        $sections['Station 1 / Ladder 1'][0] = ['employee_id' => '50002', 'name' => 'ONLEAVE, PERSON', 'code' => 'VACA', 'availability' => 'not_working', 'seat' => 0];
        $this->publishRoster($sections);
        $this->actingAsCanonicalFixture();

        $changes = $this->getJson($this->changesUrl($from))->assertOk();

        self::assertStringContainsString('Roster report, version 2', $changes->json('summary'));
        self::assertNotEmpty($changes->json('filters'));
        self::assertNotEmpty($changes->json('legend'));
        self::assertSame('Oct 30, 10:30 AM EDT', $changes->json('facts.updated'));
    }

    /** @return iterable<string, array{0: string}> */
    public static function fullRefreshCases(): iterable
    {
        yield 'a snapshot that no longer exists' => ['missing'];
        yield 'a page on an earlier duty date' => ['other_date'];
        yield 'a page already on the current snapshot' => ['current'];
        yield 'a section added' => ['section_added'];
        yield 'sections reordered' => ['reordered'];
        yield 'most of the roster changed' => ['most_changed'];
        yield 'a page not showing a current roster' => ['client_state'];
        yield 'a roster under review' => ['server_state'];
    }

    /** @dataProvider fullRefreshCases */
    public function test_anything_it_cannot_prove_is_a_small_change_falls_back_to_a_full_refresh(string $case): void
    {
        $from = $this->publishRoster($this->crew());
        $this->actingAsCanonicalFixture();
        $state = 'current';
        $fromId = (int) $from->getKey();

        switch ($case) {
            case 'missing':
                $this->publishRoster($this->crew(ladderName: 'CHANGED, PERSON'));
                $fromId = 999999;
                break;
            case 'other_date':
                $fromId = (int) DailyRosterSnapshotFactory::publish('2026-10-29', $this->crew(), ['generated_at' => CarbonImmutable::parse('2026-10-29 13:00:00', 'UTC')])->getKey();
                $this->publishRoster($this->crew(ladderName: 'CHANGED, PERSON'));
                break;
            case 'current':
                break;
            case 'section_added':
                $this->publishRoster([...$this->crew(), 'Station 9 / Engine 9' => [['employee_id' => '50020', 'name' => 'ADDED, SECTION']]]);
                break;
            case 'reordered':
                $this->publishRoster(array_reverse($this->crew(), true));
                break;
            case 'most_changed':
                $this->publishRoster([
                    'Station 1 / Engine 1' => [['employee_id' => '60001', 'name' => 'NEW, A']],
                    'Station 1 / Ladder 1' => [['employee_id' => '60002', 'name' => 'NEW, B'], ['employee_id' => '60003', 'name' => 'NEW, C']],
                ]);
                break;
            case 'client_state':
                $this->publishRoster($this->crew(ladderName: 'CHANGED, PERSON'));
                $state = 'needs_review';
                break;
            case 'server_state':
                $this->publishRoster($this->crew(ladderName: 'CHANGED, PERSON'));
                $this->holdAnExport();
                break;
        }

        $response = $this->getJson(self::CHANGES.'?from='.$fromId.'&state='.$state)->assertOk();

        self::assertSame(['mode' => 'full'], $response->json());
    }

    public function test_changes_are_refused_like_the_rest_of_the_roster_and_never_stored(): void
    {
        $from = $this->publishRoster($this->crew());

        $this->getJson($this->changesUrl($from))->assertUnauthorized();

        $this->actingAsCanonicalUser(User::factory()->create(['account_status' => \App\Enums\AccountStatus::Active]));
        $this->getJson($this->changesUrl($from))->assertForbidden();

        $this->actingAsCanonicalFixture();
        $response = $this->getJson($this->changesUrl($from))->assertOk();
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_a_bad_from_value_is_a_full_refresh_not_an_error(): void
    {
        $this->publishRoster($this->crew());
        $this->actingAsCanonicalFixture();

        foreach (['', 'abc', '-1', '1;DROP'] as $from) {
            self::assertSame(['mode' => 'full'], $this->getJson(self::CHANGES.'?state=current&from='.urlencode($from))->assertOk()->json());
        }
    }

    public function test_a_change_is_built_with_a_bounded_number_of_queries(): void
    {
        $from = $this->publishRoster($this->crew());
        $this->publishRoster($this->crew(ladderName: 'CHANGED, PERSON'));
        $this->actingAsCanonicalFixture();

        \Illuminate\Support\Facades\DB::flushQueryLog();
        \Illuminate\Support\Facades\DB::enableQueryLog();
        $this->getJson($this->changesUrl($from))->assertOk();
        $queries = count(\Illuminate\Support\Facades\DB::getQueryLog());
        \Illuminate\Support\Facades\DB::disableQueryLog();

        self::assertLessThan(40, $queries, 'Building a change set should not scale with the size of the roster.');
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function crew(string $ladderName = 'UNCHANGED, THREE'): array
    {
        return [
            'Station 1 / Engine 1' => [['employee_id' => '50001', 'name' => 'UNCHANGED, ONE', 'seat' => 0]],
            'Station 1 / Ladder 1' => [
                ['employee_id' => '50002', 'name' => 'UNCHANGED, TWO', 'seat' => 0],
                ['employee_id' => '50003', 'name' => $ladderName, 'seat' => 1],
            ],
            'Station 2 / Rescue 2' => [['employee_id' => '50004', 'name' => 'UNCHANGED, FOUR', 'seat' => 0]],
            'Station 3 / Engine 3' => [['employee_id' => '50005', 'name' => 'UNCHANGED, FIVE', 'seat' => 0]],
        ];
    }

    /** @param array<string, list<array<string, mixed>>> $sections */
    private function publishRoster(array $sections): RosterSnapshot
    {
        return DailyRosterSnapshotFactory::publish(self::TODAY, $sections, ['generated_at' => CarbonImmutable::now()]);
    }

    private function changesUrl(RosterSnapshot $from): string
    {
        return self::CHANGES.'?from='.$from->getKey().'&state=current';
    }

    /** What the publisher does with an export that matches what is showing. */
    private function confirmExport(string $localTime): void
    {
        $this->travelTo(CarbonImmutable::parse($localTime, 'America/New_York'));
        $moment = CarbonImmutable::now('UTC');
        RosterActiveVersion::query()->whereDate('duty_date', self::TODAY)->firstOrFail()->forceFill([
            'effective_generated_at' => $moment,
            'last_confirmed_at' => $moment,
        ])->save();
    }

    private function holdAnExport(): void
    {
        RosterImport::query()->create([
            'correlation_id' => (string) \Illuminate\Support\Str::uuid(),
            'state' => RosterImport::STATE_NEEDS_REVIEW,
            'state_changed_at' => CarbonImmutable::now('UTC'),
            'message_id_sha256' => hash('sha256', 'held'),
            'sender' => 'donotreply@miamibeachfl.gov',
            'received_at' => CarbonImmutable::now('UTC')->addMinute(),
            'duty_date' => self::TODAY,
            'source_generated_at' => CarbonImmutable::now('UTC')->addMinute(),
            'error_code' => 'layout_changed',
        ]);
    }
}
