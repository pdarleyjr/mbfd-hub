<?php

namespace App\Observers;

use App\Jobs\SyncApparatusToSheetJob;
use App\Models\Apparatus;
use Illuminate\Support\Carbon;

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
        $this->dispatchSync();
    }

    /**
     * Stamp reported_at and dispatch a sheet sync after an update.
     */
    public function updated(Apparatus $apparatus): void
    {
        $apparatus->timestamps = false;
        $apparatus->reported_at = Carbon::now();
        $apparatus->saveQuietly();
        $this->dispatchSync();
    }

    /**
     * Dispatch a sheet sync after deletion so the sheet stays current.
     */
    public function deleted(Apparatus $apparatus): void
    {
        $this->dispatchSync();
    }

    private function dispatchSync(): void
    {
        if (config('google_sheets.apparatus_sync_enabled')) {
            SyncApparatusToSheetJob::dispatch()->afterCommit();
        }
    }
}
