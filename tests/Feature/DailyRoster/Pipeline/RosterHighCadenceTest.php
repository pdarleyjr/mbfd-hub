<?php

declare(strict_types=1);

namespace Tests\Feature\DailyRoster\Pipeline;

use App\Models\DailyRoster\RosterActiveVersion;
use App\Models\DailyRoster\RosterEvent;
use App\Models\DailyRoster\RosterImport;
use App\Models\DailyRoster\RosterSnapshot;
use App\Models\InboundEmail;
use App\Services\DailyRoster\Parsing\TeleStaffWorkbookException;
use App\Services\DailyRoster\RosterAnomalyGuard;
use App\Services\DailyRoster\RosterFreshness;
use App\Services\DailyRoster\RosterFreshnessResolver;
use App\Services\DailyRoster\RosterImportCleanup;
use App\Services\DailyRoster\RosterImportRegistrar;
use App\Services\DailyRoster\RosterPipelineException;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Tests\Feature\DailyRoster\Pipeline\Support\InteractsWithRosterPipeline;
use Tests\Feature\DailyRoster\Pipeline\Support\RosterPipelineFixtures;
use Tests\TestCase;

/** TeleStaff sending every few minutes: the costs that a once-a-day export never showed. */
final class RosterHighCadenceTest extends TestCase
{
    use InteractsWithRosterPipeline;
    use RefreshDatabase;

    private const DATE = '2026-10-30';

    public function test_a_day_of_five_minute_exports_stores_one_roster_per_real_change(): void
    {
        $this->preparePipeline('2026-10-30 12:05:00');
        $moment = CarbonImmutable::parse('2026-10-30 12:05:00', 'UTC');

        for ($export = 0; $export < 288; $export++) {
            $this->travelTo($moment);
            // The roster really changes three times in the day.
            $variant = match (true) {
                $export < 100 => 'A',
                $export < 200 => 'B',
                default => 'C',
            };
            $this->importExport(RosterPipelineFixtures::report(self::DATE, ['generated_at' => $moment->subSeconds(10), 'variant' => $variant]));
            $moment = $moment->addMinutes(5);
        }

        self::assertSame(3, RosterSnapshot::query()->count());
        self::assertSame(288, RosterImport::query()->count());
        self::assertSame(285, RosterImport::query()->where('outcome', RosterImport::OUTCOME_CONFIRMED)->count());
        Exceptions::assertNothingReported();
    }

    public function test_an_export_with_no_changes_takes_a_bounded_number_of_queries(): void
    {
        $this->preparePipeline();
        $this->importExport(RosterPipelineFixtures::report(self::DATE, ['generated_at' => '2026-10-30 13:10:00']));
        $next = RosterPipelineFixtures::report(self::DATE, ['generated_at' => '2026-10-30 13:15:00']);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->importExport($next);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        self::assertLessThan(45, $queries);
    }

    public function test_a_roster_that_changes_and_changes_back_shows_the_right_people_each_time(): void
    {
        $this->preparePipeline();
        $a = $this->importExport(RosterPipelineFixtures::report(self::DATE, ['generated_at' => '2026-10-30 13:10:00', 'variant' => 'A']));
        $this->importExport(RosterPipelineFixtures::report(self::DATE, ['generated_at' => '2026-10-30 13:15:00', 'variant' => 'B']));
        $back = $this->importExport(RosterPipelineFixtures::report(self::DATE, ['generated_at' => '2026-10-30 13:20:00', 'variant' => 'A']));

        self::assertSame(RosterImport::STATE_PUBLISHED, $back->state);
        self::assertSame((int) $a->roster_snapshot_id, (int) $this->pointerFor(self::DATE)->roster_snapshot_id);
        self::assertSame(2, RosterSnapshot::query()->count());
    }

