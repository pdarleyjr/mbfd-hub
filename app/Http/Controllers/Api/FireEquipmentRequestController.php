<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FireEquipmentRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FireEquipmentRequestController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = FireEquipmentRequest::with(['station', 'requestedBy', 'approvedBy']);

        if ($request->has('station_id')) {
            $query->where('station_id', $request->station_id);
        }
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        return response()->json($query->latest()->paginate($request->get('per_page', 15)));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'station_id' => 'required|exists:stations,id',
            'requested_by' => 'nullable|exists:users,id',
            'equipment_type' => 'required|string|max:255',
            'description' => 'required|string',
            'priority' => 'required|in:low,medium,high,critical',
            'status' => 'sometimes|in:pending,approved,denied,fulfilled',
            'form_data' => 'nullable|array',
            'signature' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);

        $record = FireEquipmentRequest::create($validated);

        return response()->json($record->load(['station', 'requestedBy']), 201);
    }

    public function show(FireEquipmentRequest $fireEquipmentRequest): JsonResponse
    {
        return response()->json(
            $fireEquipmentRequest->load(['station', 'requestedBy', 'approvedBy'])
        );
    }

    public function update(Request $request, FireEquipmentRequest $fireEquipmentRequest): JsonResponse
    {
        $validated = $request->validate([
            'equipment_type' => 'sometimes|string|max:255',
            'description' => 'sometimes|string',
            'priority' => 'sometimes|in:low,medium,high,critical',
            'status' => 'sometimes|in:pending,approved,denied,fulfilled',
            'form_data' => 'nullable|array',
            'signature' => 'nullable|string',
            'approved_by' => 'nullable|exists:users,id',
            'approved_at' => 'nullable|date',
            'notes' => 'nullable|string',
        ]);

        $fireEquipmentRequest->update($validated);

        return response()->json($fireEquipmentRequest->load(['station', 'requestedBy', 'approvedBy']));
    }

    public function destroy(FireEquipmentRequest $fireEquipmentRequest): JsonResponse
    {
        $fireEquipmentRequest->delete();

        return response()->json(null, 204);
    }
}
