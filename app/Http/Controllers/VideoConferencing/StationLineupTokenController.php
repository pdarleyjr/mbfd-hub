<?php

namespace App\Http\Controllers\VideoConferencing;

use App\Exceptions\VideoConferencing\ConferenceUnavailableException;
use App\Exceptions\VideoConferencing\EndpointInUseException;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\VideoConferencing\ConferenceLaunchContextService;
use App\Services\VideoConferencing\ConferenceLineupReadinessService;
use App\Services\VideoConferencing\ConferenceSessionService;
use App\Services\VideoConferencing\ConferenceTokenService;
use App\Services\VideoConferencing\LiveKitProfileConfiguration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StationLineupTokenController extends Controller
{
    public function __invoke(
        Request $request,
        ConferenceLaunchContextService $launches,
        ConferenceLineupReadinessService $readiness,
        ConferenceSessionService $sessions,
        ConferenceTokenService $tokens,
        LiveKitProfileConfiguration $livekit,
    ): JsonResponse {
        $validated = $request->validate([
            'launch_context' => ['required', 'uuid'],
            'room' => ['sometimes', 'in:lineup,direct'],
            'confirmed_takeover' => ['sometimes', 'boolean'],
        ]);
        $role = $launches->station($request, $validated['launch_context']);
        $readiness->assertReady($role, $validated['launch_context']);
        $roomMode = (string) ($validated['room'] ?? 'lineup');
        $session = $roomMode === 'direct'
            ? $sessions->activeDirectForStationOrNull($role)
            : $sessions->activeLineup();
        if ($session === null) {
            return response()->json([
                'message' => $roomMode === 'direct'
                    ? 'There is no active direct call for this station.'
                    : 'Morning Lineup has not started.',
                'code' => $roomMode === 'direct' ? 'direct_not_started' : 'lineup_not_started',
            ], 409)->header('Cache-Control', 'no-store');
        }
        $user = $request->user();
        $employee = $user instanceof User ? $user->employeeProfile : null;

        try {
            $result = $tokens->issue(
                $session,
                $employee,
                $role,
                (bool) ($validated['confirmed_takeover'] ?? false),
                $validated['launch_context'],
            );
        } catch (EndpointInUseException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'code' => 'endpoint_in_use',
                'takeover_available' => true,
            ], 409)->header('Cache-Control', 'no-store');
        } catch (ConferenceUnavailableException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'code' => 'conference_unavailable',
            ], 503)->header('Cache-Control', 'no-store');
        }

        return response()->json([
            'session' => $sessions->sessionPayload($session),
            'token' => $result['issued']->token,
            'server_url' => $livekit->clientUrl(),
            'expires_at' => $result['issued']->expiresAt->toIso8601String(),
            'participation_id' => $result['participation']->id,
            'participant' => [
                'identity' => $result['participation']->participant_identity,
                'name' => $result['participation']->display_name,
                'join_as' => $result['participation']->join_as->value,
            ],
        ])->header('Cache-Control', 'no-store, private');
    }
}