    public function test_the_pointer_records_when_the_roster_changed_and_a_confirmation_leaves_that_alone(): void
    {
        $this->preparePipeline('2026-10-30 13:30:00');
        $this->importExport(RosterPipelineFixtures::report(self::DATE, ['generated_at' => '2026-10-30 13:10:00']));
        $changed = $this->pointerFor(self::DATE)->content_changed_at;
        self::assertSame('2026-10-30 13:30:00', $changed->utc()->format('Y-m-d H:i:s'));

        $this->travelTo(CarbonImmutable::parse('2026-10-30 13:35:00', 'UTC'));
        $this->importExport(RosterPipelineFixtures::report(self::DATE, ['generated_at' => '2026-10-30 13:32:00']));

        self::assertSame('2026-10-30 13:30:00', $this->pointerFor(self::DATE)->content_changed_at->utc()->format('Y-m-d H:i:s'));
    }

    public function test_a_new_hold_replaces_the_previous_one_so_review_shows_only_the_latest(): void
    {
        $this->preparePipeline();
        $this->importExport(RosterPipelineFixtures::report(self::DATE, ['generated_at' => '2026-10-30 13:00:00']));

        $holds = [];
        foreach (['13:05:00', '13:10:00', '13:15:00'] as $time) {
            $holds[] = $this->importExport(RosterPipelineFixtures::report(self::DATE, ['generated_at' => '2026-10-30 '.$time, 'layout' => str_repeat('b', 64)]));
        }

        self::assertSame(RosterImport::STATE_SUPERSEDED, $holds[0]->fresh()->state);
        self::assertSame(RosterImport::STATE_SUPERSEDED, $holds[1]->fresh()->state);
        self::assertSame(RosterImport::STATE_NEEDS_REVIEW, $holds[2]->fresh()->state);
        self::assertSame(1, RosterImport::query()->where('state', RosterImport::STATE_NEEDS_REVIEW)->count());
        self::assertSame(RosterFreshness::NEEDS_REVIEW, app(RosterFreshnessResolver::class)->status()->state);
    }

    public function test_a_held_export_is_reported_once_however_many_more_arrive(): void
    {
        $this->preparePipeline();
        $this->importExport(RosterPipelineFixtures::report(self::DATE, ['generated_at' => '2026-10-30 13:00:00']));

        foreach (['13:05:00', '13:10:00', '13:15:00', '13:20:00'] as $time) {
            $this->importExport(RosterPipelineFixtures::report(self::DATE, ['generated_at' => '2026-10-30 '.$time, 'layout' => str_repeat('b', 64)]));
        }

        Exceptions::assertReportedCount(1);
        Exceptions::assertReported(static fn (RosterPipelineException $exception): bool => $exception->errorCode === RosterAnomalyGuard::LAYOUT_CHANGED);
    }

    public function test_a_broken_workbook_is_reported_once_and_only_the_newest_failure_stays_open(): void
    {
        $this->preparePipeline();
        $failures = [];
        foreach ([1, 2, 3] as $minute) {
            $this->travel(5)->minutes();
            $import = RosterPipelineFixtures::import();
            $this->parserReturns(new TeleStaffWorkbookException('hours_mismatch', sourceRow: 40));
            $this->runProcess($import);
            $failures[] = $import->fresh();
        }

        Exceptions::assertReportedCount(1);
        self::assertSame(RosterImport::STATE_SUPERSEDED, $failures[0]->fresh()->state);
        self::assertSame(RosterImport::STATE_SUPERSEDED, $failures[1]->fresh()->state);
        self::assertSame(RosterImport::STATE_PERMANENT_FAILED, $failures[2]->fresh()->state);
    }

    public function test_a_failed_report_store_never_hides_the_incident(): void
    {
        $this->preparePipeline();
        $this->importExport(RosterPipelineFixtures::report(self::DATE, ['generated_at' => '2026-10-30 13:00:00']));
        Cache::shouldReceive('add')->andThrow(new \RuntimeException('cache_down'));

        $this->importExport(RosterPipelineFixtures::report(self::DATE, ['generated_at' => '2026-10-30 13:05:00', 'layout' => str_repeat('b', 64)]));

        Exceptions::assertReported(static fn (RosterPipelineException $exception): bool => $exception->errorCode === RosterAnomalyGuard::LAYOUT_CHANGED);
    }

