<?php

namespace App\Http\Controllers\Employee\VideoConferencing;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\VideoConferenceParticipation;
use App\Services\VideoConferencing\ConferenceTokenService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ConferenceLeaveController extends Controller
{
    public function __invoke(
        Request $request,
        VideoConferenceParticipation $participation,
        ConferenceTokenService $tokens,
    ): Response {
        /** @var Employee $employee */
        $employee = $request->user('employee');
        $tokens->leave($participation, $employee);

        return response()->noContent();
    }
}
