<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Deletes EMS idempotency keys past their retry window. Request, stock and audit history is never touched. */
final class PruneEmsIdempotencyKeys extends Command
{
    public const KEEP_DAYS = 30;

    protected $signature = 'ems:prune-idempotency {--days=30 : Keep keys newer than this many days}';

    protected $description = 'Delete EMS idempotency keys older than the retry window (30 days)';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $deleted = DB::table('ems_idempotency_keys')->where('created_at', '<', now()->subDays($days))->delete();
        $this->info("Deleted {$deleted} idempotency key(s) older than {$days} days.");

        return self::SUCCESS;
    }
}
