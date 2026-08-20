<?php

namespace App\Services\VideoConferencing;

use App\Events\VideoConferencing\LineupStateChanged;
use Illuminate\Support\Facades\Log;

class ConferenceLineupNotifier
{
    public function notify(string $state): void
    {
        try {
            LineupStateChanged::dispatch($state);
        } catch (\Throwable $exception) {
            Log::warning('Conference lineup realtime notification failed; polling remains authoritative.', [
                'state' => $state,
                'exception' => $exception::class,
            ]);
            report($exception);
        }
    }
}