    public function test_held_mail_that_was_never_read_is_coalesced_too(): void
    {
        $this->preparePipeline();
        $imports = [];
        foreach ([0, 5, 10] as $minutes) {
            $this->travelTo(CarbonImmutable::parse('2026-10-30 13:30:00', 'UTC')->addMinutes($minutes));
            [$email] = RosterPipelineFixtures::linkedImport(['state' => RosterImport::STATE_CLASSIFIED]);
            RosterImport::query()->where('inbound_email_id', $email->getKey())->delete();
            $email->forceFill(['transport_evidence' => null])->save();
            $imports[] = app(RosterImportRegistrar::class)->register($email->fresh());
        }

        self::assertSame(RosterImport::STATE_NEEDS_REVIEW, $imports[2]->fresh()->state);
        self::assertSame(RosterImport::STATE_SUPERSEDED, $imports[0]->fresh()->state);
        self::assertSame(RosterImport::STATE_SUPERSEDED, $imports[1]->fresh()->state);
    }

    public function test_every_kind_of_trouble_alerts_even_behind_more_than_a_batch_of_older_rows(): void
    {
        $this->preparePipeline();
        $now = CarbonImmutable::now('UTC');
        for ($row = 0; $row < 130; $row++) {
            RosterImport::query()->create($this->ledgerRow($row, RosterImport::STATE_NEEDS_REVIEW, $now));
        }
        RosterImport::query()->create($this->ledgerRow(500, RosterImport::STATE_REJECTED, $now));

        Artisan::call('daily-roster:reconcile');

        self::assertTrue(Cache::has('daily_roster_alert:rejected:'.self::DATE));
        self::assertTrue(Cache::has('daily_roster_alert:needs_review:'.self::DATE));
    }

    public function test_with_no_cadence_configured_a_roster_is_never_called_stale(): void
    {
        $this->preparePipeline();
        $this->importExport(RosterPipelineFixtures::report(self::DATE, ['generated_at' => '2026-10-30 13:00:00']));

        $this->travelTo(CarbonImmutable::parse('2026-10-31 11:00:00', 'UTC'));

        self::assertSame(RosterFreshness::CURRENT, app(RosterFreshnessResolver::class)->status()->state);
    }

    public function test_a_roster_goes_stale_after_three_missed_exports_and_recovers_by_itself(): void
    {
        $this->preparePipeline();
        config(['daily_roster.cadence.expected_interval_minutes' => 5]);
        $this->importExport(RosterPipelineFixtures::report(self::DATE, ['generated_at' => '2026-10-30 13:30:00']));
        $resolver = app(RosterFreshnessResolver::class);

        $this->travelTo(CarbonImmutable::parse('2026-10-30 13:44:00', 'UTC'));
        self::assertSame(RosterFreshness::CURRENT, $resolver->status()->state);

        $this->travelTo(CarbonImmutable::parse('2026-10-30 13:46:00', 'UTC'));
        self::assertSame(RosterFreshness::DELAYED, $resolver->status()->state);

        $this->importExport(RosterPipelineFixtures::report(self::DATE, ['generated_at' => '2026-10-30 13:45:50']));
        self::assertSame(RosterFreshness::CURRENT, $resolver->status()->state);
    }

    public function test_held_exports_still_arriving_are_trouble_with_the_export_not_silence(): void
    {
        $this->preparePipeline();
        config(['daily_roster.cadence.expected_interval_minutes' => 5]);
        $this->importExport(RosterPipelineFixtures::report(self::DATE, ['generated_at' => '2026-10-30 13:30:00']));
        $this->travelTo(CarbonImmutable::parse('2026-10-30 14:00:00', 'UTC'));
        $this->importExport(RosterPipelineFixtures::report(self::DATE, ['generated_at' => '2026-10-30 13:59:50', 'layout' => str_repeat('b', 64)]));

        self::assertSame(RosterFreshness::NEEDS_REVIEW, app(RosterFreshnessResolver::class)->status()->state);

        Artisan::call('daily-roster:reconcile');
        self::assertSame(0, RosterEvent::query()->where('action', 'roster_stale')->count());
    }

