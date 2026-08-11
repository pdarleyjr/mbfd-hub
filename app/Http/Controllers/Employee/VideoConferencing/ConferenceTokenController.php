<?php

namespace App\Http\Controllers\Employee\VideoConferencing;

use App\Enums\VideoConferencing\ConferenceJoinRole;
use App\Exceptions\VideoConferencing\ConferenceUnavailableException;
use App\Exceptions\VideoConferencing\EndpointInUseException;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\VideoConferenceSession;
use App\Services\VideoConferencing\ConferenceTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ConferenceTokenController extends Controller
{
    public function __invoke(
        Request $request,
        VideoConferenceSession $session,
        ConferenceTokenService $tokens,
    ): JsonResponse {
        $validated = $request->validate([
            'join_as' => ['required', Rule::enum(ConferenceJoinRole::class)],
            'confirmed_takeover' => ['sometimes', 'boolean'],
        ]);
        /** @var Employee $employee */
        $employee = $request->user('employee');

        try {
            $result = $tokens->issue(
                session: $session,
                employee: $employee,
                role: ConferenceJoinRole::from($validated['join_as']),
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
            'server_url' => config('video-conferencing.livekit.url'),
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
