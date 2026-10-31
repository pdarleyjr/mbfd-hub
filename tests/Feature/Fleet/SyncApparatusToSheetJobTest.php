<?php

declare(strict_types=1);

namespace Tests\Feature\Fleet;

use App\Jobs\SyncApparatusToSheetJob;
use App\Models\Apparatus;
use App\Observers\ApparatusObserver;
use App\Services\GoogleSheets\ApparatusSheetSyncService;
use Google\Service\Exception as GoogleServiceException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

final class SyncApparatusToSheetJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('google_sheets.apparatus_sync_enabled', true);
    }

    #[DataProvider('passingFaults')]
    public function test_a_passing_google_fault_is_left_for_the_worker_to_retry(int $code): void
    {
        $this->serviceThrows(new GoogleServiceException('Passing fault', $code));
        $job = (new SyncApparatusToSheetJob)->withFakeQueueInteractions();

        try {
            $job->handle(app(ApparatusSheetSyncService::class));
            self::fail('The worker must see the fault so it can apply the backoff.');
        } catch (GoogleServiceException) {
            $job->assertNotFailed();
        }
    }

    public static function passingFaults(): array
    {
        return ['quota' => [429], 'server error' => [500], 'unavailable' => [503]];
    }

    public function test_a_permanent_failure_ends_the_job_at_once(): void
    {
        $this->serviceThrows(new GoogleServiceException('No access', 403));
        $job = (new SyncApparatusToSheetJob)->withFakeQueueInteractions();

        $job->handle(app(ApparatusSheetSyncService::class));

        $job->assertFailed();
    }

    public function test_an_unknown_failure_ends_the_job_at_once(): void
    {
        $this->serviceThrows(new RuntimeException('Broken sheet layout'));
        $job = (new SyncApparatusToSheetJob)->withFakeQueueInteractions();

        $job->handle(app(ApparatusSheetSyncService::class));

        $job->assertFailed();
    }

    public function test_the_worker_waits_past_googles_per_minute_quota_before_trying_again(): void
    {
        config()->set('queue.default', 'database');
        $this->serviceThrows(new GoogleServiceException('Quota exceeded', 429));
        SyncApparatusToSheetJob::dispatch();

        $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--tries' => 3, '--sleep' => 0])->assertSuccessful();

        self::assertDatabaseCount('failed_jobs', 0);
        $job = DB::table('jobs')->sole();
        self::assertSame(1, (int) $job->attempts);
        self::assertGreaterThanOrEqual(now()->addSeconds(59)->getTimestamp(), (int) $job->available_at);
    }

    public function test_a_save_rolled_back_with_its_transaction_does_not_hold_up_the_next_sync(): void
    {
        config()->set('queue.default', 'database');
        $observer = new ApparatusObserver;

        DB::beginTransaction();
        $observer->deleted(new Apparatus);
        DB::rollBack();
        self::assertSame(0, DB::table('jobs')->count());

        $observer->deleted(new Apparatus);

        self::assertSame(1, DB::table('jobs')->count());
    }

    public function test_a_burst_of_saves_queues_one_sync_because_each_run_sends_every_apparatus(): void
    {
        config()->set('queue.default', 'database');

        foreach (range(1, 5) as $ignored) {
            SyncApparatusToSheetJob::dispatch();
        }

        self::assertSame(1, DB::table('jobs')->count());
    }

    public function test_a_save_during_a_running_sync_still_queues_another_so_it_is_not_missed(): void
    {
        config()->set('queue.default', 'database');
        $this->mock(ApparatusSheetSyncService::class, function ($mock): void {
            $mock->shouldReceive('sync')->once()->andReturnUsing(function (): array {
                SyncApparatusToSheetJob::dispatch();

                return ['rows' => 0];
            });
        });

        SyncApparatusToSheetJob::dispatch();
        $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0])->assertSuccessful();

        self::assertSame(1, DB::table('jobs')->count());
    }

    private function serviceThrows(\Throwable $exception): void
    {
        $service = Mockery::mock(ApparatusSheetSyncService::class);
        $service->shouldReceive('sync')->andThrow($exception);
        $this->app->instance(ApparatusSheetSyncService::class, $service);
    }
}
