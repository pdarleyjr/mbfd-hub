<?php

namespace App\Http\Controllers\Employee\VideoConferencing;

use App\Concerns\ResolvesCanonicalEmployee;
use App\Enums\VideoConferencing\ConferenceJoinRole;
use App\Exceptions\VideoConferencing\ConferenceUnavailableException;
use App\Exceptions\VideoConferencing\EndpointInUseException;
use App\Http\Controllers\Controller;
use App\Services\VideoConferencing\ConferenceCommandAuthorizationService;
use App\Services\VideoConferencing\ConferenceSessionService;
use App\Services\VideoConferencing\ConferenceTokenService;
use App\Services\VideoConferencing\LiveKitProfileConfiguration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StartDirectCallController extends Controller
{
    use ResolvesCanonicalEmployee;

    public function __invoke(
        Request $request,
        ConferenceCommandAuthorizationService $authorization,
        ConferenceSessionService $sessions,
        ConferenceTokenService $tokens,
        LiveKitProfileConfiguration $livekit,
    ): JsonResponse {
        $validated = $request->validate([
            'station' => ['required', Rule::enum(ConferenceJoinRole::class)],
            'confirmed_takeover' => ['sometimes', 'boolean'],
        ]);
        $station = ConferenceJoinRole::from($validated['station']);
        abort_unless($station->isStation(), 422, 'A supported station is required.');
        $employee = $this->authenticatedEmployee();
        $authorization->assertAuthorized($request, $employee);

        try {
            $session = $sessions->direct($employee, $station);
            $result = $tokens->issue(
                $session,
                $employee,
                ConferenceJoinRole::Command,
                (bool) ($validated['confirmed_takeover'] ?? false),
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
