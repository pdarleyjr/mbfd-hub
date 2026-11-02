<?php

declare(strict_types=1);

namespace Tests\Feature\DailyRoster\Pipeline;

use App\Models\DailyRoster\RosterActiveVersion;
use App\Models\DailyRoster\RosterEvent;
use App\Models\DailyRoster\RosterImport;
use App\Models\DailyRoster\RosterSnapshot;
use App\Services\DailyRoster\CurrentRosterReader;
use App\Services\DailyRoster\Parsing\TeleStaffWorkbookException;
use App\Services\DailyRoster\RosterAnomalyGuard;
use App\Services\DailyRoster\RosterFreshness;
use App\Services\DailyRoster\RosterFreshnessResolver;
use App\Services\DailyRoster\RosterOutlook;
use App\Services\DailyRoster\RosterPublicationVerifier;
use App\Services\DailyRoster\RosterStateRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\DailyRoster\Pipeline\Support\InteractsWithRosterPipeline;
use Tests\Feature\DailyRoster\Pipeline\Support\RosterPipelineFixtures;
use Tests\TestCase;

/**
 * TeleStaff's 7-day outlook on the shared pointer: it fills dates, the 5-minute export always wins its
 * date back, and unchanged days store nothing. The clock is 09:30 Eastern on duty date 2026-10-30.
 */
final class RosterOutlookTest extends TestCase
{
    use InteractsWithRosterPipeline, RefreshDatabase;

    private const TODAY = '2026-10-30';

    private const AHEAD = '2026-11-02';

    protected function setUp(): void
    {
        parent::setUp();
        $this->preparePipeline();
        config(['daily_roster.outlook.mode' => 'publish']);
    }

    public function test_an_outlook_day_fills_a_future_date_and_is_labelled_as_outlook(): void
    {
        $import = $this->outlook(self::AHEAD, '2026-10-30 13:20:00');

        self::assertSame(RosterImport::STATE_PUBLISHED, $import->state);
        self::assertSame(RosterOutlook::SOURCE, $this->pointer(self::AHEAD)->source);
        $view = app(CurrentRosterReader::class)->forDutyDate(self::AHEAD);
        self::assertTrue($view->isOutlook);
        self::assertSame((int) $import->roster_snapshot_id, (int) $view->snapshot->getKey());
    }

    public function test_the_outlook_never_replaces_a_roster_the_5_minute_export_keeps_current(): void
    {
        $live = $this->importExport(RosterPipelineFixtures::report(self::TODAY));

        $outlook = $this->outlook(self::TODAY, '2026-10-30 13:25:00', ['variant' => 'later']);

        self::assertSame(RosterImport::STATE_SUPERSEDED, $outlook->state);
        self::assertSame((int) $live->roster_snapshot_id, (int) $this->pointer(self::TODAY)->roster_snapshot_id);
        self::assertNull($this->pointer(self::TODAY)->source);
        self::assertNull($outlook->roster_snapshot_id);
        self::assertSame(1, RosterSnapshot::query()->count(), 'A deferred outlook day stores no snapshot.');
    }

    public function test_the_outlook_fills_the_current_day_when_no_5_minute_roster_exists(): void
    {
        $outlook = $this->outlook(self::TODAY, '2026-10-30 11:00:00');

        self::assertSame(RosterImport::STATE_PUBLISHED, $outlook->state);
        self::assertTrue(app(CurrentRosterReader::class)->current()->isOutlook);
    }

    public function test_the_outlook_covers_the_current_day_once_the_5_minute_export_goes_stale(): void
    {
        config(['daily_roster.cadence.expected_interval_minutes' => 5]);
        $live = $this->importExport(
            RosterPipelineFixtures::report(self::TODAY, ['generated_at' => '2026-10-30 12:30:00']),
            ['received_at' => CarbonImmutable::parse('2026-10-30 12:30:10', 'UTC')],
        );
        self::assertSame(RosterImport::STATE_PUBLISHED, $live->state);

        $outlook = $this->outlook(self::TODAY, '2026-10-30 13:25:00', ['variant' => 'outage']);

        self::assertSame(RosterImport::STATE_PUBLISHED, $outlook->state);
        self::assertSame(RosterOutlook::SOURCE, $this->pointer(self::TODAY)->source);
        self::assertSame(RosterFreshness::DELAYED, app(RosterFreshnessResolver::class)->status()->state, 'Outlook mail never hides the outage.');
    }

