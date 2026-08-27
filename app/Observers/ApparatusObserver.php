<?php

declare(strict_types=1);

namespace App\Observers;

use App\Jobs\SyncApparatusToSheetJob;
use App\Models\Apparatus;
use App\Services\Display\DisplaySnapshotService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ApparatusObserver
{
    /**
     * Stamp reported_at and dispatch a sheet sync after a create.
     */
    public function created(Apparatus $apparatus): void
    {
        $apparatus->timestamps = false;
        $apparatus->reported_at = Carbon::now();
        $apparatus->saveQuietly();
        $this->invalidateDisplayReadModelsAfterCommit();
        $this->dispatchSync();
    }

    /**
     * Stamp reported_at and dispatch a sheet sync after an update.
     */
    public function updated(Apparatus $apparatus): void
    {
        $shouldInvalidateDisplayReadModels = $apparatus->wasChanged([
            'daily_checkout_requirement',
            'status',
            'station_id',
        ]);

        $apparatus->timestamps = false;
        $apparatus->reported_at = Carbon::now();
        $apparatus->saveQuietly();

        if ($shouldInvalidateDisplayReadModels) {
            $this->invalidateDisplayReadModelsAfterCommit();
        }

        $this->dispatchSync();
    }

    /**
     * Dispatch a sheet sync after deletion so the sheet stays current.
     */
    public function deleted(Apparatus $apparatus): void
    {
        $this->invalidateDisplayReadModelsAfterCommit();
        $this->dispatchSync();
    }

    private function invalidateDisplayReadModelsAfterCommit(): void
    {
        DB::afterCommit(static function (): void {
            Cache::forget(DisplaySnapshotService::SNAPSHOT_CACHE_KEY);
            Cache::forget(DisplaySnapshotService::STATIONS_CACHE_KEY);
        });
    }

    private function dispatchSync(): void
    {
        if (config('google_sheets.apparatus_sync_enabled')) {
            SyncApparatusToSheetJob::dispatch()->afterCommit();
        }
    }
}
