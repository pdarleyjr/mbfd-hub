<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\UnitStatus\SyncOutcome;
use App\Services\UnitStatus\UnitStatusSync as SyncService;
use Illuminate\Console\Command;

/** Pulls PulsePoint calls from Command's private feed into the Unit Status tables. Scheduled every minute. */
final class UnitStatusSync extends Command
{
    protected $signature = 'unit-status:sync';

    protected $description = 'Sync PulsePoint calls for the Unit Status page from the private Command feed';

    public function handle(SyncService $sync): int
    {
        $outcome = $sync->run();
        $this->line('unit-status:sync '.$outcome->status.': '.$outcome->message);

        // A feed that is down is reported on the page, not as a failed job; only a Hub-side fault fails the run.
        return $outcome->status === SyncOutcome::HUB_ERROR ? self::FAILURE : self::SUCCESS;
    }
}
