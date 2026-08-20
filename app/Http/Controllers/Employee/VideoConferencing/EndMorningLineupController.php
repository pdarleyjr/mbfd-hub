<?php

namespace App\Http\Controllers\Employee\VideoConferencing;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Services\VideoConferencing\ConferenceCommandAuthorizationService;
use App\Services\VideoConferencing\ConferenceLineupNotifier;
use App\Services\VideoConferencing\ConferenceLineupReadinessService;
use App\Services\VideoConferencing\ConferenceSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EndMorningLineupController extends Controller
{
    public function __invoke(
        Request $request,
        ConferenceCommandAuthorizationService $authorization,
        ConferenceLineupReadinessService $readiness,
        ConferenceLineupNotifier $notifier,
        ConferenceSessionService $sessions,
    ): JsonResponse {
        /** @var Employee $employee */
        $employee = $request->user('employee');
        $authorization->assertAuthorized($request, $employee);
        $session = $sessions->activeLineup();
        if ($session !== null) {
            $sessions->end($session);
        }
        $readiness->clear();
        $notifier->notify('ended');

        return response()->json(['lineup' => $sessions->lineupStatus()])
            ->header('Cache-Control', 'no-store');
    }
}
