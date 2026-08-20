<?php

namespace App\Http\Controllers\Employee\VideoConferencing;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Services\VideoConferencing\ConferenceCommandAuthorizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CommandAuthorizationController extends Controller
{
    public function __invoke(Request $request, ConferenceCommandAuthorizationService $authorization): JsonResponse
    {
        $validated = $request->validate([
            'command_pin' => ['required', 'string', 'regex:/^\d{4,8}$/'],
        ]);
        /** @var Employee $employee */
        $employee = $request->user('employee');
        $authorization->authorize($request, $employee, $validated['command_pin']);

        return response()->json(['authorized' => true])->header('Cache-Control', 'no-store');
    }
}
