<?php

namespace App\Http\Controllers\VideoConferencing;

use App\Concerns\ResolvesCanonicalEmployee;
use App\Enums\VideoConferencing\ConferenceJoinRole;
use App\Http\Controllers\Controller;
use App\Services\VideoConferencing\ConferenceBootstrapFactory;
use App\Services\VideoConferencing\ConferenceLaunchContextService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class ConferencePageController extends Controller
{
    use ResolvesCanonicalEmployee;

    public function station(
        Request $request,
        string $station,
        ConferenceLaunchContextService $launches,
        ConferenceBootstrapFactory $bootstrap,
    ): View {
        $role = ConferenceJoinRole::tryFrom('sta'.$station);
        abort_unless($role?->isStation(), 404);
        $launchContext = $launches->createStation($request, $role);

        return view('video-conferencing', [
            'enabled' => (bool) config('video-conferencing.enabled'),
            'conferenceBootstrap' => $bootstrap->make('station', $role, $launchContext),
        ]);
    }

    public function command(Request $request, ConferenceBootstrapFactory $bootstrap): View
    {
        $employee = $this->authenticatedEmployee();

        return view('video-conferencing', [
            'enabled' => (bool) config('video-conferencing.enabled'),
            'conferenceBootstrap' => $bootstrap->make('command', ConferenceJoinRole::Command, employee: $employee),
        ]);
    }
}
