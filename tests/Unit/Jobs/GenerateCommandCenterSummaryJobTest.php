<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use App\Jobs\GenerateCommandCenterSummaryJob;
use Tests\TestCase;

class GenerateCommandCenterSummaryJobTest extends TestCase
{
    public function test_database_retry_window_exceeds_timeout_and_allows_recovery(): void
    {
        $job = new GenerateCommandCenterSummaryJob('test-fingerprint');

        $this->assertGreaterThan($job->timeout, config('queue.connections.database.retry_after'));
        $this->assertGreaterThan(1, $job->tries);
    }
}
