<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Captain5\Captain5ActingRequest;
use App\Services\Captain5\Captain5DutyRoster;
use App\Services\DailyRoster\OnDutyOfficerDisplayResolver;
use Illuminate\Http\JsonResponse;

/**
 * The shift's 300 (Division Chief) on the 300 page and the 300 card: who today's accepted roster names,
 * otherwise the 300 bid holder for the shift on duty. Display only. The route group needs `access-command-300`.
 */
final class Command300ShiftController extends Controller
{
    private const ROLE = Captain5ActingRequest::ROLE_COMMAND_300;

    public function show(): JsonResponse
    {
        app(Captain5DutyRoster::class)->flush();

        return response()->json([
            'on_duty' => app(OnDutyOfficerDisplayResolver::class)->resolve(self::ROLE)->toArray(),
            'acting' => Captain5ActingRequest::retiredState(self::ROLE),
        ], 200, ['Cache-Control' => 'private, no-store']);
    }
}
