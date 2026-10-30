<?php

declare(strict_types=1);

namespace Tests\Feature\DailyRoster\Pipeline;

use App\Jobs\DailyRoster\CleanupRosterImport;
use App\Jobs\DailyRoster\ProcessRosterImport;
use App\Jobs\DailyRoster\VerifyRosterImport;
use App\Models\DailyRoster\RosterActiveVersion;
use App\Models\DailyRoster\RosterAssignment;
use App\Models\DailyRoster\RosterEvent;
use App\Models\DailyRoster\RosterImport;
use App\Models\DailyRoster\RosterSnapshot;
use App\Services\DailyRoster\CurrentRosterReader;
use App\Services\DailyRoster\Parsing\TeleStaffWorkbookException;
use App\Services\DailyRoster\RosterAnomalyGuard;
use App\Services\DailyRoster\RosterPipelineException;
use App\Services\DailyRoster\RosterPublisher;
use App\Support\Captain5\DutyCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Feature\DailyRoster\Pipeline\Support\InteractsWithRosterPipeline;
use Tests\Feature\DailyRoster\Pipeline\Support\RosterPipelineFixtures;
use Tests\TestCase;

final class ProcessRosterImportTest extends TestCase
{
    use InteractsWithRosterPipeline, RefreshDatabase;

    private const DATE = '2026-10-30';

    protected function setUp(): void
    {
        parent::setUp();
        $this->preparePipeline();
    }

    public function test_a_clean_export_publishes_and_queues_verification(): void
    {
        $report = RosterPipelineFixtures::report(self::DATE, ['warnings' => ['overlap_row_31']]);
        $import = $this->importExport($report);

        self::assertSame(RosterImport::STATE_PUBLISHED, $import->state);
        self::assertSame(RosterImport::OUTCOME_PUBLISHED, $import->outcome);
        self::assertSame(1, (int) $import->attempts);
        self::assertNull($import->lease_expires_at);
        self::assertNull($import->error_code);
        self::assertNotNull($import->published_at);
        self::assertSame(self::DATE, $import->duty_date->format('Y-m-d'));
        self::assertSame($report->shift, $import->shift_letter);
        self::assertSame('test-parser', $import->parser_version);
        self::assertSame($report->normalizedSha256(), $import->normalized_sha256);
        self::assertSame(RosterPipelineFixtures::FINGERPRINT, $import->layout_fingerprint);
        self::assertSame(4, (int) $import->section_count);
        self::assertSame(6 + 1, (int) $import->assignment_count);
        self::assertSame(6, (int) $import->unique_employee_count);
        self::assertSame(['overlap_row_31'], $import->warnings);
        self::assertSame([['title_row' => 120, 'division' => 'Administration', 'shift_label' => 'Days', 'section_count' => 2, 'entry_count' => 5]], $import->other_blocks);
        self::assertTrue($import->source_generated_at->equalTo($report->generatedAt));

        self::assertSame((int) $import->roster_snapshot_id, (int) $this->pointerFor(self::DATE)->roster_snapshot_id);
        Bus::assertDispatched(VerifyRosterImport::class, static fn (VerifyRosterImport $job): bool => $job->importId === (int) $import->id && $job->afterCommit === true);
    }

    public function test_mode_off_does_nothing(): void
    {
        config(['daily_roster.mode' => 'off']);
        $parser = $this->parserReturns(RosterPipelineFixtures::report(self::DATE));
        $import = RosterPipelineFixtures::import();

        $this->runProcess($import);

        self::assertSame(RosterImport::STATE_QUEUED, $import->fresh()->state);
        self::assertSame(0, $parser->calls);
        self::assertSame(0, RosterEvent::query()->count());
    }

