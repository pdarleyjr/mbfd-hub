<?php

declare(strict_types=1);

namespace Tests\Feature\DailyRoster\Pipeline;

use App\Jobs\DailyRoster\CleanupRosterImport;
use App\Jobs\DailyRoster\VerifyRosterImport;
use App\Models\DailyRoster\RosterImport;
use App\Services\DailyRoster\CurrentRosterReader;
use App\Services\DailyRoster\Parsing\TeleStaffRosterReport;
use App\Services\DailyRoster\RosterPipelineException;
use App\Services\DailyRoster\RosterPublicationVerifier;
use App\Services\DailyRoster\RosterPublisher;
use App\Services\DailyRoster\RosterStateRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\DailyRoster\Pipeline\Support\InteractsWithRosterPipeline;
use Tests\Feature\DailyRoster\Pipeline\Support\RosterPipelineFixtures;
use Tests\TestCase;

final class RosterPublicationVerifierTest extends TestCase
{
    use InteractsWithRosterPipeline, RefreshDatabase;

    private const DATE = '2026-10-30';

    protected function setUp(): void
    {
        parent::setUp();
        $this->preparePipeline();
    }

    public function test_a_faithful_publish_verifies(): void
    {
        $import = $this->published(RosterPipelineFixtures::report(self::DATE));

        self::assertTrue($this->verify($import));

        $import = $import->fresh();
        self::assertSame(RosterImport::STATE_VERIFIED, $import->state);
        self::assertNotNull($import->verified_at);
        Bus::assertNotDispatched(CleanupRosterImport::class);
        Exceptions::assertNothingReported();
    }

    public function test_verifying_queues_cleanup_when_enabled(): void
    {
        config(['daily_roster.cleanup_enabled' => true]);
        $import = $this->published(RosterPipelineFixtures::report(self::DATE));

        self::assertTrue($this->verify($import));

        Bus::assertDispatched(CleanupRosterImport::class, static fn (CleanupRosterImport $job): bool => $job->importId === (int) $import->id && $job->afterCommit === true);
    }

    public function test_the_job_runs_the_verifier(): void
    {
        $import = $this->published(RosterPipelineFixtures::report(self::DATE));

        $this->app->call([new VerifyRosterImport((int) $import->id), 'handle']);

        self::assertSame(RosterImport::STATE_VERIFIED, $import->fresh()->state);
    }

    public function test_it_can_run_again_safely(): void
    {
        config(['daily_roster.cleanup_enabled' => true]);
        $import = $this->published(RosterPipelineFixtures::report(self::DATE));

        self::assertTrue($this->verify($import));
        $verifiedAt = $import->fresh()->verified_at;
        $this->travel(5)->minutes();
        self::assertTrue($this->verify($import));

        self::assertTrue($import->fresh()->verified_at->equalTo($verifiedAt));
        Bus::assertDispatchedTimes(CleanupRosterImport::class, 1);
    }

    public function test_the_hash_rebuilt_from_rows_equals_the_export_hash(): void
    {
        $report = RosterPipelineFixtures::report(self::DATE);
        $this->published($report);

        $view = app(CurrentRosterReader::class)->forDutyDate(self::DATE);

        self::assertSame($report->normalizedSha256(), RosterPublicationVerifier::normalizedHash(self::DATE, $report->shift, $view->sections));
    }

    public function test_a_newer_export_that_already_won_still_verifies_the_older_publish(): void
    {
        $older = $this->published(RosterPipelineFixtures::report(self::DATE, ['generated_at' => '2026-10-30 13:10:00']));
        $newer = $this->published(RosterPipelineFixtures::report(self::DATE, ['generated_at' => '2026-10-30 13:20:00', 'variant' => 'B']));

        self::assertTrue($this->verify($older));
        self::assertTrue($this->verify($newer));
        self::assertSame((int) $newer->roster_snapshot_id, (int) $this->pointerFor(self::DATE)->roster_snapshot_id);
    }

