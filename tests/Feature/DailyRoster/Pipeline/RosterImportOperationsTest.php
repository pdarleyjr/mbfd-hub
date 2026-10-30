<?php

declare(strict_types=1);

namespace Tests\Feature\DailyRoster\Pipeline;

use App\Jobs\DailyRoster\CleanupRosterImport;
use App\Jobs\DailyRoster\ProcessRosterImport;
use App\Jobs\DailyRoster\VerifyRosterImport;
use App\Models\DailyRoster\RosterEvent;
use App\Models\DailyRoster\RosterImport;
use App\Models\User;
use App\Services\DailyRoster\RosterImportOperations;
use App\Services\DailyRoster\RosterPipelineException;
use App\Services\DailyRoster\RosterPublicationVerifier;
use App\Services\DailyRoster\RosterPublisher;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Container\Container;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Tests\Feature\DailyRoster\Pipeline\Support\InteractsWithRosterPipeline;
use Tests\Feature\DailyRoster\Pipeline\Support\RosterPipelineFixtures;
use Tests\TestCase;

final class RosterImportOperationsTest extends TestCase
{
    use InteractsWithRosterPipeline, RefreshDatabase;

    private const DATE = '2026-10-30';

    protected function setUp(): void
    {
        parent::setUp();
        $this->preparePipeline();
    }

    /** @return array<string, array{0: string}> */
    public static function retryableStates(): array
    {
        return [
            'retryable' => [RosterImport::STATE_RETRYABLE_FAILED],
            'permanent' => [RosterImport::STATE_PERMANENT_FAILED],
            'held' => [RosterImport::STATE_NEEDS_REVIEW],
            'shadowed' => [RosterImport::STATE_SHADOWED],
        ];
    }

    #[DataProvider('retryableStates')]
    public function test_a_failed_held_or_shadowed_import_goes_back_through_the_pipeline(string $state): void
    {
        $import = RosterPipelineFixtures::import(['state' => $state, 'attempts' => 5, 'error_code' => 'hours_mismatch', 'next_attempt_at' => now()->addHour()]);
        $admin = $this->admin();
        $operations = app(RosterImportOperations::class);

        self::assertTrue($operations->canRetry($import));
        $operations->retry($import, $admin);

        $import = $import->fresh();
        self::assertSame(RosterImport::STATE_QUEUED, $import->state);
        self::assertSame(0, (int) $import->attempts);
        self::assertNull($import->error_code);
        self::assertNull($import->next_attempt_at);
        self::assertNotNull($import->last_dispatched_at);
        Bus::assertDispatched(ProcessRosterImport::class, static fn (ProcessRosterImport $job): bool => $job->importId === (int) $import->id && $job->afterCommit === true);

        $event = RosterEvent::query()->where('roster_import_id', $import->id)->latest('id')->first();
        self::assertSame('manual_retry', $event->reason);
        self::assertSame((int) $admin->id, (int) $event->actor_user_id);
        self::assertSame($state, $event->from_state);
    }

    public function test_a_retried_import_then_publishes(): void
    {
        $import = RosterPipelineFixtures::import(['state' => RosterImport::STATE_PERMANENT_FAILED, 'error_code' => 'hours_mismatch', 'attempts' => 1]);
        app(RosterImportOperations::class)->retry($import, $this->admin());

        $this->parserReturns(RosterPipelineFixtures::report(self::DATE));
        $this->runProcess($import);

        self::assertSame(RosterImport::STATE_PUBLISHED, $import->fresh()->state);
        self::assertSame(1, (int) $import->fresh()->attempts);
    }

    #[DataProvider('unretryableStates')]
    public function test_other_states_cannot_be_retried(string $state): void
    {
        $import = RosterPipelineFixtures::import(['state' => $state]);
        $operations = app(RosterImportOperations::class);

        self::assertFalse($operations->canRetry($import));
        $this->expectException(RosterPipelineException::class);
        $this->expectExceptionMessage('retry_not_allowed');
        $operations->retry($import, $this->admin());
    }

    /** @return array<string, array{0: string}> */
    public static function unretryableStates(): array
    {
        return [
            'queued' => [RosterImport::STATE_QUEUED],
            'parsing' => [RosterImport::STATE_PARSING],
            'published' => [RosterImport::STATE_PUBLISHED],
            'verified' => [RosterImport::STATE_VERIFIED],
            'cleaned' => [RosterImport::STATE_CLEANED],
            'duplicate' => [RosterImport::STATE_DUPLICATE],
            'rejected' => [RosterImport::STATE_REJECTED],
        ];
    }

