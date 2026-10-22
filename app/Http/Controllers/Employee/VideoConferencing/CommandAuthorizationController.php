<?php

namespace App\Http\Controllers\Employee\VideoConferencing;

use App\Concerns\ResolvesCanonicalEmployee;
use App\Http\Controllers\Controller;
use App\Services\VideoConferencing\ConferenceCommandAuthorizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Retained for one release so cached clients that still call it keep working.
 * It takes no body and reads no PIN: it only reports the live policy decision.
 */
class CommandAuthorizationController extends Controller
{
    use ResolvesCanonicalEmployee;

    public function __invoke(Request $request, ConferenceCommandAuthorizationService $authorization): JsonResponse
    {
        $employee = $this->authenticatedEmployee();
        $authorization->assertAuthorized($request, $employee);

        return response()->json(['authorized' => true])->header('Cache-Control', 'no-store');
    }
}
