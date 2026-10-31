<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\DailyRoster\RosterRetention;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/** Removes finished roster history past its window. Prints counts only. */
final class PruneRosterHistory extends Command
{
    protected $signature = 'daily-roster:prune {--dry-run : Count what would be removed and change nothing}';

    protected $description = 'Remove old TeleStaff exports that changed nothing and replaced roster versions';

    public function handle(RosterRetention $retention): int
    {
        if (config('daily_roster.mode') === 'off') {
            return self::SUCCESS;
        }

        $counts = $retention->run((bool) $this->option('dry-run'));
        $this->line(sprintf('unchanged_exports=%d replaced_snapshots=%d', $counts['unchanged_exports'], $counts['replaced_snapshots']));

        if (! $this->option('dry-run') && array_sum($counts) > 0) {
            Log::info('daily_roster.prune', $counts);
        }

        return self::SUCCESS;
    }
}
