<?php

namespace App\Http\Controllers\Employee\VideoConferencing;

use App\Concerns\ResolvesCanonicalEmployee;
use App\Http\Controllers\Controller;
use App\Models\VideoConferenceParticipation;
use App\Services\VideoConferencing\ConferenceTokenService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ConferenceLeaveController extends Controller
{
    use ResolvesCanonicalEmployee;

    public function __invoke(
        Request $request,
        VideoConferenceParticipation $participation,
        ConferenceTokenService $tokens,
    ): Response {
        $employee = $this->authenticatedEmployee();
        $tokens->leave($participation, $employee);

        return response()->noContent();
    }
}
