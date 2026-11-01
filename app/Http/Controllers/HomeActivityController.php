<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Home\HomeActivity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The two small JSON feeds behind the Home visit card: the counters the open page refreshes, and the list of
 * members who signed in over the past day (admins only). Neither is cached by the browser or any proxy.
 */
final class HomeActivityController extends Controller
{
    private const HEADERS = ['Cache-Control' => 'no-store, private'];

    public function summary(Request $request, HomeActivity $activity): JsonResponse
    {
        $this->leaveFlashAlone($request);
        $summary = $activity->summary();

        return $summary === null
            ? response()->json(['message' => 'Unavailable.'], 503, self::HEADERS)
            : response()->json($summary, 200, self::HEADERS);
    }

    public function members(Request $request, HomeActivity $activity): JsonResponse
    {
        $user = $request->user('web');
        abort_unless($user instanceof User && $activity->canSeeSignIns($user), 403);

        $this->leaveFlashAlone($request);
        $signedIn = $activity->signedIn();

        return $signedIn === null
            ? response()->json(['message' => 'Unavailable.'], 503, self::HEADERS)
            : response()->json($signedIn, 200, self::HEADERS);
    }

    /**
     * Saving a session ages its flash data by one step, so a fetch that lands between a redirecting POST and the
     * page it redirects to would take the message ("Saved.") before that page can show it. Keep it for the next request.
     */
    private function leaveFlashAlone(Request $request): void
    {
        $request->session()->reflash();
    }
}
