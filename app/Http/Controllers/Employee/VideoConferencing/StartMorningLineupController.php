<?php

namespace App\Http\Controllers\Employee\VideoConferencing;

use App\Concerns\ResolvesCanonicalEmployee;
use App\Enums\VideoConferencing\ConferenceJoinRole;
use App\Exceptions\VideoConferencing\ConferenceUnavailableException;
use App\Exceptions\VideoConferencing\EndpointInUseException;
use App\Http\Controllers\Controller;
use App\Services\VideoConferencing\ConferenceCommandAuthorizationService;
use App\Services\VideoConferencing\ConferenceLineupNotifier;
use App\Services\VideoConferencing\ConferenceSessionService;
use App\Services\VideoConferencing\ConferenceTokenService;
use App\Services\VideoConferencing\LiveKitProfileConfiguration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StartMorningLineupController extends Controller
{
    use ResolvesCanonicalEmployee;

    public function __invoke(
        Request $request,
        ConferenceCommandAuthorizationService $authorization,
        ConferenceLineupNotifier $notifier,
        ConferenceSessionService $sessions,
        ConferenceTokenService $tokens,
        LiveKitProfileConfiguration $livekit,
    ): JsonResponse {
        $validated = $request->validate(['confirmed_takeover' => ['sometimes', 'boolean']]);
        $employee = $this->authenticatedEmployee();
        $authorization->assertAuthorized($request, $employee);

        try {
            $session = $sessions->startLineup($employee);
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
        $notifier->notify('started');

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