    public function test_shadow_mode_parses_and_records_hold_reasons_without_publishing_anything(): void
    {
        config(['daily_roster.mode' => 'shadow', 'daily_roster.cleanup_enabled' => true]);
        $import = $this->importExport(RosterPipelineFixtures::report(self::DATE, ['shift' => 'Z']));

        self::assertSame(RosterImport::STATE_SHADOWED, $import->state);
        self::assertContains('hold:'.RosterAnomalyGuard::SHIFT_MISMATCH, $import->warnings);
        self::assertSame(0, RosterSnapshot::query()->count());
        self::assertSame(0, RosterActiveVersion::query()->count());
        self::assertSame(0, RosterAssignment::query()->count());
        Storage::disk('local')->assertExists($import->attachment_path);
        Bus::assertNotDispatched(CleanupRosterImport::class);
        Bus::assertNotDispatched(VerifyRosterImport::class);
        Exceptions::assertNothingReported();
    }

    public function test_a_clean_shadow_run_records_no_holds(): void
    {
        config(['daily_roster.mode' => 'shadow']);
        $import = $this->importExport(RosterPipelineFixtures::report(self::DATE));

        self::assertSame(RosterImport::STATE_SHADOWED, $import->state);
        self::assertSame([], $import->warnings);
    }

    public function test_the_same_message_delivered_twice_has_no_second_effect(): void
    {
        $import = RosterPipelineFixtures::import();
        $parser = $this->parserReturns(RosterPipelineFixtures::report(self::DATE));

        $this->runProcess($import);
        $this->runProcess($import);

        self::assertSame(1, $parser->calls);
        self::assertSame(1, RosterSnapshot::query()->count());
        self::assertSame(RosterImport::STATE_PUBLISHED, $import->fresh()->state);
        self::assertSame(1, (int) $import->fresh()->attempts);
    }

    public function test_the_same_workbook_under_a_different_message_id_is_a_duplicate_without_parsing(): void
    {
        $first = $this->importExport(RosterPipelineFixtures::report(self::DATE), ['bytes' => 'PKsame-workbook']);
        self::assertSame(RosterImport::STATE_PUBLISHED, $first->state, (string) $first->error_code);

        config(['daily_roster.cleanup_enabled' => true]);
        $second = RosterPipelineFixtures::import(['bytes' => 'PKsame-workbook']);
        $parser = $this->parserReturns();
        $this->runProcess($second);
        $second = $second->fresh();

        self::assertSame(0, $parser->calls);
        self::assertSame(RosterImport::STATE_DUPLICATE, $second->state);
        self::assertSame(RosterImport::OUTCOME_DUPLICATE, $second->outcome);
        self::assertSame((int) $first->id, (int) $second->duplicate_of_import_id);
        self::assertSame(1, RosterSnapshot::query()->count());
        Bus::assertDispatched(CleanupRosterImport::class, static fn (CleanupRosterImport $job): bool => $job->importId === (int) $second->id);
    }

    public function test_a_duplicate_of_a_duplicate_points_at_the_import_that_took_effect(): void
    {
        $first = $this->importExport(RosterPipelineFixtures::report(self::DATE), ['bytes' => 'PKchain']);
        $this->runProcess($second = RosterPipelineFixtures::import(['bytes' => 'PKchain']));
        $this->runProcess($third = RosterPipelineFixtures::import(['bytes' => 'PKchain']));

        self::assertSame((int) $first->id, (int) $second->fresh()->duplicate_of_import_id);
        self::assertSame((int) $first->id, (int) $third->fresh()->duplicate_of_import_id);
    }

    public function test_a_held_import_does_not_count_as_live_for_the_same_workbook(): void
    {
        $this->importExport(RosterPipelineFixtures::report(self::DATE, ['shift' => 'Z']), ['bytes' => 'PKheld']);

        $retried = $this->importExport(RosterPipelineFixtures::report(self::DATE), ['bytes' => 'PKheld']);

        self::assertSame(RosterImport::STATE_PUBLISHED, $retried->state);
    }

