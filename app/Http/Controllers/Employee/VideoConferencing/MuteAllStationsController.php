<?php

namespace App\Http\Controllers\Employee\VideoConferencing;

use App\Concerns\ResolvesCanonicalEmployee;
use App\Http\Controllers\Controller;
use App\Models\VideoConferenceSession;
use App\Services\VideoConferencing\ConferenceModerationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MuteAllStationsController extends Controller
{
    use ResolvesCanonicalEmployee;

    public function __invoke(
        Request $request,
        VideoConferenceSession $session,
        ConferenceModerationService $moderation,
    ): JsonResponse {
        $employee = $this->authenticatedEmployee();

        return response()->json([
            'muted' => $moderation->muteAllStations($session, $employee),
        ]);
    }
}
