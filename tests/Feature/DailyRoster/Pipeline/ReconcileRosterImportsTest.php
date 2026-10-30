<?php

declare(strict_types=1);

namespace Tests\Feature\DailyRoster\Pipeline;

use App\Jobs\DailyRoster\CleanupRosterImport;
use App\Jobs\DailyRoster\ProcessRosterImport;
use App\Jobs\DailyRoster\VerifyRosterImport;
use App\Models\DailyRoster\RosterActiveVersion;
use App\Models\DailyRoster\RosterEvent;
use App\Models\DailyRoster\RosterImport;
use App\Models\DailyRoster\RosterSnapshot;
use App\Services\DailyRoster\Parsing\TeleStaffRosterReport;
use App\Services\DailyRoster\RosterPipelineException;
use App\Services\DailyRoster\RosterPublisher;
use App\Services\DailyRoster\RosterStateRecorder;
use App\Support\HubTimezone;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Tests\Feature\DailyRoster\Pipeline\Support\InteractsWithRosterPipeline;
use Tests\Feature\DailyRoster\Pipeline\Support\RosterPipelineFixtures;
use Tests\TestCase;

final class ReconcileRosterImportsTest extends TestCase
{
    use InteractsWithRosterPipeline, RefreshDatabase;

    private const DATE = '2026-10-30';

    protected function setUp(): void
    {
        parent::setUp();
        $this->preparePipeline('2026-10-30 13:30:00');
    }

    public function test_it_is_scheduled_every_minute_on_one_server_without_overlap(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(static fn ($event): bool => str_contains((string) $event->command, 'daily-roster:reconcile'));

        self::assertNotNull($event);
        self::assertSame('* * * * *', $event->expression);
        self::assertTrue($event->withoutOverlapping);
        self::assertTrue($event->onOneServer);
    }

    public function test_empty_tables_are_safe_and_take_few_queries(): void
    {
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->reconcile();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        self::assertLessThanOrEqual(7, $queries);
        Bus::assertNothingDispatched();
        Exceptions::assertNothingReported();
    }

    public function test_mode_off_touches_nothing(): void
    {
        config(['daily_roster.mode' => 'off']);
        $import = RosterPipelineFixtures::import(['state' => RosterImport::STATE_CLASSIFIED, 'state_changed_at' => now()->subHour()]);

        $this->reconcile();

        self::assertSame(RosterImport::STATE_CLASSIFIED, $import->fresh()->state);
        Bus::assertNothingDispatched();
    }

    public function test_a_crash_between_commit_and_dispatch_is_repaired_with_no_double_effect(): void
    {
        // The inbound transaction committed; the process died before queueing.
        $import = RosterPipelineFixtures::import(['state' => RosterImport::STATE_CLASSIFIED, 'state_changed_at' => now()->subMinutes(3)]);

        $this->reconcile();

        self::assertSame(RosterImport::STATE_QUEUED, $import->fresh()->state);
        self::assertSame('reconciler_lost_dispatch', RosterEvent::query()->where('roster_import_id', $import->id)->latest('id')->first()->reason);
        Bus::assertDispatchedTimes(ProcessRosterImport::class, 1);
        Bus::assertDispatched(ProcessRosterImport::class, static fn (ProcessRosterImport $job): bool => $job->afterCommit === true);

        // Both the first job and a stray second one run. The second finds nothing to claim.
        $parser = $this->parserReturns(RosterPipelineFixtures::report(self::DATE));
        $this->runProcess($import);
        $this->runProcess($import);

        self::assertSame(1, $parser->calls);
        self::assertSame(1, RosterSnapshot::query()->count());
        self::assertSame(RosterImport::STATE_PUBLISHED, $import->fresh()->state);
    }

    public function test_recent_rows_are_left_for_the_normal_dispatch(): void
    {
        RosterPipelineFixtures::import(['state' => RosterImport::STATE_CLASSIFIED, 'state_changed_at' => now()->subMinute()]);
        RosterPipelineFixtures::import(['state' => RosterImport::STATE_QUEUED, 'state_changed_at' => now()->subMinute()]);

        $this->reconcile();

        Bus::assertNothingDispatched();
    }

    public function test_a_stuck_queued_row_is_redispatched_but_not_every_minute(): void
    {
        $import = RosterPipelineFixtures::import(['state_changed_at' => now()->subMinutes(5)]);

        $this->reconcile();
        Bus::assertDispatchedTimes(ProcessRosterImport::class, 1);
        self::assertNotNull($import->fresh()->last_dispatched_at);

        $this->travel(1)->minutes();
        $this->reconcile();
        Bus::assertDispatchedTimes(ProcessRosterImport::class, 1);

        $this->travel(2)->minutes();
        $this->reconcile();
        Bus::assertDispatchedTimes(ProcessRosterImport::class, 2);
    }

