<?php

namespace App\Http\Controllers\Employee\VideoConferencing;

use App\Enums\VideoConferencing\ConferenceJoinRole;
use App\Exceptions\VideoConferencing\ConferenceUnavailableException;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Services\VideoConferencing\ConferenceCommandPinService;
use App\Services\VideoConferencing\ConferenceSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ConferenceSessionController extends Controller
{
    public function __invoke(
        Request $request,
        ConferenceSessionService $sessions,
        ConferenceCommandPinService $commandPin,
    ): JsonResponse {
        $validated = $request->validate([
            'room' => ['required', Rule::in(['lineup', 'direct'])],
            'station' => ['required_if:room,direct', 'nullable', Rule::enum(ConferenceJoinRole::class)],
            'join_as' => ['required_if:room,direct', 'nullable', Rule::enum(ConferenceJoinRole::class)],
            'command_pin' => ['nullable', 'string', 'regex:/^\d{4,8}$/'],
        ]);
        /** @var Employee $employee */
        $employee = $request->user('employee');

        try {
            if ($validated['room'] === 'lineup') {
                $session = $sessions->lineup($employee);
            } else {
                $station = ConferenceJoinRole::from((string) ($validated['station'] ?? ''));
                $joiningAs = ConferenceJoinRole::from((string) ($validated['join_as'] ?? ''));
                abort_unless($joiningAs === ConferenceJoinRole::Command || $joiningAs === $station, 403);
                if ($joiningAs === ConferenceJoinRole::Command) {
                    $commandPin->verify($employee, (string) $request->ip(), $validated['command_pin'] ?? null);
                }
                $session = $joiningAs === ConferenceJoinRole::Command
                    ? $sessions->direct($employee, $station)
                    : $sessions->activeDirectForStation($station);
            }
        } catch (ConferenceUnavailableException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'code' => 'conference_unavailable',
            ], 503)->header('Cache-Control', 'no-store');
        }

        return response()->json([
            'session' => [
                'id' => $session->id,
                'type' => $session->type->value,
                'target_station' => $session->target_station,
                'scheduled_for' => $session->scheduled_for?->toIso8601String(),
                'lineup_time_configured' => config('video-conferencing.lineup_time') !== null,
            ],
        ])->header('Cache-Control', 'no-store');
    }
}