    public function test_changed_row_text_fails_verification_and_keeps_the_raw_file(): void
    {
        config(['daily_roster.cleanup_enabled' => true]);
        $import = $this->published(RosterPipelineFixtures::report(self::DATE));
        DB::table('roster_assignments')->where('employee_id', '90001')->update(['raw_name' => 'TESTPERSON, TAMPERED']);

        self::assertFalse($this->verify($import));

        $import = $import->fresh();
        self::assertSame(RosterImport::STATE_NEEDS_REVIEW, $import->state);
        self::assertSame(RosterPublicationVerifier::HASH_MISMATCH, $import->error_code);
        Storage::disk('local')->assertExists($import->attachment_path);
        Bus::assertNotDispatched(CleanupRosterImport::class);
        Exceptions::assertReported(static fn (RosterPipelineException $exception): bool => $exception->errorCode === RosterPublicationVerifier::HASH_MISMATCH);
    }

    public function test_missing_rows_fail_on_the_count(): void
    {
        $import = $this->published(RosterPipelineFixtures::report(self::DATE));
        DB::table('roster_assignments')->where('employee_id', '90001')->delete();

        self::assertFalse($this->verify($import));
        self::assertSame(RosterPublicationVerifier::COUNT_MISMATCH, $import->fresh()->error_code);
    }

    public function test_a_pointer_at_something_older_fails(): void
    {
        $older = $this->published(RosterPipelineFixtures::report(self::DATE, ['generated_at' => '2026-10-30 13:10:00']));
        $newer = $this->published(RosterPipelineFixtures::report(self::DATE, ['generated_at' => '2026-10-30 13:20:00', 'variant' => 'B']));
        DB::table('roster_active_versions')->update([
            'roster_snapshot_id' => $older->roster_snapshot_id,
            'effective_generated_at' => '2026-10-30 13:10:00',
        ]);

        self::assertFalse($this->verify($newer));
        self::assertSame(RosterPublicationVerifier::POINTER_MISMATCH, $newer->fresh()->error_code);
    }

    public function test_a_missing_pointer_fails(): void
    {
        $import = $this->published(RosterPipelineFixtures::report(self::DATE));
        DB::table('roster_active_versions')->delete();

        self::assertFalse($this->verify($import));
        self::assertSame(RosterPublicationVerifier::POINTER_MISSING, $import->fresh()->error_code);
    }

    public function test_a_shift_the_calendar_disagrees_with_fails(): void
    {
        $import = $this->published(RosterPipelineFixtures::report(self::DATE));
        DB::table('roster_snapshots')->update(['shift_letter' => 'Z']);

        self::assertFalse($this->verify($import));
        self::assertSame(RosterPublicationVerifier::WINDOW_MISMATCH, $import->fresh()->error_code);
    }

    public function test_a_window_the_calendar_disagrees_with_fails(): void
    {
        $import = $this->published(RosterPipelineFixtures::report(self::DATE));
        DB::table('roster_snapshots')->update(['ends_at' => '2026-10-31 13:00:00']);

        self::assertFalse($this->verify($import));
        self::assertSame(RosterPublicationVerifier::WINDOW_MISMATCH, $import->fresh()->error_code);
    }

    public function test_a_publish_with_no_audit_event_fails(): void
    {
        $import = $this->published(RosterPipelineFixtures::report(self::DATE));
        DB::table('roster_events')->where('action', RosterStateRecorder::ACTION_POINTER_MOVED)->delete();

        self::assertFalse($this->verify($import));
        self::assertSame(RosterPublicationVerifier::EVENT_MISSING, $import->fresh()->error_code);
    }

    public function test_a_row_not_in_published_state_is_left_alone(): void
    {
        $held = RosterPipelineFixtures::import(['state' => RosterImport::STATE_NEEDS_REVIEW]);

        self::assertFalse($this->verify($held));
        self::assertSame(RosterImport::STATE_NEEDS_REVIEW, $held->fresh()->state);
    }

    private function verify(RosterImport $import): bool
    {
        return app(RosterPublicationVerifier::class)->verify($import);
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