    public function test_a_changed_export_for_the_same_date_becomes_the_next_version(): void
    {
        $first = $this->importExport(RosterPipelineFixtures::report(self::DATE));
        [$start] = app(DutyCalendar::class)->window(self::DATE);
        $second = $this->importExport(RosterPipelineFixtures::report(self::DATE, ['generated_at' => $start->addMinutes(90), 'variant' => 'B']));

        self::assertSame(RosterImport::STATE_PUBLISHED, $second->state);
        self::assertSame(2, (int) $second->snapshot->version);
        self::assertSame((int) $second->roster_snapshot_id, (int) $this->pointerFor(self::DATE)->roster_snapshot_id);
        self::assertTrue(RosterSnapshot::query()->whereKey($first->roster_snapshot_id)->exists());
    }

    public function test_an_old_revision_arriving_after_a_new_one_is_stored_but_never_shown(): void
    {
        [$start] = app(DutyCalendar::class)->window(self::DATE);
        $newer = $this->importExport(RosterPipelineFixtures::report(self::DATE, ['generated_at' => $start->addMinutes(90), 'variant' => 'NEW']));
        $older = $this->importExport(RosterPipelineFixtures::report(self::DATE, ['generated_at' => $start->addMinutes(30), 'variant' => 'OLD']));

        self::assertSame(RosterImport::STATE_SUPERSEDED, $older->state);
        self::assertSame(RosterImport::OUTCOME_SUPERSEDED, $older->outcome);
        self::assertSame(2, (int) $older->snapshot->version);
        self::assertSame((int) $newer->id, (int) $older->duplicate_of_import_id);

        $pointer = $this->pointerFor(self::DATE);
        self::assertSame((int) $newer->roster_snapshot_id, (int) $pointer->roster_snapshot_id);
        self::assertTrue($pointer->effective_generated_at->equalTo($start->addMinutes(90)));
        Bus::assertDispatchedTimes(VerifyRosterImport::class, 1);
    }

    public function test_equal_export_time_with_different_content_holds_for_review(): void
    {
        $generated = CarbonImmutable::parse('2026-10-30 13:14:15', 'UTC');
        $first = $this->importExport(RosterPipelineFixtures::report(self::DATE, ['generated_at' => $generated]));
        $held = $this->importExport(RosterPipelineFixtures::report(self::DATE, ['generated_at' => $generated, 'variant' => 'B']));

        self::assertSame(RosterImport::STATE_NEEDS_REVIEW, $held->state);
        self::assertSame(RosterPublisher::HOLD_GENERATED_CONFLICT, $held->error_code);
        self::assertSame((int) $first->roster_snapshot_id, (int) $this->pointerFor(self::DATE)->roster_snapshot_id);
        Storage::disk('local')->assertExists($held->attachment_path);
        Exceptions::assertReported(static fn (RosterPipelineException $exception): bool => $exception->errorCode === RosterPublisher::HOLD_GENERATED_CONFLICT);
    }

    public function test_a_missing_source_file_fails_permanently(): void
    {
        $import = RosterPipelineFixtures::import();
        Storage::disk('local')->delete($import->attachment_path);
        $parser = $this->parserReturns();

        $this->runProcess($import);

        self::assertSame(RosterImport::STATE_PERMANENT_FAILED, $import->fresh()->state);
        self::assertSame('source_file_missing', $import->fresh()->error_code);
        self::assertSame(0, $parser->calls);
    }

    public function test_a_source_file_that_changed_since_receipt_fails_permanently(): void
    {
        $import = RosterPipelineFixtures::import();
        Storage::disk('local')->put($import->attachment_path, 'PKtampered');
        $parser = $this->parserReturns();

        $this->runProcess($import);

        self::assertSame(RosterImport::STATE_PERMANENT_FAILED, $import->fresh()->state);
        self::assertSame('source_hash_mismatch', $import->fresh()->error_code);
        self::assertSame(0, $parser->calls);
    }

