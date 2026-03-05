<?php

namespace App\Jobs;

use App\Services\GoogleSheets\ApparatusSheetSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncApparatusToSheetJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 60; // seconds between retries

    public function handle(ApparatusSheetSyncService $service): void
    {
        if (!config('google_sheets.apparatus_sync_enabled')) {
            Log::debug('[SyncApparatusToSheetJob] Sync disabled via feature flag — skipping.');
            return;
        }

        try {
            $result = $service->sync();
            Log::info('[SyncApparatusToSheetJob] Sync succeeded', $result);
        } catch (Throwable $e) {
            Log::error('[SyncApparatusToSheetJob] Sync failed: ' . $e->getMessage());
            \Sentry\captureException($e);
            $this->fail($e);
        }
    }
}