    public function test_a_crash_while_parsing_is_found_when_the_lease_expires(): void
    {
        $import = RosterPipelineFixtures::import();
        app(RosterStateRecorder::class)->claim($import->fresh());

        $this->reconcile();
        self::assertSame(RosterImport::STATE_PARSING, $import->fresh()->state, 'The lease has not expired yet.');

        $this->travel(301)->seconds();
        $this->reconcile();

        $import = $import->fresh();
        self::assertSame(RosterImport::STATE_RETRYABLE_FAILED, $import->state);
        self::assertSame('lease_expired', $import->error_code);
        self::assertNotNull($import->next_attempt_at);
        self::assertNull($import->lease_expires_at);

        // When the schedule comes due the import reprocesses and publishes.
        $this->travel(2)->minutes();
        $this->reconcile();
        Bus::assertDispatched(ProcessRosterImport::class);
        $this->parserReturns(RosterPipelineFixtures::report(self::DATE, ['generated_at' => '2026-10-30 13:10:00']));
        $this->runProcess($import);
        self::assertSame(RosterImport::STATE_PUBLISHED, $import->fresh()->state);
        self::assertSame(2, (int) $import->fresh()->attempts);
    }

    public function test_an_expired_lease_with_no_attempts_left_fails_for_good(): void
    {
        $import = RosterPipelineFixtures::import(['state' => RosterImport::STATE_PARSING, 'attempts' => 5, 'lease_expires_at' => now()->subMinute()]);

        $this->reconcile();

        self::assertSame(RosterImport::STATE_PERMANENT_FAILED, $import->fresh()->state);
        self::assertSame('lease_expired', $import->fresh()->error_code);
    }

    public function test_a_crash_right_after_validation_is_recovered_too(): void
    {
        $import = RosterPipelineFixtures::import(['state' => RosterImport::STATE_VALIDATED, 'attempts' => 1, 'lease_expires_at' => now()->subMinute()]);

        $this->reconcile();

        self::assertSame(RosterImport::STATE_RETRYABLE_FAILED, $import->fresh()->state);
    }

    public function test_due_retries_are_redispatched_and_future_ones_are_not(): void
    {
        $due = RosterPipelineFixtures::import(['state' => RosterImport::STATE_RETRYABLE_FAILED, 'next_attempt_at' => now()->subMinute()]);
        RosterPipelineFixtures::import(['state' => RosterImport::STATE_RETRYABLE_FAILED, 'next_attempt_at' => now()->addHour()]);

        $this->reconcile();

        Bus::assertDispatchedTimes(ProcessRosterImport::class, 1);
        Bus::assertDispatched(ProcessRosterImport::class, static fn (ProcessRosterImport $job): bool => $job->importId === (int) $due->id);
    }

    public function test_a_crash_between_publish_and_verify_is_verified_by_the_reconciler(): void
    {
        $import = $this->published(RosterPipelineFixtures::report(self::DATE));
        Bus::assertNotDispatched(VerifyRosterImport::class);

        $this->reconcile();
        Bus::assertNotDispatched(VerifyRosterImport::class);

        $this->travel(3)->minutes();
        $this->reconcile();

        Bus::assertDispatched(VerifyRosterImport::class, static fn (VerifyRosterImport $job): bool => $job->importId === (int) $import->id);
        $this->app->call([new VerifyRosterImport((int) $import->id), 'handle']);
        self::assertSame(RosterImport::STATE_VERIFIED, $import->fresh()->state);
        self::assertSame(1, RosterSnapshot::query()->count());
    }

    public function test_pending_cleanup_is_redispatched_only_when_enabled(): void
    {
        $verified = RosterPipelineFixtures::import(['state' => RosterImport::STATE_VERIFIED]);
        $partly = RosterPipelineFixtures::import(['state' => RosterImport::STATE_DUPLICATE, 'cleanup_state' => RosterImport::CLEANUP_ROW_DELETED]);
        $stored = RosterPipelineFixtures::import(['state' => RosterImport::STATE_SUPERSEDED, 'roster_snapshot_id' => 1]);
        // None of these can be cleaned: a held export's superseded row has no snapshot, the rest are settled.
        RosterPipelineFixtures::import(['state' => RosterImport::STATE_SUPERSEDED]);
        RosterPipelineFixtures::import(['state' => RosterImport::STATE_VERIFIED, 'cleanup_state' => RosterImport::CLEANUP_KEPT_REPLIED]);
        RosterPipelineFixtures::import(['state' => RosterImport::STATE_VERIFIED, 'cleanup_state' => RosterImport::CLEANUP_FAILED]);
        RosterPipelineFixtures::import(['state' => RosterImport::STATE_CLEANED, 'cleanup_state' => RosterImport::CLEANUP_DONE]);

        $this->reconcile();
        Bus::assertNotDispatched(CleanupRosterImport::class);

        config(['daily_roster.cleanup_enabled' => true]);
        $this->reconcile();

        $ids = [];
        Bus::assertDispatched(CleanupRosterImport::class, static function (CleanupRosterImport $job) use (&$ids): bool {
            $ids[] = $job->importId;

            return true;
        });
        sort($ids);
        self::assertSame([(int) $verified->id, (int) $partly->id, (int) $stored->id], $ids);
    }