    public function test_the_5_minute_export_takes_a_date_back_even_when_generated_before_the_outlook(): void
    {
        $this->outlook(self::TODAY, '2026-10-30 13:25:00', ['variant' => 'outlook']);

        $live = $this->importExport(RosterPipelineFixtures::report(self::TODAY, ['generated_at' => '2026-10-30 13:20:00']));

        self::assertSame(RosterImport::STATE_PUBLISHED, $live->state);
        $pointer = $this->pointer(self::TODAY);
        self::assertNull($pointer->source);
        self::assertSame((int) $live->roster_snapshot_id, (int) $pointer->roster_snapshot_id);
        self::assertTrue($pointer->effective_generated_at->equalTo(CarbonImmutable::parse('2026-10-30 13:20:00', 'UTC')));
        self::assertFalse(app(CurrentRosterReader::class)->current()->isOutlook);
    }

    public function test_the_5_minute_export_confirming_the_outlooks_roster_takes_the_date_back_without_a_new_version(): void
    {
        $outlook = $this->outlook(self::TODAY, '2026-10-30 13:25:00');

        $live = $this->importExport(RosterPipelineFixtures::report(self::TODAY, ['generated_at' => '2026-10-30 13:28:00']));

        self::assertSame(RosterImport::OUTCOME_CONFIRMED, $live->outcome);
        $pointer = $this->pointer(self::TODAY);
        self::assertNull($pointer->source);
        self::assertSame((int) $outlook->roster_snapshot_id, (int) $pointer->roster_snapshot_id);
        self::assertTrue($pointer->last_confirmed_at->equalTo(CarbonImmutable::parse('2026-10-30 13:28:00', 'UTC')));
        self::assertSame(1, RosterSnapshot::query()->count());
    }

    public function test_an_outlook_published_before_the_takeover_still_verifies(): void
    {
        $outlook = $this->outlook(self::TODAY, '2026-10-30 13:25:00', ['variant' => 'outlook']);
        $this->importExport(RosterPipelineFixtures::report(self::TODAY, ['generated_at' => '2026-10-30 13:20:00']));

        self::assertTrue(app(RosterPublicationVerifier::class)->verify($outlook));
        self::assertSame(RosterImport::STATE_VERIFIED, $outlook->refresh()->state);
    }

    public function test_a_pinned_date_sets_outlook_mail_aside_quietly(): void
    {
        $this->outlook(self::AHEAD, '2026-10-30 12:00:00');
        $this->pointer(self::AHEAD)->forceFill(['pinned_at' => now()])->save();

        $later = $this->outlook(self::AHEAD, '2026-10-30 13:25:00', ['variant' => 'later']);

        self::assertSame(RosterImport::STATE_SUPERSEDED, $later->state);
        self::assertSame(0, RosterImport::query()->where('state', RosterImport::STATE_NEEDS_REVIEW)->count());
        self::assertSame(1, RosterSnapshot::query()->count());
    }

    public function test_resending_an_unchanged_outlook_day_stores_nothing_new(): void
    {
        $first = $this->outlook(self::AHEAD, '2026-10-30 12:00:00');

        $again = $this->outlook(self::AHEAD, '2026-10-30 13:25:00');

        self::assertSame(RosterImport::OUTCOME_CONFIRMED, $again->outcome);
        self::assertSame(1, RosterSnapshot::query()->count());
        $pointer = $this->pointer(self::AHEAD);
        self::assertSame((int) $first->roster_snapshot_id, (int) $pointer->roster_snapshot_id);
        self::assertSame(RosterOutlook::SOURCE, $pointer->source);
        self::assertTrue($pointer->last_confirmed_at->equalTo(CarbonImmutable::parse('2026-10-30 13:25:00', 'UTC')));
    }

    public function test_a_changed_outlook_day_replaces_the_older_outlook_and_an_older_one_stores_nothing(): void
    {
        $this->outlook(self::AHEAD, '2026-10-30 12:00:00');
        $newer = $this->outlook(self::AHEAD, '2026-10-30 13:25:00', ['variant' => 'newer']);
        $late = $this->outlook(self::AHEAD, '2026-10-30 12:30:00', ['variant' => 'late']);

        self::assertSame(RosterImport::STATE_PUBLISHED, $newer->state);
        self::assertSame(RosterImport::STATE_SUPERSEDED, $late->state);
        self::assertSame((int) $newer->roster_snapshot_id, (int) $this->pointer(self::AHEAD)->roster_snapshot_id);
        self::assertSame(2, RosterSnapshot::query()->count());
    }

    public function test_an_outlook_day_already_past_is_set_aside(): void
    {
        $import = $this->outlook('2026-10-29', '2026-10-30 12:00:00');

        self::assertSame(RosterImport::STATE_SUPERSEDED, $import->state);
        self::assertNull($this->pointer('2026-10-29')?->roster_snapshot_id);
    }

    public function test_in_shadow_mode_the_outlook_is_checked_and_never_published(): void
    {
        config(['daily_roster.outlook.mode' => 'shadow']);

        $import = $this->outlook(self::AHEAD, '2026-10-30 13:20:00');

        self::assertSame(RosterImport::STATE_SHADOWED, $import->state);
        self::assertSame(0, RosterActiveVersion::query()->count());
    }