    public function test_a_pinned_date_is_not_stale_because_an_administrator_chose_it(): void
    {
        $this->preparePipeline();
        config(['daily_roster.cadence.expected_interval_minutes' => 5]);
        $this->importExport(RosterPipelineFixtures::report(self::DATE, ['generated_at' => '2026-10-30 13:30:00']));
        RosterActiveVersion::query()->whereDate('duty_date', self::DATE)->update(['pinned_at' => CarbonImmutable::now('UTC')]);

        $this->travelTo(CarbonImmutable::parse('2026-10-30 15:00:00', 'UTC'));

        self::assertSame(RosterFreshness::CURRENT, app(RosterFreshnessResolver::class)->status()->state);
    }

    public function test_stale_alerts_once_per_outage_and_a_second_outage_alerts_again(): void
    {
        $this->preparePipeline();
        config(['daily_roster.cadence.expected_interval_minutes' => 5]);
        $firstHeard = CarbonImmutable::parse('2026-10-30 13:30:00', 'UTC')->getTimestamp();
        $this->importExport(RosterPipelineFixtures::report(self::DATE, ['generated_at' => '2026-10-30 13:30:00']));

        $this->travelTo(CarbonImmutable::parse('2026-10-30 14:00:00', 'UTC'));
        Artisan::call('daily-roster:reconcile');
        Artisan::call('daily-roster:reconcile');
        self::assertTrue(Cache::has('daily_roster_alert:stale:'.self::DATE.':'.$firstHeard));
        self::assertSame(1, RosterEvent::query()->where('action', 'roster_stale')->count());

        $this->importExport(RosterPipelineFixtures::report(self::DATE, ['generated_at' => '2026-10-30 13:59:50']));
        $this->travelTo(CarbonImmutable::parse('2026-10-30 15:00:00', 'UTC'));
        Artisan::call('daily-roster:reconcile');
        self::assertSame(2, RosterEvent::query()->where('action', 'roster_stale')->count());
        self::assertTrue(Cache::has('daily_roster_alert:stale:'.self::DATE.':'.CarbonImmutable::parse('2026-10-30 13:59:50', 'UTC')->getTimestamp()));
    }

    public function test_with_a_cadence_a_missing_roster_is_late_soon_after_the_duty_day_starts(): void
    {
        $this->preparePipeline();
        config(['daily_roster.cadence.expected_interval_minutes' => 5]);
        $this->importExport(RosterPipelineFixtures::report('2026-10-29', ['generated_at' => '2026-10-29 13:00:00']));
        $resolver = app(RosterFreshnessResolver::class);

        $this->travelTo(CarbonImmutable::parse('2026-10-30 08:10:00', 'America/New_York'));
        self::assertSame(RosterFreshness::AWAITING, $resolver->status()->state);

        $this->travelTo(CarbonImmutable::parse('2026-10-30 08:20:00', 'America/New_York'));
        self::assertSame(RosterFreshness::DELAYED, $resolver->status()->state);
    }

