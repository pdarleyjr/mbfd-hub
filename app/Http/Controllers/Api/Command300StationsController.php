<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Station;
use App\Services\Command300\Command300StationBoard;
use Illuminate\Http\JsonResponse;

/**
 * The 300 dashboard: every station at a glance, then a read-only drill-in per station and per checkout. Routes sit
 * behind `can:access-command-300`; nothing here writes, and the payload is built from an allowlist by the board.
 */
final class Command300StationsController extends Controller
{
    private const NO_STORE = ['Cache-Control' => 'no-store, private'];

    public function index(Command300StationBoard $board): JsonResponse
    {
        return response()->json($board->board(), 200, self::NO_STORE);
    }

    public function show(Station $station, Command300StationBoard $board): JsonResponse
    {
        abort_unless($station->is_active, 404);

        return response()->json($board->station($station), 200, self::NO_STORE);
    }

    public function checkout(Station $station, int $inspection, Command300StationBoard $board): JsonResponse
    {
        abort_unless($station->is_active, 404);
        $checkout = $board->checkout($station, $inspection);
        abort_if($checkout === null, 404);

        return response()->json(['checkout' => $checkout], 200, self::NO_STORE);
    }
}
