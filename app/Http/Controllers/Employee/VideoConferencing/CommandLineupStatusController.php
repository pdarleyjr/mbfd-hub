<?php

namespace App\Http\Controllers\Employee\VideoConferencing;

use App\Contracts\VideoConferencing\ConferenceProvider;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Services\VideoConferencing\ConferenceCommandAuthorizationService;
use App\Services\VideoConferencing\ConferenceLineupReadinessService;
use App\Services\VideoConferencing\ConferenceSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CommandLineupStatusController extends Controller
{
    public function __invoke(
        Request $request,
        ConferenceCommandAuthorizationService $authorization,
        ConferenceLineupReadinessService $readiness,
        ConferenceSessionService $sessions,
        ConferenceProvider $provider,
    ): JsonResponse {
        /** @var Employee $employee */
        $employee = $request->user('employee');
        $authorization->assertAuthorized($request, $employee);
        $session = $sessions->activeLineup();
        $providerHealthy = $provider->healthCheck();
        $participants = [];
        if ($session !== null && $providerHealthy) {
            try {
                $participants = collect($provider->participants($session->livekit_room_name))
                    ->map(fn ($participant): array => [
                        'identity' => $participant->identity,
                        'name' => $participant->name,
                    ])->all();
            } catch (\Throwable) {
                $providerHealthy = false;
            }
        }

        return response()->json([
            'provider_api_healthy' => $providerHealthy,
            'lineup' => $sessions->lineupStatus($session),
            'stations' => $readiness->allStations(),
            'participants' => $participants,
        ])->header('Cache-Control', 'no-store');
    }
}
