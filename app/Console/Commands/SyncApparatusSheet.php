<?php

namespace App\Console\Commands;

use App\Services\GoogleSheets\ApparatusSheetSyncService;
use Illuminate\Console\Command;
use Throwable;

class SyncApparatusSheet extends Command
{
    protected $signature = 'apparatus:sync-sheet
                            {--dry-run : Preview what would be written without making any changes}
                            {--force : Run even if the sync feature flag is disabled}';

    protected $description = 'Sync all Fire Apparatus records to the Equipment Maintenance Google Sheet tab';

    public function handle(ApparatusSheetSyncService $service): int
    {
        $featureEnabled = config('google_sheets.apparatus_sync_enabled');
        $force = $this->option('force');
        $dryRun = $this->option('dry-run');

        if (!$featureEnabled && !$force) {
            $this->warn('Google Sheets apparatus sync is DISABLED (GOOGLE_SHEETS_APPARATUS_SYNC_ENABLED=false).');
            $this->line('Use --force to run anyway.');
            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->info('[DRY RUN] Showing rows that would be written — no changes will be made.');
        }

        try {
            $result = $service->sync($dryRun);

            if ($dryRun) {
                $this->table(
                    ['Designation', 'Vehicle#', 'Status', 'Location', 'Comments', 'Reported'],
                    $result['data'] ?? []
                );
                $this->info("Would write {$result['rows']} row(s).");
            } else {
                $this->info("✅ Sync complete — {$result['rows']} row(s) written to 'Equipment Maintenance' tab.");
            }

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('❌ Sync failed: ' . $e->getMessage());
            \Sentry\captureException($e);
            return self::FAILURE;
        }
    }
}
