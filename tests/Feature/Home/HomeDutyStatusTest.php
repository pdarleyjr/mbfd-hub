<?php

declare(strict_types=1);

namespace Tests\Feature\Home;

use App\Models\DailyRoster\RosterImport;
use App\Models\User;
use App\Services\DailyRoster\HomeDutyLines;
use App\Services\DailyRoster\HomeDutyStatus;
use App\Services\DailyRoster\OnDutyOfficerDisplayResolver;
use App\Services\DailyRoster\RosterPipelineException;
use App\Services\Security\Command300Access;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Feature\Captain5\Concerns\BuildsCaptain5World;
use Tests\Support\DailyRosterSnapshotFactory;
use Tests\TestCase;

/**
 * The Home greeting block: time of day, the civil date, the shift on duty and the 300. Every person is synthetic.
 * 2026-10-20 is an A shift duty day (the rotation anchor is 2026-03-09), 2026-10-21 is B, 2026-10-19 is C.
 */
final class HomeDutyStatusTest extends TestCase
{
    use BuildsCaptain5World;
    use RefreshDatabase;

    private const DUTY_DATE = '2026-10-20';

    private const COMMAND_300_SECTION = 'Suppression / Division Chief 300';

    private const CAPTAIN_5_SECTION = 'Rescue Division / Captain 5';

    private User $viewer;

    private User $bidChief;

