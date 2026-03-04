<?php

namespace App\Observers;

use App\Jobs\VectorizeWorkgroupFileJob;
use App\Models\WorkgroupFile;

/**
 * Observer for WorkgroupFile model.
 * Dispatches vectorization job when a file is uploaded.
 */
class WorkgroupFileObserver
{
    public function created(WorkgroupFile $file): void
    {
        VectorizeWorkgroupFileJob::dispatch($file->id)->afterCommit();
    }
}
