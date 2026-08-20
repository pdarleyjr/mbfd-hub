<?php

namespace App\Http\Controllers\Employee\VideoConferencing;

use App\Enums\VideoConferencing\ConferenceJoinRole;
use App\Exceptions\VideoConferencing\ConferenceUnavailableException;
use App\Exceptions\VideoConferencing\EndpointInUseException;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\VideoConferenceSession;
use App\Services\VideoConferencing\ConferenceCommandPinService;
use App\Services\VideoConferencing\ConferenceTokenService;
use App\Services\VideoConferencing\LiveKitProfileConfiguration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ConferenceTokenController extends Controller
{
    public function __invoke(
        Request $request,
        VideoConferenceSession $session,
        ConferenceTokenService $tokens,
        ConferenceCommandPinService $commandPin,
        LiveKitProfileConfiguration $livekit,
    ): JsonResponse {
        $validated = $request->validate([
            'join_as' => ['required', Rule::enum(ConferenceJoinRole::class)],
            'confirmed_takeover' => ['sometimes', 'boolean'],
            'command_pin' => ['nullable', 'string', 'regex:/^\d{4,8}$/'],
        ]);
        /** @var Employee $employee */
        $employee = $request->user('employee');

        try {
            $requestedRole = ConferenceJoinRole::from($validated['join_as']);
            $role = $session->type->value === 'lineup' ? ConferenceJoinRole::Self : $requestedRole;
            if ($session->type->value === 'direct') {
                abort_unless($role === ConferenceJoinRole::Command, 403);
            }
            if ($role === ConferenceJoinRole::Command) {
                $commandPin->verify($employee, (string) $request->ip(), $validated['command_pin'] ?? null);
            }
            $result = $tokens->issue(
                session: $session,
                employee: $employee,
                role: $role,
                confirmedTakeover: (bool) ($validated['confirmed_takeover'] ?? false),
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