    private User $rosterChief;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config()->set('daily_roster.card_display_enabled', true);
        $this->viewer = $this->officer('Firefighter', 'Viewer Member');
        $this->bidChief = $this->officer('Division Chief', 'Bid Chief');
        $this->rosterChief = $this->officer('Division Chief', 'Roster Chief');
    }

    /** Pins the clock to an Eastern wall time and clears the per-request memo. */
    private function at(string $local): CarbonImmutable
    {
        $moment = CarbonImmutable::parse($local, 'America/New_York');
        $this->travelTo($moment);
        $this->forgetCaptain5Memo();

        return $moment;
    }

    private function lines(?User $user, string $local): HomeDutyLines
    {
        return app(HomeDutyStatus::class)->forMember($user, $this->at($local));
    }

    private function seatBidChief(): void
    {
        $this->seat($this->bidChief, Command300Access::COMMAND_UNIT_LABEL, 'A Shift');
    }

    /**
     * @param  list<array<string, mixed>>|null  $rows
     * @param  array<string, mixed>  $options
     */
    private function publish300(?array $rows = null, array $options = [], string $dutyDate = self::DUTY_DATE): void
    {
        $rows ??= [['employee_id' => $this->rosterChief->employee_id, 'name' => 'SOURCE, PRINTED', 'rank' => 'DC', 'code' => 'SP']];
        DailyRosterSnapshotFactory::publish($dutyDate, [self::COMMAND_300_SECTION => $rows], $options);
        $this->forgetCaptain5Memo();
    }

    // ------------------------------------------------------------------ greeting

    /** @return array<string, array{0: string, 1: string}> */
    public static function greetingWindows(): array
    {
        return [
            'midnight' => ['2026-10-20 00:00', 'Good morning'],
            'just before noon' => ['2026-10-20 11:59', 'Good morning'],
            'noon' => ['2026-10-20 12:00', 'Good afternoon'],
            'just before evening' => ['2026-10-20 17:59', 'Good afternoon'],
            'evening' => ['2026-10-20 18:00', 'Good evening'],
            'late' => ['2026-10-20 23:59', 'Good evening'],
        ];
    }

    #[DataProvider('greetingWindows')]
    public function test_the_greeting_follows_the_eastern_wall_clock(string $local, string $expected): void
    {
        self::assertSame($expected.', Viewer', $this->lines($this->viewer, $local)->greeting);
        self::assertSame($expected, $this->lines(null, $local)->greeting, 'No name, no comma.');
    }

    public function test_the_first_name_is_the_preferred_name_when_there_is_one(): void
    {
        // The personnel profile is the source: saving it carries the preferred name onto the account.
        $this->viewer->employeeProfile->forceFill(['display_name' => 'Preferred Person'])->save();

        self::assertSame('Good afternoon, Preferred', $this->lines($this->viewer->fresh(), '2026-10-20 13:00')->greeting);
    }

    // ------------------------------------------------------------------ dates, duty day and DST

    public function test_the_date_is_the_civil_date_and_the_shift_is_the_duty_day_just_around_08_00(): void
    {
        $this->publish300();

        $before = $this->lines($this->viewer, '2026-10-21 07:59');
        self::assertSame('Wednesday, October 21, 2026', $before->dateLabel);
        self::assertSame('2026-10-21', $before->dateIso);
        self::assertSame('A Shift (on duty since Oct 20, 8:00 AM)', $before->shift['text'], 'Still the Oct 20 duty day, and says so.');
        self::assertSame(HomeDutyLines::SHIFT_VERIFIED, $before->shift['state']);
        self::assertSame('300 is Division Chief Roster Chief', $before->command['text']);

        $after = $this->lines($this->viewer, '2026-10-21 08:00');
        self::assertSame('Wednesday, October 21, 2026', $after->dateLabel);
        self::assertSame('B Shift (scheduled)', $after->shift['text'], 'The new duty day has no roster yet: the rotation, qualified.');
        self::assertSame("300: Awaiting today's roster", $after->command['text'], "Yesterday's roster is never today's.");
    }

    public function test_the_civil_date_and_duty_day_agree_after_08_00_so_no_note_is_added(): void
    {
        self::assertSame('A Shift (scheduled)', $this->lines($this->viewer, '2026-10-20 08:00')->shift['text']);
        self::assertSame('A Shift (scheduled)', $this->lines($this->viewer, '2026-10-20 23:59')->shift['text']);
    }

    public function test_spring_forward_day_keeps_the_wall_clock_boundaries(): void
    {
        // 2026-03-08: clocks jump from 02:00 EST to 03:00 EDT. 2026-03-07 is B, 2026-03-08 is C.
        $night = $this->lines(null, '2026-03-08 03:00');
        self::assertSame('Good morning', $night->greeting);
        self::assertSame('Sunday, March 8, 2026', $night->dateLabel);

        $early = $this->lines($this->viewer, '2026-03-08 07:59');
        self::assertSame('B Shift (scheduled, on duty since Mar 7, 8:00 AM)', $early->shift['text']);

        self::assertSame('C Shift (scheduled)', $this->lines($this->viewer, '2026-03-08 08:00')->shift['text']);
    }

    public function test_fall_back_day_has_one_duty_day_boundary_and_the_repeated_hour_changes_nothing(): void
    {
        // 2026-11-01: clocks fall back from 02:00 EDT to 01:00 EST. 2026-10-31 is C, 2026-11-01 is A.
        foreach (['2026-11-01 05:30:00', '2026-11-01 06:30:00'] as $utc) { // both are 01:30 on the wall clock
            $lines = app(HomeDutyStatus::class)->forMember(null, CarbonImmutable::parse($utc, 'UTC'));
            self::assertSame('Good morning', $lines->greeting, $utc);
            self::assertSame('Sunday, November 1, 2026', $lines->dateLabel, $utc);
        }

        $this->travelTo(CarbonImmutable::parse('2026-11-01 06:30:00', 'UTC'));
        $this->forgetCaptain5Memo();
        $repeated = app(HomeDutyStatus::class)->forMember($this->viewer, CarbonImmutable::parse('2026-11-01 06:30:00', 'UTC'));
        self::assertSame('C Shift (scheduled, on duty since Oct 31, 8:00 AM)', $repeated->shift['text']);
        self::assertSame('2026-11-01T13:00:00+00:00', $repeated->nextChangeAt, 'The next boundary is 08:00 EST, 13:00 UTC.');

        $beforeDuty = app(HomeDutyStatus::class)->forMember($this->viewer, CarbonImmutable::parse('2026-11-01 12:59:00', 'UTC'));
        self::assertStringStartsWith('C Shift', $beforeDuty->shift['text']);
        $afterDuty = app(HomeDutyStatus::class)->forMember($this->viewer, CarbonImmutable::parse('2026-11-01 13:00:00', 'UTC'));
        self::assertSame('A Shift (scheduled)', $afterDuty->shift['text']);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function nextBoundaries(): array
    {
        return [
            'overnight to the duty day' => ['2026-10-20 03:00', '2026-10-20 12:00:00'],
            'duty day to noon' => ['2026-10-20 08:00', '2026-10-20 16:00:00'],
            'noon to evening' => ['2026-10-20 17:59', '2026-10-20 22:00:00'],
            'evening to midnight' => ['2026-10-20 18:00', '2026-10-21 04:00:00'],
        ];
    }

    #[DataProvider('nextBoundaries')]
    public function test_the_next_change_names_the_next_greeting_or_duty_day_boundary(string $local, string $expectedUtc): void
    {
        self::assertSame(
            CarbonImmutable::parse($expectedUtc, 'UTC')->toIso8601String(),
            $this->lines(null, $local)->nextChangeAt,
        );
    }

    // ------------------------------------------------------------------ the shift letter

    public function test_a_roster_that_agrees_with_the_rotation_makes_the_letter_a_fact(): void
    {
        $this->publish300();

        self::assertSame(['text' => 'A Shift', 'state' => HomeDutyLines::SHIFT_VERIFIED], $this->lines($this->viewer, '2026-10-20 10:30')->shift);
    }

    public function test_without_an_accepted_roster_the_rotation_is_shown_as_scheduled(): void
    {
        self::assertSame(['text' => 'A Shift (scheduled)', 'state' => HomeDutyLines::SHIFT_SCHEDULED], $this->lines($this->viewer, '2026-10-20 09:00')->shift);
    }

    public function test_a_roster_that_disagrees_with_the_rotation_shows_no_letter_and_no_name(): void
    {
        $this->publish300(options: ['shift' => 'B']);

        $lines = $this->lines($this->viewer, '2026-10-20 10:30');
        self::assertNull($lines->shift, 'Neither the roster nor the rotation is invented over the other.');
        self::assertSame(['text' => '300: Assignment needs review', 'state' => HomeDutyLines::COMMAND_REVIEW], $lines->command);
    }

    public function test_with_the_rotation_off_no_letter_is_shown_and_no_name_is_guessed(): void
    {
        config()->set('captain5.shift_rotation.enabled', false);
        $this->publish300();

        $lines = $this->lines($this->viewer, '2026-10-20 10:30');
        self::assertNull($lines->shift);
        self::assertSame('300: Assignment needs review', $lines->command['text']);
    }

    // ------------------------------------------------------------------ the 300

    public function test_the_roster_names_the_300_with_the_hub_rank_and_name_and_an_as_of_time(): void
    {
        $this->seatBidChief();
        $this->publish300();

        $lines = $this->lines($this->viewer, '2026-10-20 10:30');

        self::assertSame(['text' => '300 is Division Chief Roster Chief', 'state' => HomeDutyLines::COMMAND_VERIFIED], $lines->command);
        self::assertSame('As of 9:14 AM', $lines->asOf);
        self::assertSame('/daily-roster', $lines->rosterUrl);
        self::assertStringNotContainsString((string) $this->rosterChief->employee_id, (string) json_encode($lines->toArray()));
        self::assertStringNotContainsString('SOURCE, PRINTED', (string) json_encode($lines->toArray()), 'The Hub name wins over the printed one.');
    }

    public function test_an_unmatched_roster_name_is_shown_by_the_source_name_alone(): void
    {
        $this->publish300([['employee_id' => '99-NOT-IN-HUB', 'name' => 'Printed Only', 'rank' => 'DC', 'code' => 'SP']]);

        $lines = $this->lines($this->viewer, '2026-10-20 10:30');

        self::assertSame('300 is Printed Only', $lines->command['text']);
        self::assertStringNotContainsString('99-NOT-IN-HUB', (string) json_encode($lines->toArray()));
    }

    public function test_before_the_roster_arrives_the_bid_seat_is_scheduled_and_not_yet_verified(): void
    {
        $this->seatBidChief();

        $lines = $this->lines($this->viewer, '2026-10-20 09:00');

        self::assertSame(['text' => 'Scheduled 300: Bid Chief · not yet verified', 'state' => HomeDutyLines::COMMAND_SCHEDULED], $lines->command);
        self::assertNull($lines->asOf);
    }

    public function test_before_the_roster_arrives_with_nobody_in_the_bid_seat_the_line_says_it_is_awaited(): void
    {
        self::assertSame(['text' => "300: Awaiting today's roster", 'state' => HomeDutyLines::COMMAND_AWAITING], $this->lines($this->viewer, '2026-10-20 09:00')->command);
    }

    /** Yesterday's roster exists, so the Hub knows exports normally arrive; today's has not. */
    private function publishYesterdaysRoster(): void
    {
        $this->publish300(options: ['shift' => 'C'], dutyDate: '2026-10-19');
    }

    public function test_a_late_roster_is_called_delayed_and_the_bid_seat_stays_scheduled(): void
    {
        $this->seatBidChief();
        $this->publishYesterdaysRoster();

        self::assertSame(
            ['text' => "Today's roster is delayed · Scheduled 300: Bid Chief", 'state' => HomeDutyLines::COMMAND_DELAYED],
            $this->lines($this->viewer, '2026-10-20 10:30')->command,
        );
    }

    public function test_a_late_roster_with_nobody_in_the_bid_seat_says_only_that_it_is_delayed(): void
    {
        $this->publishYesterdaysRoster();

        self::assertSame("300: Today's roster is delayed", $this->lines($this->viewer, '2026-10-20 10:30')->command['text']);
    }

    public function test_a_held_export_before_the_cutoff_is_called_on_hold(): void
    {
        $this->seatBidChief();
        RosterImport::query()->create([
            'correlation_id' => (string) Str::uuid(), 'state' => RosterImport::STATE_NEEDS_REVIEW, 'state_changed_at' => now(),
            'message_id_sha256' => hash('sha256', 'held'), 'sender' => 'sender@example.test',
            'received_at' => CarbonImmutable::parse('2026-10-20 08:30', 'America/New_York'), 'duty_date' => self::DUTY_DATE,
        ]);

        self::assertSame(
            ['text' => "Today's roster is on hold · Scheduled 300: Bid Chief", 'state' => HomeDutyLines::COMMAND_HELD],
            $this->lines($this->viewer, '2026-10-20 09:00')->command,
        );
    }

    public function test_two_people_working_the_seat_needs_review_and_names_nobody(): void
    {
        $this->seatBidChief();
        $this->publish300([
            ['employee_id' => $this->rosterChief->employee_id, 'name' => 'ONE, FIRST', 'seat' => 0],
            ['employee_id' => $this->bidChief->employee_id, 'name' => 'TWO, SECOND', 'seat' => 1],
        ]);

        $lines = $this->lines($this->viewer, '2026-10-20 10:30');

        self::assertSame('300: Assignment needs review', $lines->command['text']);
        self::assertStringNotContainsString('Roster Chief', (string) json_encode($lines->toArray()));
        self::assertStringNotContainsString('Bid Chief', (string) json_encode($lines->toArray()));
    }

    public function test_a_seat_with_nobody_working_and_a_roster_without_the_section_both_need_review(): void
    {
        $this->seatBidChief();
        $this->publish300([['kind' => 'vacancy']]);
        self::assertSame('300: Assignment needs review', $this->lines($this->viewer, '2026-10-20 10:30')->command['text'], 'no_working_row');

        DailyRosterSnapshotFactory::publish(self::DUTY_DATE, [self::CAPTAIN_5_SECTION => [['employee_id' => $this->rosterChief->employee_id, 'name' => 'X, Y']]]);
        self::assertSame('300: Assignment needs review', $this->lines($this->viewer, '2026-10-20 10:30')->command['text'], 'section_missing');
    }

    public function test_quiet_exports_add_a_plain_qualifier_to_the_as_of_time(): void
    {
        config()->set('daily_roster.cadence.expected_interval_minutes', 60);
        $this->publish300();

        $lines = $this->lines($this->viewer, '2026-10-20 15:00');

        self::assertSame('300 is Division Chief Roster Chief', $lines->command['text'], "Today's roster is still today's.");
        self::assertSame('As of 9:14 AM · roster updates delayed', $lines->asOf);
    }

    public function test_the_kill_switch_shows_no_300_line_and_no_roster_derived_shift_and_reads_no_roster(): void
    {
        config()->set('daily_roster.card_display_enabled', false);
        $this->seatBidChief();
        $this->publish300();
        $this->at('2026-10-20 10:30');
        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $lines = app(HomeDutyStatus::class)->forMember($this->viewer, CarbonImmutable::now());

        self::assertNull($lines->command);
        self::assertNull($lines->asOf);
        self::assertSame(['text' => 'A Shift (scheduled)', 'state' => HomeDutyLines::SHIFT_SCHEDULED], $lines->shift, 'The rotation alone, never verified.');
        foreach ($queries as $sql) {
            self::assertDoesNotMatchRegularExpression('/roster_(snapshots|assignments|sections)/', $sql);
        }
    }

    // ------------------------------------------------------------------ who may see duty lines

    public function test_a_guest_gets_the_greeting_and_the_date_and_no_duty_lines(): void
    {
        $this->seatBidChief();
        $this->publish300();

        $lines = $this->lines(null, '2026-10-20 10:30');

        self::assertSame('Good morning', $lines->greeting);
        self::assertSame('Tuesday, October 20, 2026', $lines->dateLabel);
        self::assertNull($lines->shift);
        self::assertNull($lines->command);
        self::assertNull($lines->asOf);
        self::assertNull($lines->rosterUrl);
    }

    public function test_an_account_the_roster_does_not_admit_gets_no_duty_lines(): void
    {
        $this->publish300();
        $bare = User::factory()->create(['account_status' => 'active', 'must_change_password' => false, 'name' => 'Bare Login']);

        $lines = $this->lines($bare, '2026-10-20 10:30');

        self::assertSame('Good morning, Bare', $lines->greeting);
        self::assertNull($lines->shift);
        self::assertNull($lines->command);
        self::assertNull($lines->rosterUrl);
    }

    public function test_a_departed_member_gets_no_duty_lines(): void
    {
        $this->publish300();
        $this->viewer->employeeProfile->forceFill(['roster_status' => 'departed'])->save();

        self::assertNull($this->lines($this->viewer->fresh(), '2026-10-20 10:30')->command);
    }

    public function test_the_roster_link_follows_the_roster_entry_rule_not_the_data(): void
    {
        // Shadow rollout with nothing published: the Daily Roster entry on Home is hidden, so the link is too.
        config()->set('daily_roster.mode', 'shadow');

        $lines = $this->lines($this->viewer, '2026-10-20 09:00');

        self::assertNull($lines->rosterUrl);
        self::assertNotNull($lines->shift, 'The member is still admitted to duty lines.');
    }

    // ------------------------------------------------------------------ failure

    public function test_a_failing_resolver_degrades_to_the_greeting_and_reports_a_code_only(): void
    {
        $this->publish300();
        Exceptions::fake();
        $secret = (string) $this->rosterChief->employee_id;
        $this->app->bind(OnDutyOfficerDisplayResolver::class, static fn () => throw new RuntimeException('SQL failed for employee '.$secret));

        $lines = $this->lines($this->viewer, '2026-10-20 10:30');

        self::assertSame('Good morning, Viewer', $lines->greeting);
        self::assertSame('Tuesday, October 20, 2026', $lines->dateLabel);
        self::assertNull($lines->shift);
        self::assertNull($lines->command);
        self::assertNull($lines->rosterUrl);
        Exceptions::assertReported(static fn (RosterPipelineException $e): bool => $e->errorCode === 'home_duty_failed_RuntimeException' && ! str_contains($e->getMessage(), $secret));
    }

    // ------------------------------------------------------------------ the page and the refresh endpoint

    public function test_the_page_renders_the_block_for_an_admitted_member_without_any_employee_id(): void
    {
        $this->seatBidChief();
        $this->publish300();
        $this->at('2026-10-20 14:00');
        $this->actingAsCanonicalUser($this->viewer);

        $html = $this->get('/')->assertOk()->getContent();
        $xpath = $this->xpath($html);

        self::assertSame('Good afternoon, Viewer', trim($xpath->query('//h1[@id="home-greeting"]')->item(0)->textContent));
        self::assertStringContainsString('Today is Tuesday, October 20, 2026', preg_replace('/\s+/', ' ', $xpath->query('//*[@data-home-date]')->item(0)->textContent));
        self::assertSame('A Shift', trim($xpath->query('//*[@data-home-shift]')->item(0)->textContent));
        self::assertSame('300 is Division Chief Roster Chief', trim($xpath->query('//*[@data-home-command]')->item(0)->textContent));
        self::assertSame('As of 9:14 AM', trim($xpath->query('//*[@data-home-as-of]')->item(0)->textContent));
        self::assertSame('/daily-roster', $xpath->query('//a[@data-home-roster-link]')->item(0)->getAttribute('href'));
        self::assertSame('/home/duty', $xpath->query('//*[@data-home-duty]')->item(0)->getAttribute('data-url'));
        self::assertStringNotContainsString((string) $this->rosterChief->employee_id, $html);
        self::assertStringNotContainsString((string) $this->bidChief->employee_id, $html);
        self::assertSame(1, $xpath->query('//section[contains(@class,"home-today")]//a[contains(@class,"hub-btn--primary")]')->length, 'The actions are unchanged.');
        self::assertSame(1, $xpath->query('//*[@data-department-updates or @data-home-section="department-updates"]')->length, 'Department Updates is unchanged.');
        self::assertSame(1, $xpath->query('//*[@data-home-section="quick-access"]')->length, 'Quick Access is unchanged.');
    }

    public function test_the_page_renders_no_duty_text_for_an_account_the_roster_does_not_admit(): void
    {
        $this->seatBidChief();
        $this->publish300();
        $this->at('2026-10-20 14:00');
        $bare = User::factory()->create(['account_status' => 'active', 'must_change_password' => false, 'name' => 'Bare Login']);
        $this->actingAsCanonicalUser($bare);

        $html = $this->get('/')->assertOk()->getContent();
        $xpath = $this->xpath($html);

        self::assertSame('Good afternoon, Bare', trim($xpath->query('//h1[@id="home-greeting"]')->item(0)->textContent));
        self::assertTrue($xpath->query('//*[@data-home-command]')->item(0)->hasAttribute('hidden'));
        self::assertSame('', trim($xpath->query('//*[@data-home-command]')->item(0)->textContent));
        self::assertStringNotContainsString('Roster Chief', $html);
        self::assertStringNotContainsString('Bid Chief', $html);
        self::assertStringNotContainsString('Daily Roster', $html, 'No roster link or text for an account the roster does not admit.');
        self::assertStringNotContainsString('Shift', $xpath->query('//*[@data-home-date]')->item(0)->textContent);
    }

    public function test_a_guest_gets_nothing_from_the_page_or_the_endpoint(): void
    {
        $this->publish300();

        $this->get('/')->assertRedirect();
        $this->getJson('/home/duty')->assertUnauthorized();
    }

    public function test_the_page_and_the_endpoint_are_never_cacheable_by_a_shared_cache(): void
    {
        $this->publish300();
        $this->at('2026-10-20 14:00');
        $this->actingAsCanonicalUser($this->viewer);

        $page = (string) $this->get('/')->assertOk()->headers->get('Cache-Control');
        self::assertStringContainsString('private', $page);
        self::assertStringNotContainsString('public', $page);
        self::assertStringNotContainsString('s-maxage', $page);

        $endpoint = (string) $this->getJson('/home/duty')->assertOk()->headers->get('Cache-Control');
        self::assertStringContainsString('no-store', $endpoint);
        self::assertStringContainsString('private', $endpoint);
    }

    public function test_the_endpoint_returns_the_same_text_as_the_page_and_a_new_roster_changes_it(): void
    {
        $this->seatBidChief();
        $this->at('2026-10-20 09:00');
        $this->actingAsCanonicalUser($this->viewer);

        $first = $this->getJson('/home/duty')->assertOk();
        self::assertSame('Scheduled 300: Bid Chief · not yet verified', $first->json('command.text'));
        self::assertSame('A Shift (scheduled)', $first->json('shift.text'));
        $etag = (string) $first->headers->get('ETag');

        // Nothing changed: a conditional request is answered without a body.
        $this->forgetCaptain5Memo();
        $this->getJson('/home/duty', ['If-None-Match' => $etag])->assertStatus(304);

        // A roster publishes while the page is open: the next check returns the new data.
        $this->publish300();
        $second = $this->getJson('/home/duty', ['If-None-Match' => $etag])->assertOk();
        self::assertSame('300 is Division Chief Roster Chief', $second->json('command.text'));
        self::assertSame('A Shift', $second->json('shift.text'));
        self::assertSame('As of 9:14 AM', $second->json('as_of'));
        self::assertNotSame($etag, (string) $second->headers->get('ETag'));
        self::assertNotSame($first->json('version'), $second->json('version'));
        self::assertStringNotContainsString((string) $this->rosterChief->employee_id, (string) $second->getContent());

        $this->forgetCaptain5Memo();
        $this->getJson('/home/duty', ['If-None-Match' => (string) $second->headers->get('ETag')])->assertStatus(304);
    }

    public function test_the_endpoint_follows_a_duty_day_change_and_a_greeting_change(): void
    {
        $this->publish300();
        $this->at('2026-10-21 07:59');
        $this->actingAsCanonicalUser($this->viewer);
        $before = $this->getJson('/home/duty')->assertOk();

        $this->at('2026-10-21 08:00');
        $after = $this->getJson('/home/duty')->assertOk();

        self::assertSame('A Shift (on duty since Oct 20, 8:00 AM)', $before->json('shift.text'));
        self::assertSame('B Shift (scheduled)', $after->json('shift.text'));
        self::assertSame("300: Awaiting today's roster", $after->json('command.text'));

        // Four hours on, the first session would have idled out; sign in again at the new time.
        $this->at('2026-10-21 12:00');
        $this->actingAsCanonicalUser($this->viewer);
        self::assertSame('Good afternoon, Viewer', $this->getJson('/home/duty')->assertOk()->json('greeting'));
    }

    public function test_the_endpoint_gives_a_member_without_roster_access_no_duty_data(): void
    {
        $this->publish300();
        $this->at('2026-10-20 14:00');
        $this->actingAsCanonicalUser(User::factory()->create(['account_status' => 'active', 'must_change_password' => false]));

        $json = $this->getJson('/home/duty')->assertOk()->json();

        self::assertNull($json['shift']);
        self::assertNull($json['command']);
        self::assertNull($json['roster_url']);
        self::assertStringNotContainsString('Roster Chief', (string) json_encode($json));
    }

    public function test_the_endpoint_is_throttled(): void
    {
        $this->at('2026-10-20 14:00');
        $this->actingAsCanonicalUser($this->viewer);

        for ($i = 0; $i < 30; $i++) {
            $this->getJson('/home/duty')->assertOk();
        }
        $this->getJson('/home/duty')->assertStatus(429);
    }

    public function test_a_page_view_stays_within_a_small_query_budget(): void
    {
        $this->seatBidChief();
        $this->publish300();
        $this->at('2026-10-20 14:00');
        $queries = 0;
        DB::listen(static function () use (&$queries): void {
            $queries++;
        });

        app(HomeDutyStatus::class)->forMember($this->viewer, CarbonImmutable::now());

        self::assertLessThanOrEqual(40, $queries);
    }

    private function xpath(string $html): \DOMXPath
    {
        $document = new \DOMDocument;
        libxml_use_internal_errors(true);
        $document->loadHTML($html);
        libxml_clear_errors();

        return new \DOMXPath($document);
    }
}
