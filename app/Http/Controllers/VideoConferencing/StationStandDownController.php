<?php

namespace App\Http\Controllers\VideoConferencing;

use App\Http\Controllers\Controller;
use App\Services\VideoConferencing\ConferenceLaunchContextService;
use App\Services\VideoConferencing\ConferenceLineupReadinessService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class StationStandDownController extends Controller
{
    public function __invoke(
        Request $request,
        ConferenceLaunchContextService $launches,
        ConferenceLineupReadinessService $readiness,
    ): Response {
        $validated = $request->validate(['launch_context' => ['required', 'uuid']]);
        $station = $launches->station($request, $validated['launch_context']);
        $readiness->remove($station, $validated['launch_context']);

        return response()->noContent();
    }
}
