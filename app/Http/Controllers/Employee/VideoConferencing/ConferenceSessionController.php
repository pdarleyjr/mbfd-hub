<?php

declare(strict_types=1);

namespace App\Http\Controllers\Employee\VideoConferencing;

use App\Concerns\ResolvesCanonicalEmployee;
use App\Enums\VideoConferencing\ConferenceJoinRole;
use App\Exceptions\VideoConferencing\ConferenceUnavailableException;
use App\Http\Controllers\Controller;
use App\Models\VideoConferenceSession;
use App\Services\VideoConferencing\ConferenceCommandAuthorizationService;
use App\Services\VideoConferencing\ConferenceSessionService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ConferenceSessionController extends Controller
{
    use ResolvesCanonicalEmployee;

    public function status(VideoConferenceSession $session): JsonResponse
    {
        $employee = $this->authenticatedEmployee();
        abort_unless($session->participations()->where('employee_id', $employee->getKey())->exists(), 403);

        return response()->json([
            'active' => $session->ended_at === null && $session->active_key !== null,
            'server_time' => CarbonImmutable::now((string) config('hub.timezone'))->toIso8601String(),
        ])
            ->header('Cache-Control', 'no-store');
    }

    public function __invoke(
        Request $request,
        ConferenceSessionService $sessions,
        ConferenceCommandAuthorizationService $authorization,
    ): JsonResponse {
        $validated = $request->validate([
            'room' => ['required', Rule::in(['lineup', 'direct'])],
            'station' => ['required_if:room,direct', 'nullable', Rule::enum(ConferenceJoinRole::class)],
            'join_as' => ['required_if:room,direct', 'nullable', Rule::enum(ConferenceJoinRole::class)],
        ]);
        $employee = $this->authenticatedEmployee();

        try {
            if ($validated['room'] === 'lineup') {
                $session = $sessions->activeLineup();
                if ($session === null) {
                    return response()->json([
                        'message' => 'Morning Lineup has not started.',
                        'code' => 'lineup_not_started',
                    ], 409)->header('Cache-Control', 'no-store');
                }
            } else {
                $station = ConferenceJoinRole::from((string) ($validated['station'] ?? ''));
                $joiningAs = ConferenceJoinRole::from((string) ($validated['join_as'] ?? ''));
                abort_unless($joiningAs === ConferenceJoinRole::Command, 403);
                $authorization->assertAuthorized($request, $employee);
                $session = $sessions->direct($employee, $station);
            }
        } catch (ConferenceUnavailableException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'code' => 'conference_unavailable',
            ], 503)->header('Cache-Control', 'no-store');
        }

        return response()->json(['session' => $sessions->sessionPayload($session)])
            ->header('Cache-Control', 'no-store');
    }
}
