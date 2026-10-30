<?php

namespace App\Jobs;

use App\Services\GoogleSheets\ApparatusSheetSyncService;
use Google\Service\Exception as GoogleServiceException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Every run sends every apparatus, so saves made while one sync waits in the queue
 * collapse into it. A save made once it has started queues another, so none is missed.
 */
class SyncApparatusToSheetJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    /** @var list<int> Seconds between tries, past Google's per-minute write quota. */
    public array $backoff = [60, 120, 300, 600];

    public int $uniqueFor = 120;

    public function handle(ApparatusSheetSyncService $service): void
    {
        if (! config('google_sheets.apparatus_sync_enabled')) {
            Log::debug('[SyncApparatusToSheetJob] Sync disabled via feature flag — skipping.');

            return;
        }

        try {
            $result = $service->sync();
            Log::info('[SyncApparatusToSheetJob] Sync succeeded', $result);
        } catch (GoogleServiceException $e) {
            if ($e->getCode() === 429 || $e->getCode() >= 500) {
                Log::warning('[SyncApparatusToSheetJob] Google is temporarily unavailable; the worker will retry.', ['code' => $e->getCode()]);
                throw $e;
            }
            $this->failPermanently($e);
        } catch (Throwable $e) {
            $this->failPermanently($e);
        }
    }

    private function failPermanently(Throwable $e): void
    {
        Log::error('[SyncApparatusToSheetJob] Sync failed: '.$e->getMessage());
        \Sentry\captureException($e);
        $this->fail($e);
    }
}
