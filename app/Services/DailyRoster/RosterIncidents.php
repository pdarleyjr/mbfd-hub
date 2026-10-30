<?php

declare(strict_types=1);

namespace App\Services\DailyRoster;

use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Reports a pipeline fault once per code per day. The same fault on every export would otherwise raise
 * a new error, and a new diagnosis from the site monitor, every few minutes. If the cache itself is
 * down the fault is reported anyway: a repeated report is better than a hidden one.
 */
final class RosterIncidents
{
    public function report(string $code, string $day): void
    {
        try {
            if (! Cache::add("daily_roster_incident:{$code}:{$day}", true, now()->addDays(2))) {
                return;
            }
        } catch (Throwable) {
            // Falls through to the report below.
        }

        report(new RosterPipelineException($code));
    }
}
