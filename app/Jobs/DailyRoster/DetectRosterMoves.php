<?php

declare(strict_types=1);

namespace App\Jobs\DailyRoster;

use App\Services\DailyRoster\RosterMoveDetector;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Checks for in-shift moves right after a verified publish. A lost or failed run is repeated by daily-roster:reconcile. */
final class DetectRosterMoves implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public bool $failOnTimeout = true;

    public int $timeout;

    public function __construct()
    {
        $this->onQueue('default');
        $this->timeout = (int) config('daily_roster.processing.job_timeout_seconds', 55);
    }

    public function handle(RosterMoveDetector $detector): void
    {
        $detector->run();
    }
}
