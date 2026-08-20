<?php

namespace App\Http\Controllers\Admin;

use App\Contracts\VideoConferencing\ConferenceProvider;
use App\Http\Controllers\Controller;
use App\Services\VideoConferencing\ConferenceClientFailureMonitor;
use App\Services\VideoConferencing\ConferenceLineupReadinessService;
use App\Services\VideoConferencing\ConferenceSessionService;
use App\Services\VideoConferencing\ConferenceUsageService;
use App\Services\VideoConferencing\LiveKitProfileConfiguration;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class VideoConferenceHealthController extends Controller
{
    public function __invoke(
        ConferenceProvider $provider,
        ConferenceClientFailureMonitor $clientFailures,
        ConferenceLineupReadinessService $readiness,
        ConferenceSessionService $sessions,
        ConferenceUsageService $usage,
        LiveKitProfileConfiguration $livekit,
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

        $lineup = $sessions->activeLineup();
        $participants = [];
        if ($lineup !== null && $healthy) {
            try {
                $participants = collect($provider->participants($lineup->livekit_room_name))
                    ->map(fn ($participant): array => [
                        'identity' => $participant->identity,
                        'name' => $participant->name,
                    ])->all();
            } catch (\Throwable) {
                $status = 'degraded';
            }
        }
        $lastWebhookAt = Cache::get('video-conferencing:last-valid-webhook-at');
        $reverb = (array) config('broadcasting.connections.reverb', []);
        $reverbConfigured = config('broadcasting.default') === 'reverb'
            && (string) ($reverb['key'] ?? '') !== ''
            && (string) ($reverb['options']['host'] ?? '') !== '';
        $reverbHealthy = $reverbConfigured && $this->reverbIsReachable($reverb);
        if ($healthy && $reverbConfigured && ! $reverbHealthy) {
            $status = 'degraded';
        }

        return response()->json([
            'status' => $status,
            'checks' => [
                'provider_api' => $healthy ? 'healthy' : 'unavailable',
                'cloud_api' => $livekit->profile() === 'cloud'
                    ? ($healthy ? 'healthy' : 'unavailable')
                    : 'not_active',
                'webhook' => is_string($lastWebhookAt) ? 'healthy' : 'not_observed',
                'webhook_last_observed_at' => $lastWebhookAt,
                'reverb' => $reverbConfigured
                    ? ($reverbHealthy ? 'healthy' : 'unavailable')
                    : 'not_configured',
                'recent_client_connection_failures' => $recentClientFailures,
            ],
            'client_transport' => (string) config('video-conferencing.client_transport'),
            'provider' => $livekit->label(),
            'profile' => $livekit->profile(),
            'lineup' => $sessions->lineupStatus($lineup),
            'ready_stations' => collect($readiness->allStations())
                ->where('ready', true)
                ->pluck('label')
                ->values()
                ->all(),
            'connected_participants' => $participants,
            'usage' => $usage->monthlyEstimate(),
        ], $healthy ? 200 : 503)
            ->header('Cache-Control', 'no-store');
    }

    /** @param array<string, mixed> $reverb */
    private function reverbIsReachable(array $reverb): bool
    {
        $options = (array) ($reverb['options'] ?? []);
        $host = (string) ($options['host'] ?? '');
        $port = (int) ($options['port'] ?? 0);
        if ($host === '' || $port < 1 || $port > 65535) {
            return false;
        }

        $socket = @stream_socket_client(
            'tcp://'.$host.':'.$port,
            $errorCode,
            $errorMessage,
            0.35,
            STREAM_CLIENT_CONNECT,
        );
        if (! is_resource($socket)) {
            return false;
        }
        fclose($socket);

        return true;
    }
}
