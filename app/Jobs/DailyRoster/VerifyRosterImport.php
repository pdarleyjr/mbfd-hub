<?php

declare(strict_types=1);

namespace App\Jobs\DailyRoster;

use App\Models\DailyRoster\RosterImport;
use App\Services\DailyRoster\RosterPublicationVerifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Reads a published roster back. A lost or failed run is repeated by daily-roster:reconcile. */
final class VerifyRosterImport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public bool $failOnTimeout = true;

    public int $timeout;

    public function __construct(public readonly int $importId)
    {
        $this->onQueue('default');
        $this->timeout = (int) config('daily_roster.processing.job_timeout_seconds', 55);
    }

    public function handle(RosterPublicationVerifier $verifier): void
    {
        $import = RosterImport::query()->find($this->importId);
        if ($import === null || config('daily_roster.mode') === 'off') {
            return;
        }

        $verifier->verify($import);
    }
}
