<?php

namespace App\Observers;

use App\Jobs\SyncApparatusToSheetJob;
use App\Models\Apparatus;
use Illuminate\Support\Carbon;

class ApparatusObserver
{
    public function created(Apparatus $apparatus): void
    {
        $apparatus->timestamps = false;
        $apparatus->reported_at = Carbon::now();
        $apparatus->saveQuietly();
        $this->dispatchSync();
    }

    public function updated(Apparatus $apparatus): void
    {
        $apparatus->timestamps = false;
        $apparatus->reported_at = Carbon::now();
        $apparatus->saveQuietly();
        $this->dispatchSync();
    }

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
