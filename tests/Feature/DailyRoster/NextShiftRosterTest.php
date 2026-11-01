<?php

declare(strict_types=1);

namespace Tests\Feature\DailyRoster;

use App\Models\Captain5\Captain5ActingRequest;
use App\Models\User;
use App\Services\DailyRoster\OnDutyCaptain5Recipients;
use App\Services\DailyRoster\OnDutyOfficerDisplayResolver;
use App\Services\DailyRoster\RosterFreshnessResolver;
use App\Services\Ems\Captain5Recipients;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Captain5\Concerns\BuildsCaptain5World;
use Tests\Support\DailyRosterSnapshotFactory;
use Tests\TestCase;

/**
 * TeleStaff may post the next shift's roster hours before it starts. The page shows it straight away so
 * members can read it on their way in. The cards and alerts stay with whoever is scheduled to be working
 * until 08:00, and within a shift they change hands at the time the roster says.
 */
final class NextShiftRosterTest extends TestCase
{
    use BuildsCaptain5World;
    use RefreshDatabase;

    private const TODAY = '2026-10-20'; // A shift

    private const TOMORROW = '2026-10-21'; // B shift

    private User $days;

    private User $nights;

    private User $tomorrow;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->withoutVite();
        $this->buildCaptain5World('2026-10-20 22:30:00');
        config()->set('daily_roster.card_display_enabled', true);
        $this->days = $this->officer('Captain', 'Day Captain');
        $this->nights = $this->officer('Captain', 'Night Captain');
        $this->tomorrow = $this->officer('Captain', 'Tomorrow Captain');