    public function test_a_missing_source_file_cannot_be_retried(): void
    {
        $import = RosterPipelineFixtures::import(['state' => RosterImport::STATE_PERMANENT_FAILED]);
        Storage::disk('local')->delete($import->attachment_path);

        self::assertFalse(app(RosterImportOperations::class)->canRetry($import));
    }

    public function test_an_import_with_no_attachment_cannot_be_retried(): void
    {
        $import = RosterPipelineFixtures::import(['state' => RosterImport::STATE_NEEDS_REVIEW, 'attachment_path' => null, 'attachment_disk' => null]);

        self::assertFalse(app(RosterImportOperations::class)->canRetry($import));
    }

    public function test_nothing_can_be_retried_while_the_pipeline_is_off(): void
    {
        config(['daily_roster.mode' => 'off']);

        self::assertFalse(app(RosterImportOperations::class)->canRetry(RosterPipelineFixtures::import(['state' => RosterImport::STATE_PERMANENT_FAILED])));
    }

    public function test_a_member_without_system_manage_is_refused(): void
    {
        $import = RosterPipelineFixtures::import(['state' => RosterImport::STATE_PERMANENT_FAILED]);

        $this->expectException(AuthorizationException::class);
        app(RosterImportOperations::class)->retry($import, User::factory()->create());
    }

    public function test_a_null_actor_is_the_system_in_a_console_process(): void
    {
        $import = RosterPipelineFixtures::import(['state' => RosterImport::STATE_PERMANENT_FAILED]);

        app(RosterImportOperations::class)->retry($import, null);

        self::assertSame(RosterImport::STATE_QUEUED, $import->fresh()->state);
        self::assertNull(RosterEvent::query()->where('roster_import_id', $import->id)->latest('id')->first()->actor_user_id);
    }

    public function test_a_null_actor_is_refused_during_a_web_request(): void
    {
        $import = RosterPipelineFixtures::import(['state' => RosterImport::STATE_PERMANENT_FAILED]);
        $operations = app(RosterImportOperations::class);
        $app = Mockery::mock($this->app)->makePartial();
        $app->shouldReceive('runningInConsole')->andReturn(false);
        Container::setInstance($app);

        try {
            $operations->retry($import, null);
            self::fail('A null actor must not pass outside the console.');
        } catch (AuthorizationException) {
            self::assertSame(RosterImport::STATE_PERMANENT_FAILED, RosterImport::query()->whereKey($import->id)->value('state'));
        } finally {
            Container::setInstance($this->app);
        }
    }

    public function test_a_rejected_import_needs_the_attested_path_which_keeps_the_evidence(): void
    {
        $evidence = ['envelope_from' => 'a@evil.example', 'authentication_results' => 'mx.cloudflare.net; dmarc=fail'];
        $import = RosterPipelineFixtures::import(['state' => RosterImport::STATE_REJECTED, 'classification' => ['verdict' => 'rejected', 'reasons' => ['evidence_dmarc_fail'], 'evidence' => $evidence]]);
        $operations = app(RosterImportOperations::class);
        self::assertFalse($operations->canRetry($import));
        self::assertTrue($operations->canRetryAttested($import));

        $admin = $this->admin();
        $operations->retryAttested($import, $admin, 'Phoned the City, confirmed the export');

        $import = $import->fresh();
        self::assertSame(RosterImport::STATE_QUEUED, $import->state);
        self::assertEquals([
            'verdict' => 'match',
            'original_verdict' => 'rejected',
            'reasons' => ['operator_attested', 'evidence_dmarc_fail'],
            'evidence' => $evidence,
            'attestation_reason' => 'Phoned the City, confirmed the export',
        ], $import->classification);
        self::assertSame(['operator_attested', 'evidence_dmarc_fail'], $import->classification['reasons']);

        $event = RosterEvent::query()->where('roster_import_id', $import->id)->latest('id')->first();
        self::assertSame('operator_attested', $event->reason);
        self::assertSame((int) $admin->id, (int) $event->actor_user_id);
        self::assertSame('Phoned the City, confirmed the export', $event->context['attestation_reason']);
        Bus::assertDispatched(ProcessRosterImport::class);
    }

    /** @return array<string, array{array<string, mixed>|null}> */
    public static function unprovenMail(): array
    {
        return [
            'held by the mail check' => [['verdict' => 'needs_review', 'reasons' => ['authentication_results_missing']]],
            'rejected verdict' => [['verdict' => 'rejected', 'reasons' => ['near_miss']]],
            'no verdict recorded' => [null],
        ];
    }

