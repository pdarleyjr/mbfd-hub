<?php

namespace App\Http\Controllers\Admin;

use App\Contracts\VideoConferencing\ConferenceProvider;
use App\Http\Controllers\Controller;
use App\Services\VideoConferencing\ConferenceClientFailureMonitor;
use Illuminate\Http\JsonResponse;

class VideoConferenceHealthController extends Controller
{
    public function __invoke(
        ConferenceProvider $provider,
        ConferenceClientFailureMonitor $clientFailures,
    ): JsonResponse {
        if (! config('video-conferencing.enabled')) {
            return response()->json(['status' => 'disabled']);
        }

        $healthy = $provider->healthCheck();
        $recentClientFailures = $clientFailures->recentCount();
        $degradedThreshold = max(
            1,
            (int) config('video-conferencing.client_failure_degraded_threshold', 3),
        );
        $status = ! $healthy
            ? 'unavailable'
            : ($recentClientFailures >= $degradedThreshold ? 'degraded' : 'healthy');

        return response()->json([
            'status' => $status,
            'checks' => [
                'provider_api' => $healthy ? 'healthy' : 'unavailable',
                'recent_client_connection_failures' => $recentClientFailures,
            ],
            'client_transport' => (string) config('video-conferencing.client_transport'),
        ], $healthy ? 200 : 503)
            ->header('Cache-Control', 'no-store');
    }
}
