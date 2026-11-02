<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Home\HomeActivity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The two small JSON feeds behind the collapsed Home page visits control on the Admin Overview: the counters, and the
 * list of members who signed in over the past day. Administrators only, and never cached by the browser or any proxy.
 */
final class HomeActivityController extends Controller
{
    private const HEADERS = ['Cache-Control' => 'no-store, private'];

    public function summary(Request $request, HomeActivity $activity): JsonResponse
    {
        $this->authorizeViewer($request, $activity);
        $this->leaveFlashAlone($request);
        $summary = $activity->summary();

        return $summary === null
            ? response()->json(['message' => 'Unavailable.'], 503, self::HEADERS)
            : response()->json($summary, 200, self::HEADERS);
    }

    public function members(Request $request, HomeActivity $activity): JsonResponse
    {
        $this->authorizeViewer($request, $activity);
        $this->leaveFlashAlone($request);
        $signedIn = $activity->signedIn();

        return $signedIn === null
            ? response()->json(['message' => 'Unavailable.'], 503, self::HEADERS)
            : response()->json($signedIn, 200, self::HEADERS);
    }

    private function authorizeViewer(Request $request, HomeActivity $activity): void
    {
        $user = $request->user('web');
        abort_unless($user instanceof User && $activity->canSeeActivity($user), 403);
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