    /** @param array<string, mixed>|null $classification */
    #[DataProvider('unprovenMail')]
    public function test_a_plain_retry_of_mail_the_check_did_not_pass_is_refused(?array $classification): void
    {
        $import = RosterPipelineFixtures::import(['state' => RosterImport::STATE_NEEDS_REVIEW, 'classification' => $classification]);
        $operations = app(RosterImportOperations::class);

        self::assertFalse($operations->canRetry($import));
        self::assertTrue($operations->canRetryAttested($import));
        try {
            $operations->retry($import, $this->admin());
            self::fail('A plain retry must not vouch for this mail.');
        } catch (RosterPipelineException $exception) {
            self::assertSame('retry_needs_attestation', $exception->errorCode);
        }

        self::assertSame(RosterImport::STATE_NEEDS_REVIEW, $import->fresh()->state);
        Bus::assertNotDispatched(ProcessRosterImport::class);

        $operations->retryAttested($import->fresh(), $this->admin());
        self::assertSame(RosterImport::STATE_QUEUED, $import->fresh()->state);
    }

    public function test_once_attested_a_later_hold_retries_normally(): void
    {
        $import = RosterPipelineFixtures::import(['state' => RosterImport::STATE_NEEDS_REVIEW, 'classification' => ['verdict' => 'needs_review', 'reasons' => ['authentication_results_missing']]]);
        $operations = app(RosterImportOperations::class);
        $operations->retryAttested($import, $this->admin());

        DB::table('roster_imports')->where('id', $import->id)->update(['state' => RosterImport::STATE_NEEDS_REVIEW, 'error_code' => 'assignment_count_low']);

        self::assertTrue($operations->canRetry($import->fresh()));
        self::assertFalse($operations->canRetryAttested($import->fresh()));
    }

    /** @return array<string, array{string, array<string, mixed>}> */
    public static function notForTheAttestedPath(): array
    {
        return [
            'failed' => [RosterImport::STATE_PERMANENT_FAILED, []],
            'shadowed' => [RosterImport::STATE_SHADOWED, []],
            'held after a passed mail check' => [RosterImport::STATE_NEEDS_REVIEW, ['error_code' => 'assignment_count_low']],
            'queued' => [RosterImport::STATE_QUEUED, []],
        ];
    }

    /** @param array<string, mixed> $attributes */
    #[DataProvider('notForTheAttestedPath')]
    public function test_the_attested_path_is_refused_for_everything_a_plain_retry_covers(string $state, array $attributes): void
    {
        $import = RosterPipelineFixtures::import(['state' => $state, ...$attributes]);
        $operations = app(RosterImportOperations::class);

        self::assertFalse($operations->canRetryAttested($import));
        $this->expectException(RosterPipelineException::class);
        $this->expectExceptionMessage('retry_not_allowed');
        $operations->retryAttested($import, $this->admin());
    }

    public function test_the_attested_path_needs_the_source_file_and_an_authorised_actor(): void
    {
        $operations = app(RosterImportOperations::class);
        $gone = RosterPipelineFixtures::import(['state' => RosterImport::STATE_REJECTED, 'attachment_path' => null, 'attachment_disk' => null]);
        self::assertFalse($operations->canRetryAttested($gone));

        $this->expectException(AuthorizationException::class);
        $operations->retryAttested(RosterPipelineFixtures::import(['state' => RosterImport::STATE_REJECTED]), User::factory()->create());
    }

    public function test_a_new_layout_can_be_accepted_and_becomes_the_baseline(): void
    {
        $this->importExport(RosterPipelineFixtures::report('2026-10-29', ['generated_at' => '2026-10-29 13:10:00']));
        $import = $this->importExport(RosterPipelineFixtures::report(self::DATE, ['layout' => str_repeat('b', 64)]));
        self::assertSame(RosterImport::STATE_NEEDS_REVIEW, $import->state);
        self::assertSame('layout_changed', $import->error_code);

        // A plain retry would hold it again, so this is the operator's decision to make.
        $operations = app(RosterImportOperations::class);
        self::assertTrue($operations->canRetry($import));
        self::assertTrue($operations->canRetryAttested($import));
        $operations->retryAttested($import, $this->admin(), 'TeleStaff confirmed the report change');

        $this->parserReturns(RosterPipelineFixtures::report(self::DATE, ['layout' => str_repeat('b', 64)]));
        $this->runProcess($import);
        self::assertSame(RosterImport::STATE_PUBLISHED, $import->fresh()->state);

        $next = $this->importExport(RosterPipelineFixtures::report('2026-10-31', ['layout' => str_repeat('b', 64), 'generated_at' => '2026-10-30 13:20:00']));
        self::assertSame(RosterImport::STATE_PUBLISHED, $next->state, 'The accepted layout is now what later exports are compared with.');
    }

