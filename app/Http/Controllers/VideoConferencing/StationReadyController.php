<?php

namespace App\Http\Controllers\VideoConferencing;

use App\Events\VideoConferencing\LineupStateChanged;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\VideoConferencing\ConferenceLaunchContextService;
use App\Services\VideoConferencing\ConferenceLineupReadinessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StationReadyController extends Controller
{
    public function __invoke(
        Request $request,
        ConferenceLaunchContextService $launches,
        ConferenceLineupReadinessService $readiness,
    ): JsonResponse {
        $validated = $request->validate([
            'launch_context' => ['required', 'uuid'],
            'camera_ready' => ['required', 'boolean'],
            'microphone_ready' => ['required', 'boolean'],
        ]);
        $role = $launches->station($request, $validated['launch_context']);
        $user = $request->user();
        $employeeId = $user instanceof User ? $user->employee_profile_id : null;
        $state = $readiness->markReady(
            $role,
            $validated['launch_context'],
            (bool) $validated['camera_ready'],
            (bool) $validated['microphone_ready'],
            $employeeId,
        );
        LineupStateChanged::dispatch('readiness');

        return response()->json(['station' => $state])->header('Cache-Control', 'no-store');
    }
}