    public function test_a_workbook_that_fails_closed_is_permanent_and_keeps_the_previous_roster(): void
    {
        $good = $this->importExport(RosterPipelineFixtures::report(self::DATE));
        $import = RosterPipelineFixtures::import();
        $this->parserReturns(new TeleStaffWorkbookException('hours_mismatch', 44));
        $this->runProcess($import);

        self::assertSame(RosterImport::STATE_PERMANENT_FAILED, $import->fresh()->state);
        self::assertSame('hours_mismatch', $import->fresh()->error_code);
        self::assertNull($import->fresh()->next_attempt_at);
        self::assertSame((int) $good->roster_snapshot_id, (int) $this->pointerFor(self::DATE)->roster_snapshot_id);
        self::assertNotNull(app(CurrentRosterReader::class)->current());
        Exceptions::assertReported(static fn (RosterPipelineException $exception): bool => $exception->errorCode === 'hours_mismatch');
    }

    public function test_a_read_failure_retries_on_the_ledger_schedule_with_jitter(): void
    {
        $import = RosterPipelineFixtures::import();
        $this->parserReturns(new TeleStaffWorkbookException('read_failed'));

        $this->runProcess($import);
        $import = $import->fresh();

        self::assertSame(RosterImport::STATE_RETRYABLE_FAILED, $import->state);
        self::assertSame('read_failed', $import->error_code);
        self::assertNull($import->lease_expires_at);
        // First backoff is one minute, plus or minus 20 percent.
        $wait = now()->diffInSeconds($import->next_attempt_at);
        self::assertGreaterThanOrEqual(48, $wait);
        self::assertLessThanOrEqual(72, $wait);
        Bus::assertNotDispatched(ProcessRosterImport::class);
    }

    public function test_an_unexpected_error_retries_and_reports_only_a_code(): void
    {
        $import = RosterPipelineFixtures::import();
        $this->parserReturns(new RuntimeException('cell text '.RosterPipelineFixtures::FAKE_NAMES[0]));

        $this->runProcess($import);

        self::assertSame(RosterImport::STATE_RETRYABLE_FAILED, $import->fresh()->state);
        self::assertSame('unexpected_error', $import->fresh()->error_code);
        Exceptions::assertReported(static fn (RosterPipelineException $exception): bool => $exception->getMessage() === 'process_unexpected_RuntimeException');
        Exceptions::assertNotReported(RuntimeException::class);
    }

    public function test_retries_stop_at_the_attempt_limit_and_become_permanent(): void
    {
        $import = RosterPipelineFixtures::import(['attempts' => 4]);
        $this->parserReturns(new TeleStaffWorkbookException('read_failed'));

        $this->runProcess($import);

        self::assertSame(5, (int) $import->fresh()->attempts);
        self::assertSame(RosterImport::STATE_PERMANENT_FAILED, $import->fresh()->state);
        self::assertSame('read_failed', $import->fresh()->error_code);
        self::assertNull($import->fresh()->next_attempt_at);
        Exceptions::assertReported(static fn (RosterPipelineException $exception): bool => $exception->errorCode === 'read_failed');
    }

    public function test_a_retry_only_runs_once_it_is_due(): void
    {
        $import = RosterPipelineFixtures::import(['state' => RosterImport::STATE_RETRYABLE_FAILED, 'next_attempt_at' => now()->addMinutes(5), 'attempts' => 1]);
        $parser = $this->parserReturns(RosterPipelineFixtures::report(self::DATE));

        $this->runProcess($import);
        self::assertSame(0, $parser->calls);
        self::assertSame(RosterImport::STATE_RETRYABLE_FAILED, $import->fresh()->state);

        $this->travel(6)->minutes();
        $this->runProcess($import);
        self::assertSame(1, $parser->calls);
        self::assertSame(RosterImport::STATE_PUBLISHED, $import->fresh()->state);
        self::assertSame(2, (int) $import->fresh()->attempts);
    }

