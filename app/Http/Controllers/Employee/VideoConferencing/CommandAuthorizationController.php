<?php

namespace App\Http\Controllers\Employee\VideoConferencing;

use App\Concerns\ResolvesCanonicalEmployee;
use App\Http\Controllers\Controller;
use App\Services\VideoConferencing\ConferenceCommandAuthorizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CommandAuthorizationController extends Controller
{
    use ResolvesCanonicalEmployee;

    public function __invoke(Request $request, ConferenceCommandAuthorizationService $authorization): JsonResponse
    {
        $validated = $request->validate([
            'command_pin' => ['required', 'string', 'regex:/^\d{4,8}$/'],
        ]);
        $employee = $this->authenticatedEmployee();
        $authorization->authorize($request, $employee, $validated['command_pin']);

        return response()->json(['authorized' => true])->header('Cache-Control', 'no-store');
    }
}
