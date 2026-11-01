<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Station;
use App\Services\DailyRoster\StationRosterPresenter;
use App\Services\Security\DailyRosterAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The Today's Roster tab of a station page. It carries names, employee IDs and leave codes, so it applies the Daily
 * Roster's own access rule, not the station page's, and the Daily service worker never stores it.
 */
final class StationRosterController extends Controller
{
    private const NO_STORE = ['Cache-Control' => 'no-store, private'];

    public function show(Request $request, Station $station, DailyRosterAccess $access, StationRosterPresenter $roster): JsonResponse
    {
        if (! $access->canView($request->user())) {
            abort(403, 'You do not have access to the Daily Roster.', self::NO_STORE);
        }

        return response()->json($roster->forStation($station), 200, self::NO_STORE);
    }
}
