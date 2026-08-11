<?php

namespace App\Http\Controllers\Employee\VideoConferencing;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\VideoConferenceSession;
use App\Services\VideoConferencing\ConferenceModerationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MuteAllStationsController extends Controller
{
    public function __invoke(
        Request $request,
        VideoConferenceSession $session,
        ConferenceModerationService $moderation,
    ): JsonResponse {
        /** @var Employee $employee */
        $employee = $request->user('employee');

        return response()->json([
            'muted' => $moderation->muteAllStations($session, $employee),
        ]);
    }
}