    public function test_a_held_anomaly_keeps_the_raw_file_and_reports_the_first_reason(): void
    {
        $import = $this->importExport(RosterPipelineFixtures::report(self::DATE, ['shift' => 'Z']));

        self::assertSame(RosterImport::STATE_NEEDS_REVIEW, $import->state);
        self::assertSame(RosterAnomalyGuard::SHIFT_MISMATCH, $import->error_code);
        self::assertContains('hold:'.RosterAnomalyGuard::SHIFT_MISMATCH, $import->warnings);
        Storage::disk('local')->assertExists($import->attachment_path);
        self::assertSame(0, RosterSnapshot::query()->count());
        Exceptions::assertReported(static fn (RosterPipelineException $exception): bool => $exception->errorCode === RosterAnomalyGuard::SHIFT_MISMATCH);
    }

    public function test_a_job_that_finds_a_classified_row_moves_it_along(): void
    {
        $import = RosterPipelineFixtures::import(['state' => RosterImport::STATE_CLASSIFIED]);
        $this->parserReturns(RosterPipelineFixtures::report(self::DATE));

        $this->runProcess($import);

        self::assertSame(RosterImport::STATE_PUBLISHED, $import->fresh()->state);
    }

    public function test_a_publish_fault_retries_and_leaves_no_trace_on_the_pointer(): void
    {
        $import = RosterPipelineFixtures::import();
        $this->parserReturns(RosterPipelineFixtures::report(self::DATE));
        DB::listen(static function ($query): void {
            if (str_contains($query->sql, 'insert into "roster_assignments"')) {
                throw new RuntimeException('disk full');
            }
        });

        $this->runProcess($import);

        self::assertSame(RosterImport::STATE_RETRYABLE_FAILED, $import->fresh()->state);
        self::assertNull($this->pointerFor(self::DATE));
        self::assertSame(0, RosterSnapshot::query()->count());
    }

    public function test_events_and_logs_carry_no_names_or_ids_through_success_failure_and_hold(): void
    {
        $logged = [];
        Event::listen(MessageLogged::class, static function (MessageLogged $message) use (&$logged): void {
            $logged[] = $message->message.' '.json_encode($message->context);
        });

        $this->importExport(RosterPipelineFixtures::report(self::DATE));
        $this->importExport(RosterPipelineFixtures::report(self::DATE, ['shift' => 'Z', 'variant' => 'HELD']));
        $this->parserReturns(new RuntimeException('cell text '.RosterPipelineFixtures::FAKE_NAMES[0]));
        $this->runProcess(RosterPipelineFixtures::import());

        $haystack = json_encode([
            RosterEvent::query()->get(['action', 'reason', 'context', 'from_state', 'to_state'])->toArray(),
            RosterImport::query()->get(['warnings', 'other_blocks', 'error_code', 'classification'])->toArray(),
            $logged,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        self::assertGreaterThan(5, RosterEvent::query()->count());
        foreach ([...RosterPipelineFixtures::FAKE_NAMES, ...RosterPipelineFixtures::FAKE_IDS, 'TESTPERSON', 'VARIANT'] as $needle) {
            self::assertStringNotContainsString($needle, $haystack);
        }
    }

    public function test_the_pipeline_never_updates_or_deletes_immutable_rows(): void
    {
        $writes = [];
        DB::listen(static function ($query) use (&$writes): void {
            $writes[] = $query->sql;
        });

        $first = $this->importExport(RosterPipelineFixtures::report(self::DATE));
        $this->app->call([new VerifyRosterImport((int) $first->id), 'handle']);
        $this->importExport(RosterPipelineFixtures::report(self::DATE, ['variant' => 'B', 'generated_at' => '2026-10-30 13:25:00']));
        $this->importExport(RosterPipelineFixtures::report(self::DATE, ['variant' => 'C', 'generated_at' => '2026-10-30 13:00:00']));

        $tables = 'roster_snapshots|roster_sections|roster_assignments|roster_events';
        foreach ($writes as $sql) {
            self::assertDoesNotMatchRegularExpression('/^(update|delete from) "('.$tables.')"/i', $sql);
        }
        self::assertTrue(RosterAssignment::query()->exists());
    }
}
