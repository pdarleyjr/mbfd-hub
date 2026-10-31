<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Station;
use App\Models\User;
use App\Services\Fleet\ApparatusOperationalAlertService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Read-only projection of operational alerts onto a station page. Network only, never offline-cached. */
final class OperationalAlertController extends Controller
{
    public function station(Request $request, Station $station, ApparatusOperationalAlertService $alerts): JsonResponse
    {
        $user = $request->user();

        return response()
            ->json(['alerts' => $alerts->forStation((int) $station->getKey(), $user instanceof User ? $user : null)])
            ->header('Cache-Control', 'no-store, private');
    }
}
