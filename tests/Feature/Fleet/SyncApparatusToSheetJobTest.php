<?php

declare(strict_types=1);

namespace Tests\Feature\Fleet;

use App\Jobs\SyncApparatusToSheetJob;
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

    public function test_the_retry_schedule_outlasts_googles_per_minute_quota(): void
    {
        $job = new SyncApparatusToSheetJob;

        self::assertSame(5, $job->tries);
        self::assertSame([60, 120, 300, 600], $job->backoff);
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
