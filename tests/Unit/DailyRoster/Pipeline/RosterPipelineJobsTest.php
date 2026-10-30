<?php

declare(strict_types=1);

namespace Tests\Unit\DailyRoster\Pipeline;

use App\Jobs\DailyRoster\CleanupRosterImport;
use App\Jobs\DailyRoster\ProcessRosterImport;
use App\Jobs\DailyRoster\VerifyRosterImport;
use Illuminate\Contracts\Queue\ShouldQueue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class RosterPipelineJobsTest extends TestCase
{
    /** @return array<string, array{0: class-string}> */
    public static function jobs(): array
    {
        return [
            'process' => [ProcessRosterImport::class],
            'verify' => [VerifyRosterImport::class],
            'cleanup' => [CleanupRosterImport::class],
        ];
    }

    /** @param class-string $class */
    #[DataProvider('jobs')]
    public function test_the_queue_never_retries_and_the_worker_can_consume_it(string $class): void
    {
        config(['daily_roster.processing.job_timeout_seconds' => 44]);

        $job = new $class(12);

        self::assertInstanceOf(ShouldQueue::class, $job);
        self::assertSame(12, $job->importId);
        self::assertSame(1, $job->tries);
        self::assertTrue($job->failOnTimeout);
        self::assertSame(44, $job->timeout);
        self::assertSame('default', $job->queue);
    }

    /** @param class-string $class */
    #[DataProvider('jobs')]
    public function test_the_job_stays_inside_the_worker_retry_window(string $class): void
    {
        $job = new $class(12);

        self::assertLessThan(90, $job->timeout, 'A job outliving retry_after would run twice.');
    }

    /** @param class-string $class */
    #[DataProvider('jobs')]
    public function test_the_job_survives_the_queue_round_trip(string $class): void
    {
        $restored = unserialize(serialize(new $class(12)));

        self::assertSame(12, $restored->importId);
        self::assertSame(1, $restored->tries);
    }
}
