<?php

namespace App\Http\Controllers\VideoConferencing;

use App\Http\Controllers\Controller;
use App\Services\VideoConferencing\ConferenceLaunchContextService;
use App\Services\VideoConferencing\ConferenceLineupReadinessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StationHeartbeatController extends Controller
{
    public function __invoke(
        Request $request,
        ConferenceLaunchContextService $launches,
        ConferenceLineupReadinessService $readiness,
    ): JsonResponse {
        $validated = $request->validate(['launch_context' => ['required', 'uuid']]);
        $role = $launches->station($request, $validated['launch_context']);

        return response()->json([
            'station' => $readiness->heartbeat($role, $validated['launch_context']),
        ])->header('Cache-Control', 'no-store');
    }
}