    public function test_the_outlook_publishes_only_when_the_5_minute_export_does(): void
    {
        config(['daily_roster.mode' => 'shadow']);

        self::assertSame(RosterImport::STATE_SHADOWED, $this->outlook(self::AHEAD, '2026-10-30 13:20:00')->state);
    }

    public function test_the_outlook_never_moves_the_page_to_the_next_shift(): void
    {
        $this->importExport(RosterPipelineFixtures::report(self::TODAY));
        $this->outlook('2026-10-31', '2026-10-30 13:20:00');

        self::assertSame(self::TODAY, app(RosterFreshnessResolver::class)->pageStatus()->dutyDate);
    }

    public function test_the_5_minute_export_compares_only_with_its_own_rosters(): void
    {
        $this->outlook(self::TODAY, '2026-10-30 13:00:00', ['layout' => str_repeat('ab', 32)]);

        $live = RosterPipelineFixtures::import();
        $reasons = app(RosterAnomalyGuard::class)->check($live, RosterPipelineFixtures::report(self::TODAY));

        self::assertNotContains(RosterAnomalyGuard::LAYOUT_CHANGED, $reasons);
    }

    public function test_turning_the_outlook_off_hides_its_rosters_at_once(): void
    {
        $this->outlook(self::AHEAD, '2026-10-30 13:20:00');
        $live = $this->importExport(RosterPipelineFixtures::report(self::TODAY));

        config(['daily_roster.outlook.mode' => 'off']);

        $reader = app(CurrentRosterReader::class);
        self::assertNull($reader->forDutyDate(self::AHEAD));
        self::assertSame([self::TODAY], $reader->availableDutyDates());
        self::assertSame((int) $live->roster_snapshot_id, (int) $reader->current()->snapshot->getKey());
    }

    public function test_outlook_mail_still_in_flight_when_the_outlook_is_turned_off_is_only_shadowed(): void
    {
        config(['daily_roster.outlook.mode' => 'off']);

        $import = $this->outlook(self::AHEAD, '2026-10-30 13:20:00');

        self::assertSame(RosterImport::STATE_SHADOWED, $import->state);
        self::assertSame(0, RosterActiveVersion::query()->count());
    }

    public function test_turning_the_outlook_off_keeps_a_current_day_it_covers_the_same_for_every_reader(): void
    {
        $outlook = $this->outlook(self::TODAY, '2026-10-30 11:00:00');

        config(['daily_roster.outlook.mode' => 'off']);

        self::assertSame((int) $outlook->roster_snapshot_id, (int) app(CurrentRosterReader::class)->current()->snapshot->getKey());
    }

    public function test_the_late_alert_still_fires_when_only_the_outlook_covers_the_day(): void
    {
        $this->outlook(self::TODAY, '2026-10-30 11:00:00');
        $this->travelTo(CarbonImmutable::parse('2026-10-30 18:00:00', 'America/New_York'));

        $this->artisan('daily-roster:reconcile')->assertExitCode(0);

        self::assertTrue(RosterEvent::query()->where('action', RosterStateRecorder::ACTION_DELAYED)->whereDate('duty_date', self::TODAY)->exists());
    }

    public function test_an_outlook_failure_never_closes_a_5_minute_export_failure(): void
    {
        $live = RosterPipelineFixtures::import(['received_at' => now()->subMinutes(10)]);
        $this->runWithParserFailure($live);
        $outlook = RosterPipelineFixtures::import(['source' => RosterOutlook::SOURCE, 'filename' => 'Roster_Day_3.xlsx']);
        $this->runWithParserFailure($outlook);

        self::assertSame(RosterImport::STATE_PERMANENT_FAILED, $live->fresh()->state);
        self::assertSame(RosterImport::STATE_PERMANENT_FAILED, $outlook->fresh()->state);
    }

    private function runWithParserFailure(RosterImport $import): void
    {
        $this->parserReturns(new TeleStaffWorkbookException('sheet_mismatch'));
        $this->runProcess($import);
    }

    /** @param array<string, mixed> $options */
    private function outlook(string $dutyDate, string $generatedAt, array $options = []): RosterImport
    {
        return $this->importExport(
            RosterPipelineFixtures::report($dutyDate, ['generated_at' => $generatedAt, ...$options]),
            ['source' => RosterOutlook::SOURCE, 'filename' => 'Roster_Day_2.xlsx'],
        );
    }

    private function pointer(string $dutyDate): ?RosterActiveVersion
    {
        return RosterActiveVersion::query()->whereDate('duty_date', $dutyDate)->first();
    }
}
