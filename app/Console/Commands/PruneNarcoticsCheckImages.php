<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Narcotics\NarcoticsCheckService;
use Illuminate\Console\Command;

/**
 * Retention hook for narcotics check PHOTOS. The record, the decision and the audit trail are never
 * deleted; only the picture files of a decided check older than the configured window are removed
 * (`narcotics_checks.retention_days`, set from department records policy; 0 keeps everything).
 * Without --force it only reports what it would remove.
 */
final class PruneNarcoticsCheckImages extends Command
{
    protected $signature = 'narcotics-checks:prune-images
        {--days= : Override the configured retention in days}
        {--force : Actually delete (otherwise a dry run)}';

    protected $description = 'Remove the photo files of decided narcotics checks past the retention window (records and audit are kept)';

    public function handle(NarcoticsCheckService $checks): int
    {
        $days = $this->option('days') !== null ? (int) $this->option('days') : (int) config('narcotics_checks.retention_days');
        if ($days < 1) {
            $this->info('No narcotics check photo retention window is set; nothing was removed.');

            return self::SUCCESS;
        }

        $apply = (bool) $this->option('force');
        $count = $checks->purgeExpiredImages($days, $apply);
        $this->info($apply
            ? "Removed the photo files of {$count} narcotics check(s) decided more than {$days} days ago."
            : "Dry run: {$count} narcotics check(s) decided more than {$days} days ago have photo files to remove. Run with --force to remove them.");

        return self::SUCCESS;
    }
}
