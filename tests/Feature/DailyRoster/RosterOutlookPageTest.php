<?php

declare(strict_types=1);

namespace Tests\Feature\DailyRoster;

use App\Models\DailyRoster\RosterActiveVersion;
use App\Services\DailyRoster\RosterOutlook;
use App\Services\Training\CrewPlanner\TrainingRosterDutySource;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\DailyRosterSnapshotFactory;
use Tests\TestCase;

/** One roster page with a day strip over the 7-day outlook, and the same days in the Crew Planner. */
final class RosterOutlookPageTest extends TestCase
{
    use RefreshDatabase;

    private const PAGE = '/daily-roster';

    /** Friday 10:30 ET: inside the duty day that started at 08:00. */
    private const NOW = '2026-10-30 10:30:00';

    private const TODAY = '2026-10-30';

    private const OUTLOOK_DAY = '2026-11-01';

    private const MISSING_DAY = '2026-11-02';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(CarbonImmutable::parse(self::NOW, 'America/New_York'));
        config(['daily_roster.mode' => 'publish', 'daily_roster.outlook.mode' => 'publish']);

        DailyRosterSnapshotFactory::publish(self::TODAY, ['Station 2 / Captain 5' => [['employee_id' => '90001', 'name' => 'TESTER, ALPHA']]]);
        $this->publishOutlook(self::OUTLOOK_DAY, '2026-10-30 07:00:05');
        $this->publishOutlook('2026-11-03', '2026-10-30 07:00:09');
    }

    public function test_the_page_opens_on_today_with_a_seven_day_strip(): void
    {
        $this->actingAsCanonicalFixture();

        $html = $this->get(self::PAGE)->assertOk()->getContent();

        // The same seven days above and below the roster.
        self::assertStringContainsString('aria-label="Roster days"', $html);
        self::assertStringContainsString('aria-label="Roster days, end of roster"', $html);
        self::assertSame(14, substr_count($html, 'data-roster-day="'));
        self::assertMatchesRegularExpression('/aria-current="date"\s+data-roster-day="'.self::TODAY.'">Today<span class="sr-only">: Friday, October 30</', $html);
        self::assertStringContainsString('Sun 1<span class="sr-only">: Sunday, November 1</span>', $html);
        self::assertStringContainsString('href="'.route('daily-roster.show', ['date' => self::OUTLOOK_DAY]).'"', $html);
        self::assertMatchesRegularExpression('/<span class="roster-days__day roster-days__day--missing" data-roster-day="'.self::MISSING_DAY.'">Mon 2<span class="sr-only">: Monday, November 2, not available yet/', $html);
        self::assertStringContainsString('data-roster-day="2026-11-05"', $html);
        self::assertStringNotContainsString('data-roster-day="2026-11-06"', $html);
        self::assertStringNotContainsString('data-roster-outlook', $html);
        self::assertStringContainsString('data-live="1"', $html);
    }

    public function test_an_outlook_day_is_labelled_read_only_and_does_not_poll(): void
    {
        $this->actingAsCanonicalFixture();

        $response = $this->get(self::PAGE.'?date='.self::OUTLOOK_DAY)->assertOk();
        $html = $response->getContent();

        $response->assertSee('<title>Daily Roster · Sunday, November 1 · MBFD Hub</title>', false);
        $response->assertSeeInOrder(['data-roster-outlook', 'Outlook.', 'Outlook from Oct 30, 7:00 AM EDT. It may change before the shift.']);
        self::assertMatchesRegularExpression('/aria-current="date"\s+data-roster-day="'.self::OUTLOOK_DAY.'"/', $html);
        self::assertMatchesRegularExpression('/data-roster-freshness>Outlook</', $html);
        self::assertStringContainsString('data-live="0"', $html);
    }

    public function test_an_outlook_not_updated_for_hours_says_so(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-30 15:30:00', 'America/New_York'));
        $this->actingAsCanonicalFixture();

        $this->get(self::PAGE.'?date='.self::OUTLOOK_DAY)->assertOk()
            ->assertSee('TeleStaff has not updated it since, so it may be out of date.');
    }

    public function test_with_the_outlook_off_the_page_is_exactly_as_before(): void
    {
        config(['daily_roster.outlook.mode' => 'off']);
        $this->actingAsCanonicalFixture();

        $html = $this->get(self::PAGE.'?date='.self::OUTLOOK_DAY)->assertOk()->getContent();

        self::assertStringNotContainsString('data-roster-days', $html);
        self::assertStringNotContainsString('data-roster-outlook', $html);
        self::assertStringContainsString('<title>Daily Roster · MBFD Hub</title>', $html);
        self::assertStringContainsString('data-live="1"', $html, 'An outlook date cannot be opened while the outlook is off.');
    }

    public function test_the_crew_planner_reads_outlook_days_and_labels_them(): void
    {
        $source = app(TrainingRosterDutySource::class);

        self::assertContains(self::OUTLOOK_DAY, $source->availableDates(14));
        self::assertTrue($source->forDate(self::OUTLOOK_DAY)->isOutlook);
        self::assertTrue($source->forDate(self::OUTLOOK_DAY)->isVerified());
        self::assertFalse($source->forDate(self::TODAY)->isOutlook);
        self::assertFalse($source->forDate(self::MISSING_DAY)->isVerified());
    }

    private function publishOutlook(string $dutyDate, string $generatedLocal): void
    {
        DailyRosterSnapshotFactory::publish($dutyDate, ['Station 2 / Captain 5' => [['employee_id' => '90002', 'name' => 'TESTER, BRAVO']]], [
            // The factory stores the wall clock it is given, so hand it UTC.
            'generated_at' => CarbonImmutable::parse($generatedLocal, 'America/New_York')->utc(),
        ]);
        RosterActiveVersion::query()->whereDate('duty_date', $dutyDate)->update(['source' => RosterOutlook::SOURCE]);
    }
}
