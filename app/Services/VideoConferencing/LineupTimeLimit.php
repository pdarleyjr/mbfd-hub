<?php

declare(strict_types=1);

namespace App\Services\VideoConferencing;

use App\Models\VideoConferenceSession;
use Carbon\CarbonImmutable;

/**
 * The one place that knows when a Morning Lineup must end. The deadline is
 * `started_at` plus the clamped VIDEO_CONFERENCING_LINEUP_MAX_MINUTES (5 to 30),
 * so it is independent of the calendar day: a lineup started at 23:50 Eastern
 * ends at 00:05 like any other. Status, the token gate and the expiry command
 * all ask here, so none of them can drift from the others.
 */
class LineupTimeLimit
{
    public function maximumMinutes(): int
    {
        return max(5, min(30, (int) config('video-conferencing.lineup_max_minutes', 15)));
    }

    public function endsAt(VideoConferenceSession $session): ?CarbonImmutable
    {
        return $session->started_at === null
            ? null
            : CarbonImmutable::instance($session->started_at)->addMinutes($this->maximumMinutes());
    }

    public function hasExpired(VideoConferenceSession $session): bool
    {
        $endsAt = $this->endsAt($session);

        return $endsAt !== null && CarbonImmutable::now()->greaterThanOrEqualTo($endsAt);
    }
}