        DailyRosterSnapshotFactory::publish(self::TODAY, ['Rescue Division / Captain 5' => [
            ['employee_id' => $this->days->employee_id, 'name' => 'CAPTAIN, DAY', 'rank' => 'Capt', 'code' => '+OT', 'category' => 'Overtime', 'from' => '08:00', 'through' => '20:00', 'hours' => 12.0],
            ['employee_id' => $this->nights->employee_id, 'name' => 'CAPTAIN, NIGHT', 'rank' => 'Capt', 'code' => '+OT', 'category' => 'Overtime', 'from' => '20:00', 'through' => '08:00', 'hours' => 12.0],
        ]], ['generated_at' => CarbonImmutable::parse('2026-10-20 08:05', 'America/New_York')]);
    }

    private function postTomorrowsRoster(): void
    {
        DailyRosterSnapshotFactory::publish(self::TOMORROW, ['Rescue Division / Captain 5' => [
            ['employee_id' => $this->tomorrow->employee_id, 'name' => 'CAPTAIN, TOMORROW', 'rank' => 'Capt', 'code' => 'SP', 'category' => 'Scheduled'],
        ]], ['generated_at' => CarbonImmutable::parse('2026-10-20 22:20', 'America/New_York')]);
    }

    private function shownAt(string $local): ?string
    {
        return app(OnDutyOfficerDisplayResolver::class)
            ->resolve(Captain5ActingRequest::ROLE_CAPTAIN_5, CarbonImmutable::parse($local, 'America/New_York'))->name;
    }

    /** @return list<string> */
    private function alertedAt(string $local): array
    {
        return app(OnDutyCaptain5Recipients::class)->users(CarbonImmutable::parse($local, 'America/New_York'))
            ->map(static fn (User $user): string => (string) $user->name)->values()->all();
    }

    public function test_the_card_and_alerts_change_hands_inside_a_shift_at_the_time_the_roster_says(): void
    {
        self::assertSame('Day Captain', $this->shownAt('2026-10-20 19:59'));
        self::assertSame('Night Captain', $this->shownAt('2026-10-20 20:00'));
        self::assertSame(['Day Captain'], $this->alertedAt('2026-10-20 19:59'));
        self::assertSame(['Night Captain'], $this->alertedAt('2026-10-20 20:00'));
    }

    public function test_a_roster_posted_early_changes_no_card_or_alert_until_0800(): void
    {
        $this->postTomorrowsRoster();

        foreach (['2026-10-20 22:30', '2026-10-21 00:30', '2026-10-21 07:59'] as $beforeHandover) {
            self::assertSame('Night Captain', $this->shownAt($beforeHandover), $beforeHandover);
            self::assertSame(['Night Captain'], $this->alertedAt($beforeHandover), $beforeHandover);
        }

        self::assertSame('Tomorrow Captain', $this->shownAt('2026-10-21 08:00'));
        self::assertSame(['Tomorrow Captain'], $this->alertedAt('2026-10-21 08:00'));
    }

    public function test_captain_5_page_payload_stays_with_the_shift_on_duty_after_the_next_roster_posts(): void
    {
        $this->postTomorrowsRoster();
        $this->actAs($this->days);

        $this->getJson('/api/captain5/summary')->assertOk()
            ->assertJsonPath('on_duty.source', 'telestaff')
            ->assertJsonPath('on_duty.name', 'Night Captain')
            ->assertJsonPath('on_duty.duty_date', self::TODAY);
    }

    public function test_an_alert_goes_to_the_seat_holders_when_the_person_shown_cannot_use_the_page(): void
    {
        $firefighter = $this->officer('Firefighter', 'Riding Firefighter');
        $holder = $this->officer('Captain', 'Seat Captain');
        $this->seat($holder, 'Captain 5', 'A Shift');
        DailyRosterSnapshotFactory::publish(self::TODAY, ['Rescue Division / Captain 5' => [
            ['employee_id' => $firefighter->employee_id, 'name' => 'FIREFIGHTER, RIDING', 'rank' => 'FF', 'code' => '+OT', 'category' => 'Overtime'],
        ]], ['generated_at' => CarbonImmutable::parse('2026-10-20 22:25', 'America/New_York')]);
        $this->forgetCaptain5Memo();

        $names = app(Captain5Recipients::class)->onDuty()->map(static fn (User $user): string => (string) $user->name)->all();

        self::assertSame(['Seat Captain'], array_values($names));
    }

    public function test_a_new_finding_alerts_the_captain_the_roster_says_is_working(): void
    {
        $crew = $this->officer('Firefighter', 'Crew Member');
        $this->reportMissing($this->rescue, $crew, [$this->firstItemId($this->rescue)]);

        $notified = DB::table('inspection_notification_deliveries')->where('event_key', 'like', 'captain5:%')
            ->pluck('recipient_user_id')->map(static fn ($id): int => (int) $id)->all();

        self::assertContains((int) $this->nights->getKey(), $notified);
        self::assertNotContains((int) $this->days->getKey(), $notified);
    }

    public function test_the_page_shows_the_next_shift_as_soon_as_it_posts_and_says_who_is_on_duty(): void
    {
        $this->actAs($this->days);
        $this->get('/daily-roster')->assertOk()->assertSee('CAPTAIN, NIGHT')->assertDontSee('This is the next shift');

        $this->postTomorrowsRoster();

        $this->get('/daily-roster')->assertOk()
            ->assertSee('CAPTAIN, TOMORROW')
            ->assertDontSee('CAPTAIN, NIGHT')
            ->assertSee('Next shift')
            ->assertSee('This is the next shift. It starts Wednesday, October 21 at 8:00 AM. A Shift stays on duty until then.')
            ->assertSee('See the shift on duty now');
    }

    public function test_the_shift_on_duty_stays_one_link_away(): void
    {
        $this->postTomorrowsRoster();
        $this->actAs($this->days);

        $this->get('/daily-roster?date='.self::TODAY)->assertOk()
            ->assertSee('CAPTAIN, NIGHT')
            ->assertDontSee('CAPTAIN, TOMORROW')
            ->assertSee('On duty now')
            ->assertSee("Go to the next shift's roster");
    }

    public function test_the_export_follows_the_roster_on_screen(): void
    {
        $this->postTomorrowsRoster();
        $this->actAs($this->days);

        $this->get('/daily-roster/pdf')->assertOk()->assertDownload('MBFD-Daily-Roster-'.self::TOMORROW.'.pdf');
        $this->get('/daily-roster/pdf?date='.self::TODAY)->assertOk()->assertDownload('MBFD-Daily-Roster-'.self::TODAY.'.pdf');
    }

    public function test_an_open_page_is_told_when_the_next_shift_posts_and_again_when_it_starts(): void
    {
        $freshness = app(RosterFreshnessResolver::class);
        $before = $freshness->pageStatus()->version;

        $this->postTomorrowsRoster();
        $posted = $freshness->pageStatus();
        self::assertNotSame($before, $posted->version);
        self::assertSame(self::TOMORROW, $posted->dutyDate);
        self::assertSame(self::TODAY, $freshness->status()->dutyDate, 'The cards keep reading the duty day on the clock');

        $this->travelTo(CarbonImmutable::parse('2026-10-21 08:00:00', 'America/New_York'));
        $started = $freshness->pageStatus();
        self::assertSame(self::TOMORROW, $started->dutyDate);
        self::assertNotSame($posted->version, $started->version, 'An open page must drop its next-shift notice at 08:00');
    }

    public function test_a_late_correction_to_the_shift_on_duty_still_reaches_an_open_page(): void
    {
        $this->postTomorrowsRoster();
        $freshness = app(RosterFreshnessResolver::class);
        $before = $freshness->pageStatus()->version;

        DailyRosterSnapshotFactory::publish(self::TODAY, ['Rescue Division / Captain 5' => [
            ['employee_id' => $this->days->employee_id, 'name' => 'CAPTAIN, DAY', 'rank' => 'Capt', 'code' => '+OT', 'category' => 'Overtime'],
        ]], ['generated_at' => CarbonImmutable::parse('2026-10-20 22:25', 'America/New_York')]);

        self::assertNotSame($before, $freshness->pageStatus()->version);
    }

    public function test_no_link_to_the_shift_on_duty_when_it_has_no_roster(): void
    {
        DB::table('roster_active_versions')->whereDate('duty_date', self::TODAY)->update(['roster_snapshot_id' => null]);
        $this->postTomorrowsRoster();
        $this->actAs($this->days);

        $this->get('/daily-roster')->assertOk()
            ->assertSee('This is the next shift')
            ->assertDontSee('See the shift on duty now');
    }

    public function test_a_roster_further_ahead_than_the_next_shift_never_takes_over_the_page(): void
    {
        DailyRosterSnapshotFactory::publish('2026-10-22', ['Rescue Division / Captain 5' => [
            ['employee_id' => $this->tomorrow->employee_id, 'name' => 'CAPTAIN, LATER', 'rank' => 'Capt', 'code' => 'SP', 'category' => 'Scheduled'],
        ]]);
        $this->actAs($this->days);

        $this->get('/daily-roster')->assertOk()->assertSee('CAPTAIN, NIGHT')->assertDontSee('CAPTAIN, LATER');
    }
}