    public function test_cleanup_is_not_redispatched_in_shadow_mode(): void
    {
        config(['daily_roster.mode' => 'shadow', 'daily_roster.cleanup_enabled' => true]);
        RosterPipelineFixtures::import(['state' => RosterImport::STATE_VERIFIED]);

        $this->reconcile();

        Bus::assertNotDispatched(CleanupRosterImport::class);
    }

    public function test_a_missing_roster_past_the_cutoff_alerts_once_per_duty_date_and_writes_no_roster(): void
    {
        // 09:59 Eastern: not late yet.
        $this->travelTo(CarbonImmutable::parse('2026-10-30 09:59:00', HubTimezone::NAME));
        $this->reconcile();
        Exceptions::assertNothingReported();
        self::assertSame(0, RosterEvent::query()->count());

        $this->travelTo(CarbonImmutable::parse('2026-10-30 10:00:00', HubTimezone::NAME));
        $this->reconcile();
        $this->travel(1)->minutes();
        $this->reconcile();

        $event = RosterEvent::query()->where('action', RosterStateRecorder::ACTION_DELAYED)->sole();
        self::assertSame(self::DATE, $event->duty_date->format('Y-m-d'));
        Exceptions::assertReportedCount(1);
        Exceptions::assertReported(static fn (RosterPipelineException $exception): bool => $exception->errorCode === RosterStateRecorder::ACTION_DELAYED);
        self::assertSame(0, RosterSnapshot::query()->count());
        self::assertSame(0, RosterActiveVersion::query()->count());

        // Still the 30th's duty day at 07:30 on the 31st, so no second alert. The next day raises its own.
        $this->travelTo(CarbonImmutable::parse('2026-10-31 07:30:00', HubTimezone::NAME));
        $this->reconcile();
        self::assertSame(1, RosterEvent::query()->where('action', RosterStateRecorder::ACTION_DELAYED)->count());

        $this->travelTo(CarbonImmutable::parse('2026-10-31 10:30:00', HubTimezone::NAME));
        $this->reconcile();
        self::assertSame(2, RosterEvent::query()->where('action', RosterStateRecorder::ACTION_DELAYED)->count());
    }

    public function test_a_published_roster_raises_no_late_alert(): void
    {
        $this->published(RosterPipelineFixtures::report(self::DATE));
        $this->travelTo(CarbonImmutable::parse('2026-10-30 18:00:00', HubTimezone::NAME));

        $this->reconcile();

        self::assertSame(0, RosterEvent::query()->where('action', RosterStateRecorder::ACTION_DELAYED)->count());
    }

    public function test_shadow_mode_raises_no_late_alert(): void
    {
        config(['daily_roster.mode' => 'shadow']);
        $this->travelTo(CarbonImmutable::parse('2026-10-30 18:00:00', HubTimezone::NAME));

        $this->reconcile();

        Exceptions::assertNothingReported();
        self::assertSame(0, RosterEvent::query()->count());
    }

    public function test_a_backlog_is_worked_in_bounded_batches(): void
    {
        for ($index = 0; $index < 105; $index++) {
            RosterPipelineFixtures::import(['state_changed_at' => now()->subHour()]);
        }

        $this->reconcile();
        Bus::assertDispatchedTimes(ProcessRosterImport::class, 100);

        $this->reconcile();
        Bus::assertDispatchedTimes(ProcessRosterImport::class, 105);
    }

    public function test_it_logs_counts_and_nothing_else(): void
    {
        $logged = [];
        Event::listen(MessageLogged::class, static function (MessageLogged $message) use (&$logged): void {
            $logged[] = [$message->message, $message->context];
        });
        RosterPipelineFixtures::import(['state_changed_at' => now()->subHour()]);

        $this->reconcile();

        $entry = collect($logged)->firstWhere(0, 'daily_roster.reconcile');
        self::assertNotNull($entry);
        self::assertSame(1, $entry[1]['stuck_dispatches']);
        foreach ($entry[1] as $value) {
            self::assertIsInt($value);
        }
    }

    private function reconcile(): void
    {
        $this->artisan('daily-roster:reconcile')->assertExitCode(0);
    }

    private function published(TeleStaffRosterReport $report): RosterImport
    {
        $import = RosterPipelineFixtures::import([
            'state' => RosterImport::STATE_VALIDATED,
            'duty_date' => $report->dutyDate,
            'source_generated_at' => $report->generatedAt,
        ]);
        app(RosterPublisher::class)->publish($import, $report);

        return $import->fresh();
    }
}
