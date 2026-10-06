<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Bid;

use App\Http\Controllers\Controller;
use App\Http\Requests\BidAssignmentRequest;
use App\Services\BidAssignmentReceiver;
use Illuminate\Http\JsonResponse;

final class BidAssignmentController extends Controller
{
    public function __invoke(BidAssignmentRequest $request, string $employeeId, BidAssignmentReceiver $receiver): JsonResponse
    {
        $created = $receiver->receive($employeeId, $request->validated());

        return response()->json($created ? ['status' => 'accepted'] : ['status' => 'already_recorded', 'code' => 'already_recorded'], $created ? 200 : 409);
    }
}
