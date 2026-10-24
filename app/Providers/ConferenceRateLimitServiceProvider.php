<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * Named limiters for video conferencing that must not share a bucket with the
 * stations' and 300's `conference-controls` limiter.
 */
class ConferenceRateLimitServiceProvider extends ServiceProvider
{
    /** Per signed-in employee: covers two visible tabs polling while "starting" plus Reverb nudges. */
    public const LINEUP_STATUS_PER_EMPLOYEE_PER_MINUTE = 30;

    /** Per network address: a safety net for a whole firehouse behind one egress IP, far above normal use. */
    public const LINEUP_STATUS_PER_IP_PER_MINUTE = 1200;

    public function boot(): void
    {
        // Member waiting-room status reads. Own keys, so thousands of waiting members can never
        // spend the stations' heartbeat/status budget or the 300's start/end/moderation budget.
        RateLimiter::for('conference-lineup-status', function (Request $request): array {
            $user = $request->user();
            $employeeId = $user instanceof User ? $user->employee_profile_id : null;
            $subject = $employeeId !== null ? 'employee:'.$employeeId : 'ip:'.$request->ip();

            return [
                Limit::perMinute(self::LINEUP_STATUS_PER_EMPLOYEE_PER_MINUTE)->by('conference-lineup-status:'.$subject),
                Limit::perMinute(self::LINEUP_STATUS_PER_IP_PER_MINUTE)->by('conference-lineup-status-ip:'.$request->ip()),
            ];
        });
    }
}
