<?php

declare(strict_types=1);

namespace App\Jobs\DailyRoster;

use App\Models\DailyRoster\RosterImport;
use App\Services\DailyRoster\RosterImportCleanup;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Deletes one verified email and its attachment. RosterImportCleanup is idempotent and decides eligibility. */
final class CleanupRosterImport implements ShouldQueue
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

    public static function isEnabled(): bool
    {
        return config('daily_roster.mode') === 'publish' && (bool) config('daily_roster.cleanup_enabled');
    }

    public static function dispatchIfEnabled(int $importId): void
    {
        if (self::isEnabled()) {
            self::dispatch($importId)->afterCommit();
        }
    }

    public function handle(): void
    {
        $import = RosterImport::query()->find($this->importId);
        if ($import === null || ! self::isEnabled()) {
            return;
        }

        app(RosterImportCleanup::class)->run($import);
    }
}
