<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Mail\MailIndexSweeper;
use Illuminate\Console\Command;

final class MailIndexSweep extends Command
{
    protected $signature = 'mail:index-sweep
        {--read-older-than=60 : Minutes. Inbound mail older than this that the sweep meets for the first time starts read}
        {--all : Re-evaluate every message, not only unindexed and recent ones}';

    protected $description = 'Index inbound and outbound mail into Email Center threads (idempotent; also the backfill).';

    public function handle(MailIndexSweeper $sweeper): int
    {
        $minutes = filter_var($this->option('read-older-than'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 525600]]);
        if ($minutes === false) {
            $this->error('--read-older-than must be a whole number of minutes between 0 and 525600.');

            return self::FAILURE;
        }

        $stats = $sweeper->run(now()->subMinutes($minutes), (bool) $this->option('all'));
        $this->line('mail:index-sweep '.json_encode($stats, JSON_THROW_ON_ERROR));

        return $stats['errors'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
