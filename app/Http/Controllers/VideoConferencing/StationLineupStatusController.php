<?php

namespace App\Http\Controllers\VideoConferencing;

use App\Http\Controllers\Controller;
use App\Services\VideoConferencing\ConferenceLaunchContextService;
use App\Services\VideoConferencing\ConferenceLineupReadinessService;
use App\Services\VideoConferencing\ConferenceSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StationLineupStatusController extends Controller
{
    public function __invoke(
        Request $request,
        ConferenceLaunchContextService $launches,
        ConferenceLineupReadinessService $readiness,
        ConferenceSessionService $sessions,
    ): JsonResponse {
        $validated = $request->validate(['launch_context' => ['required', 'uuid']]);
        $role = $launches->station($request, $validated['launch_context']);

        return response()->json([
            'station' => $readiness->stationState($role, $validated['launch_context']),
            'lineup' => $sessions->lineupStatus(),
            'direct' => $sessions->sessionStatus($sessions->activeDirectForStationOrNull($role)),
        ])->header('Cache-Control', 'no-store');
    }
}
