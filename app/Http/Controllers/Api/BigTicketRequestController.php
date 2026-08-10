<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BigTicketRequest;
use App\Models\Station;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BigTicketRequestController extends Controller
{
    /**
     * Store a new big ticket request.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'station_id' => 'required|exists:stations,id',
            'room_type' => 'required|string|max:100',
            'room_label' => 'nullable|string|max:255',
            'items' => 'required|array|min:1|max:100',
            'items.*' => 'required|string|max:255',
            'other_item' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:5000',
        ]);

        $bigTicketRequest = BigTicketRequest::create([
            'station_id' => $validated['station_id'],
            'room_type' => $validated['room_type'],
            'room_label' => $validated['room_label'] ?? null,
            'items' => $validated['items'],
            'other_item' => $validated['other_item'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'created_by' => $request->user()?->id,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Big ticket request submitted successfully.',
            'data' => $bigTicketRequest,
        ], 201);
    }

    /**
     * Get big ticket requests for a station.
     */
    public function index(Station $station): JsonResponse
    {
        $requests = $station->bigTicketRequests()
            ->with('creator:id,name')
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
    public function destroy(BigTicketRequest $bigTicketRequest): JsonResponse
    {
        $bigTicketRequest->delete();

        return response()->json([
            'success' => true,
            'message' => 'Request deleted successfully.',
        ]);
    }
}