    public function test_a_failed_email_overtaken_by_a_verified_export_is_removed_after_the_grace_period_not_before(): void
    {
        $this->preparePipeline();
        config(['daily_roster.cleanup_enabled' => true, 'daily_roster.retention.overtaken_email_days' => 14]);
        [$email, $held] = RosterPipelineFixtures::linkedImport([
            'state' => RosterImport::STATE_SUPERSEDED,
            'outcome' => RosterImport::OUTCOME_SUPERSEDED,
            'duty_date' => self::DATE,
            'source_generated_at' => '2026-10-30 13:00:00',
            'state_changed_at' => CarbonImmutable::now('UTC'),
        ]);
        $this->travel(5)->minutes();
        RosterImport::query()->create([...$this->ledgerRow(900, RosterImport::STATE_VERIFIED, CarbonImmutable::now('UTC')), 'outcome' => RosterImport::OUTCOME_PUBLISHED, 'source_generated_at' => '2026-10-30 13:30:00']);

        app(RosterImportCleanup::class)->run($held);
        self::assertTrue(InboundEmail::query()->whereKey($email->getKey())->exists());

        $this->travel(15)->days();
        app(RosterImportCleanup::class)->run($held->fresh());

        self::assertFalse(InboundEmail::query()->whereKey($email->getKey())->exists());
        self::assertSame(RosterImport::STATE_CLEANED, $held->fresh()->state);
    }

    /** @return iterable<string, array{0: array<string, mixed>}> */
    public static function rowsThatProveNothing(): iterable
    {
        yield 'another held copy that merely finished' => [['state' => RosterImport::STATE_CLEANED, 'outcome' => RosterImport::OUTCOME_SUPERSEDED, 'source_generated_at' => '2026-10-30 13:30:00']];
        yield 'an accepted export of another duty date' => [['state' => RosterImport::STATE_VERIFIED, 'outcome' => RosterImport::OUTCOME_PUBLISHED, 'duty_date' => '2026-10-31', 'source_generated_at' => '2026-10-31 13:30:00']];
        yield 'an accepted export generated earlier, that arrived late' => [['state' => RosterImport::STATE_VERIFIED, 'outcome' => RosterImport::OUTCOME_PUBLISHED, 'source_generated_at' => '2026-10-30 12:30:00']];
    }

    /**
     * @dataProvider rowsThatProveNothing
     *
     * @param  array<string, mixed>  $later
     */
    public function test_only_a_later_accepted_export_of_the_same_date_overtakes_a_held_email(array $later): void
    {
        $this->preparePipeline();
        config(['daily_roster.cleanup_enabled' => true]);
        [$email, $held] = RosterPipelineFixtures::linkedImport([
            'state' => RosterImport::STATE_SUPERSEDED,
            'outcome' => RosterImport::OUTCOME_SUPERSEDED,
            'duty_date' => self::DATE,
            'source_generated_at' => '2026-10-30 13:00:00',
            'state_changed_at' => CarbonImmutable::now('UTC'),
        ]);
        $this->travel(5)->minutes();
        RosterImport::query()->create([...$this->ledgerRow(902, RosterImport::STATE_VERIFIED, CarbonImmutable::now('UTC')), ...$later]);

        $this->travel(60)->days();
        app(RosterImportCleanup::class)->run($held->fresh());

        self::assertTrue(InboundEmail::query()->whereKey($email->getKey())->exists());
    }

    public function test_held_mail_with_a_different_reason_stays_open_for_a_person(): void
    {
        $this->preparePipeline();
        $first = RosterPipelineFixtures::import(['state' => RosterImport::STATE_NEEDS_REVIEW, 'classification' => ['verdict' => 'needs_review', 'reasons' => ['dkim_not_aligned']]]);
        $this->travel(5)->minutes();
        [$email] = RosterPipelineFixtures::linkedImport(['state' => RosterImport::STATE_CLASSIFIED]);
        RosterImport::query()->where('inbound_email_id', $email->getKey())->delete();

        $newest = app(RosterImportRegistrar::class)->register($email->fresh());

        self::assertSame(RosterImport::STATE_NEEDS_REVIEW, $newest->fresh()->state);
        self::assertSame(RosterImport::STATE_NEEDS_REVIEW, $first->fresh()->state);
    }

