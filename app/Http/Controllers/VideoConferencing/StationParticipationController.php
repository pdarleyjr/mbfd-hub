<?php

namespace App\Http\Controllers\VideoConferencing;

use App\Http\Controllers\Controller;
use App\Models\VideoConferenceParticipation;
use App\Services\VideoConferencing\ConferenceLaunchContextService;
use App\Services\VideoConferencing\ConferenceParticipationService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class StationParticipationController extends Controller
{
    public function leave(
        Request $request,
        VideoConferenceParticipation $participation,
        ConferenceLaunchContextService $launches,
        ConferenceParticipationService $participations,
    ): Response {
        $validated = $request->validate(['launch_context' => ['required', 'uuid']]);
        $station = $launches->station($request, $validated['launch_context']);
        $participations->assertStation($participation, $station, $validated['launch_context']);
        $participations->leave($participation);

        return response()->noContent();
    }

    public function stats(
        Request $request,
        VideoConferenceParticipation $participation,
        ConferenceLaunchContextService $launches,
        ConferenceParticipationService $participations,
    ): Response {
        $validated = $this->validateStats($request, true);
        $station = $launches->station($request, $validated['launch_context']);
        $participations->assertStation($participation, $station, $validated['launch_context']);
        $participations->recordStats($participation, $validated);

        return response()->noContent();
    }

    /** @return array{launch_context: string, downstream_bytes: int, packets_received: int, packets_lost: int, jitter_ms: int} */
    private function validateStats(Request $request, bool $launchContext): array
    {
        return $request->validate([
            'launch_context' => [$launchContext ? 'required' : 'nullable', 'uuid'],
            'downstream_bytes' => ['required', 'integer', 'min:0', 'max:10000000000'],
            'packets_received' => ['required', 'integer', 'min:0', 'max:1000000000'],
            'packets_lost' => ['required', 'integer', 'min:0', 'max:1000000000'],
            'jitter_ms' => ['required', 'integer', 'min:0', 'max:60000'],
        ]);
    }
}
