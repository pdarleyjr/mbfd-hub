<?php

declare(strict_types=1);

namespace App\Http\Controllers\Employee\VideoConferencing;

use App\Concerns\ResolvesCanonicalEmployee;
use App\Http\Controllers\Controller;
use App\Services\VideoConferencing\ConferenceSessionService;
use Illuminate\Http\JsonResponse;

/**
 * Read-only "is Morning Lineup on yet?" for any signed-in member with a linked
 * profile. It is information, not entitlement: tokens, sessions and 300 powers
 * stay behind their own endpoints, and this one never writes or calls LiveKit.
 */
class MemberLineupStatusController extends Controller
{
    use ResolvesCanonicalEmployee;

    public function __invoke(ConferenceSessionService $sessions): JsonResponse
    {
        $employee = $this->authenticatedEmployee();

        return response()->json($sessions->memberLineupStatus($employee))
            ->header('Cache-Control', 'no-store');
    }
}