    public function test_someone_else_moving_an_older_hold_never_undoes_the_new_one(): void
    {
        $this->preparePipeline();
        $this->importExport(RosterPipelineFixtures::report(self::DATE, ['generated_at' => '2026-10-30 13:00:00']));
        $older = $this->importExport(RosterPipelineFixtures::report(self::DATE, ['generated_at' => '2026-10-30 13:05:00', 'layout' => str_repeat('b', 64)]));

        // An administrator's retry lands between the read and the write.
        RosterImport::retrieved(static function (RosterImport $import) use ($older): void {
            if ((int) $import->getKey() === (int) $older->getKey() && $import->state === RosterImport::STATE_NEEDS_REVIEW) {
                DB::table('roster_imports')->where('id', $import->getKey())->update(['state' => RosterImport::STATE_QUEUED]);
            }
        });

        $newest = $this->importExport(RosterPipelineFixtures::report(self::DATE, ['generated_at' => '2026-10-30 13:10:00', 'layout' => str_repeat('b', 64)]));
        RosterImport::flushEventListeners();

        self::assertSame(RosterImport::STATE_NEEDS_REVIEW, $newest->state);
        self::assertSame(RosterImport::STATE_QUEUED, DB::table('roster_imports')->where('id', $older->getKey())->value('state'));
    }

    public function test_a_second_outage_on_the_same_roster_shows_its_own_last_heard_time(): void
    {
        $this->preparePipeline();
        config(['daily_roster.cadence.expected_interval_minutes' => 5]);
        $this->importExport(RosterPipelineFixtures::report(self::DATE, ['generated_at' => '2026-10-30 13:30:00']));
        $resolver = app(RosterFreshnessResolver::class);

        $this->travelTo(CarbonImmutable::parse('2026-10-30 14:00:00', 'UTC'));
        $firstOutage = $resolver->status();
        $this->importExport(RosterPipelineFixtures::report(self::DATE, ['generated_at' => '2026-10-30 13:59:50']));
        $this->travelTo(CarbonImmutable::parse('2026-10-30 15:00:00', 'UTC'));
        $secondOutage = $resolver->status();

        self::assertSame(RosterFreshness::DELAYED, $secondOutage->state);
        self::assertNotSame($firstOutage->version, $secondOutage->version);
    }

    public function test_a_superseded_hold_with_nothing_verified_after_it_is_never_removed(): void
    {
        $this->preparePipeline();
        config(['daily_roster.cleanup_enabled' => true]);
        [$email, $held] = RosterPipelineFixtures::linkedImport([
            'state' => RosterImport::STATE_SUPERSEDED,
            'outcome' => RosterImport::OUTCOME_SUPERSEDED,
            'duty_date' => self::DATE,
            'source_generated_at' => '2026-10-30 13:00:00',
            'state_changed_at' => CarbonImmutable::now('UTC'),
        ]);

        $this->travel(60)->days();
        app(RosterImportCleanup::class)->run($held->fresh());

        self::assertTrue(InboundEmail::query()->whereKey($email->getKey())->exists());
    }

    public function test_a_rejected_email_is_never_removed_by_age(): void
    {
        $this->preparePipeline();
        config(['daily_roster.cleanup_enabled' => true]);
        [$email, $rejected] = RosterPipelineFixtures::linkedImport(['state' => RosterImport::STATE_REJECTED]);
        RosterImport::query()->create([...$this->ledgerRow(901, RosterImport::STATE_VERIFIED, CarbonImmutable::now('UTC')), 'source_generated_at' => '2026-10-30 13:30:00']);

        $this->travel(60)->days();
        app(RosterImportCleanup::class)->run($rejected->fresh());

        self::assertTrue(InboundEmail::query()->whereKey($email->getKey())->exists());
    }

    /** @return array<string, mixed> */
    private function ledgerRow(int $number, string $state, CarbonImmutable $now): array
    {
        return [
            'correlation_id' => (string) \Illuminate\Support\Str::uuid(),
            'state' => $state,
            'state_changed_at' => $now,
            'message_id_sha256' => hash('sha256', 'row-'.$number),
            'sender' => 'donotreply@miamibeachfl.gov',
            'received_at' => $now,
            'duty_date' => self::DATE,
            'source_generated_at' => $now,
        ];
    }
}
