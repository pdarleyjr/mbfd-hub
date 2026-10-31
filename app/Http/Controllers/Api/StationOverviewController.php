<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Station;
use App\Services\StationOverviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The Overview tab of a member's station page. Read-only; every signed-in member who can open the page can read it. */
final class StationOverviewController extends Controller
{
    public function show(Request $request, Station $station, StationOverviewService $overview): JsonResponse
    {
        return response()->json($overview->forStation($station, $request))
            ->header('Cache-Control', 'no-store, private');
    }

    public function checkout(Station $station, int $inspection, StationOverviewService $overview): JsonResponse
    {
        $checkout = $overview->checkout($station, $inspection);
        abort_if($checkout === null, 404);

        return response()->json(['checkout' => $checkout])
            ->header('Cache-Control', 'no-store, private');
    }
}
