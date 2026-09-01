<?php

namespace App\Http\Controllers\Employee\VideoConferencing;

use App\Concerns\ResolvesCanonicalEmployee;
use App\Http\Controllers\Controller;
use App\Models\VideoConferenceParticipation;
use App\Services\VideoConferencing\ConferenceParticipationService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ConferenceStatsController extends Controller
{
    use ResolvesCanonicalEmployee;

    public function __invoke(
        Request $request,
        VideoConferenceParticipation $participation,
        ConferenceParticipationService $participations,
    ): Response {
        $validated = $request->validate([
            'downstream_bytes' => ['required', 'integer', 'min:0', 'max:10000000000'],
            'packets_received' => ['required', 'integer', 'min:0', 'max:1000000000'],
            'packets_lost' => ['required', 'integer', 'min:0', 'max:1000000000'],
            'jitter_ms' => ['required', 'integer', 'min:0', 'max:60000'],
        ]);
        $employee = $this->authenticatedEmployee();
        $participations->assertEmployee($participation, $employee);
        $participations->recordStats($participation, $validated);

        return response()->noContent();
    }
}
