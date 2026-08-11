<?php

namespace App\Http\Controllers\Employee\VideoConferencing;

use App\Enums\VideoConferencing\ConferenceJoinRole;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\VideoConferenceSession;
use App\Services\VideoConferencing\ConferenceModerationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StationMicrophoneController extends Controller
{
    public function __invoke(
        Request $request,
        VideoConferenceSession $session,
        string $station,
        ConferenceModerationService $moderation,
    ): JsonResponse {
        $validated = $request->validate(['enabled' => ['required', 'boolean']]);
        $role = ConferenceJoinRole::tryFrom($station);
        abort_unless($role?->isStation(), 404);
        /** @var Employee $employee */
        $employee = $request->user('employee');

        return response()->json($moderation->setStationMicrophone(
            $session,
            $employee,
            $role,
            $validated['enabled'],
        ));
    }
}
