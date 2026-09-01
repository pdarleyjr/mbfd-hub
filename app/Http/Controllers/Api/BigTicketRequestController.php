<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Station;
use App\Models\StationRequest;
use App\Models\User;
use App\Services\Identity\AuthenticatedMemberContextResolver;
use App\Services\StationRequestLegacyAdapterService;
use App\Services\StationRequestWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BigTicketRequestController extends Controller
{
    /**
     * Store a new big ticket request.
     */
    public function store(
        Request $request,
        StationRequestLegacyAdapterService $adapter,
        AuthenticatedMemberContextResolver $memberContextResolver,
    ): JsonResponse {
        $validated = $request->validate([
            'station_id' => 'required|exists:stations,id',
            'room_type' => 'required|string|max:100',
            'room_label' => 'nullable|string|max:255',
            'items' => 'required|array|min:1|max:100',
            'items.*' => 'required|string|max:255',
            'other_item' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:5000',
            'client_submission_id' => 'nullable|uuid',
        ]);

        $actor = $memberContextResolver->resolve($request)->actor();
        $actor->requireEmployee();
        $result = $adapter->submitBigTicket($validated, $actor);

        return response()->json([
            'success' => true,
            'message' => 'Station request submitted successfully.',
            'data' => $result->request,
        ], $result->created ? 201 : 200);
    }

    /**
     * Get big ticket requests for a station.
     */
    public function index(Station $station): JsonResponse
    {
        $requests = $station->stationRequests()
            ->where('request_type', 'repair_service')
            ->with(['room:id,name,station_id', 'items'])
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $requests,
        ]);
    }

    /**
     * Delete a big ticket request.
     */
    public function destroy(
        Request $httpRequest,
        int $bigTicketRequest,
        StationRequestWorkflowService $workflow,
    ): JsonResponse {
        $canonical = StationRequest::query()
            ->where(fn ($query) => $query
                ->where(fn ($legacy) => $legacy->where('legacy_source', 'big_ticket_requests')->where('legacy_id', $bigTicketRequest))
                ->orWhere(fn ($direct) => $direct->whereKey($bigTicketRequest)->where('request_type', 'repair_service')))
            ->firstOrFail();
        /** @var User $actor */
        $actor = $httpRequest->user();
        $workflow->transition($canonical, [
            'status' => 'cancelled',
            'public_note' => 'Request cancelled through the legacy compatibility endpoint.',
            'internal_note' => 'Legacy delete mapped to a non-destructive canonical cancellation.',
        ], $actor);

        return response()->json([
            'success' => true,
            'message' => 'Request cancelled successfully.',
        ]);
    }
}