    public function test_a_failed_cleanup_can_be_retried_and_is_audited(): void
    {
        config(['daily_roster.cleanup_enabled' => true]);
        [, $import] = RosterPipelineFixtures::linkedImport(['state' => RosterImport::STATE_VERIFIED, 'cleanup_state' => RosterImport::CLEANUP_FAILED, 'cleanup_attempts' => 5, 'error_code' => 'cleanup_attachment_hash_mismatch']);
        $operations = app(RosterImportOperations::class);
        $admin = $this->admin();

        self::assertTrue($operations->canRetryCleanup($import));
        $operations->retryCleanup($import, $admin);

        $import = $import->fresh();
        self::assertSame(RosterImport::CLEANUP_NONE, $import->cleanup_state);
        self::assertSame(0, (int) $import->cleanup_attempts);
        self::assertNull($import->error_code);
        Bus::assertDispatched(CleanupRosterImport::class, static fn (CleanupRosterImport $job): bool => $job->importId === (int) $import->id && $job->afterCommit === true);

        $event = RosterEvent::query()->where('roster_import_id', $import->id)->where('action', 'cleanup_retried')->sole();
        self::assertSame((int) $admin->id, (int) $event->actor_user_id);
        self::assertFalse($operations->canRetryCleanup($import));
    }

    public function test_a_failed_cleanup_whose_row_is_already_gone_resumes_at_the_file(): void
    {
        config(['daily_roster.cleanup_enabled' => true]);
        $import = RosterPipelineFixtures::import(['state' => RosterImport::STATE_VERIFIED, 'cleanup_state' => RosterImport::CLEANUP_FAILED, 'cleanup_attempts' => 5, 'inbound_email_id' => null]);

        app(RosterImportOperations::class)->retryCleanup($import, $this->admin());

        self::assertSame(RosterImport::CLEANUP_ROW_DELETED, $import->fresh()->cleanup_state);
    }

    public function test_cleanup_retry_is_refused_unless_cleanup_failed_and_is_enabled_and_the_actor_may(): void
    {
        $operations = app(RosterImportOperations::class);
        $failed = RosterPipelineFixtures::import(['state' => RosterImport::STATE_VERIFIED, 'cleanup_state' => RosterImport::CLEANUP_FAILED]);
        self::assertFalse($operations->canRetryCleanup($failed), 'Cleanup is switched off.');

        config(['daily_roster.cleanup_enabled' => true]);
        $pending = RosterPipelineFixtures::import(['state' => RosterImport::STATE_VERIFIED, 'cleanup_state' => RosterImport::CLEANUP_PENDING]);
        self::assertFalse($operations->canRetryCleanup($pending));
        try {
            $operations->retryCleanup($pending, $this->admin());
            self::fail('Only a failed cleanup can be retried.');
        } catch (RosterPipelineException $exception) {
            self::assertSame('retry_not_allowed', $exception->errorCode);
        }

        $this->expectException(AuthorizationException::class);
        $operations->retryCleanup($failed, User::factory()->create());
    }

    public function test_a_failed_read_back_is_checked_again_rather_than_parsed_again(): void
    {
        $report = RosterPipelineFixtures::report(self::DATE);
        $import = RosterPipelineFixtures::import(['state' => RosterImport::STATE_VALIDATED, 'duty_date' => self::DATE, 'source_generated_at' => $report->generatedAt]);
        app(RosterPublisher::class)->publish($import, $report);
        DB::table('roster_assignments')->where('employee_id', '90001')->update(['raw_name' => 'TESTPERSON, TAMPERED']);
        self::assertFalse(app(RosterPublicationVerifier::class)->verify($import));

        // Someone repairs the row, then asks for a retry.
        DB::table('roster_assignments')->where('employee_id', '90001')->update(['raw_name' => RosterPipelineFixtures::FAKE_NAMES[0]]);
        app(RosterImportOperations::class)->retry($import->fresh(), $this->admin());

        self::assertSame(RosterImport::STATE_PUBLISHED, $import->fresh()->state);
        Bus::assertNotDispatched(ProcessRosterImport::class);
        Bus::assertDispatched(VerifyRosterImport::class);

        self::assertTrue(app(RosterPublicationVerifier::class)->verify($import));
        self::assertSame(RosterImport::STATE_VERIFIED, $import->fresh()->state);
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->givePermissionTo(Permission::findOrCreate('admin.system.manage', 'web'));

        return $admin->fresh();
    }
}
