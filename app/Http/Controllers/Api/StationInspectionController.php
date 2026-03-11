<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StationInspection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StationInspectionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = StationInspection::with(['station', 'inspector', 'reviewer']);

        if ($request->has('station_id')) {
            $query->where('station_id', $request->station_id);
        }
        if ($request->has('overall_status')) {
            $query->where('overall_status', $request->overall_status);
        }

        return response()->json($query->latest()->paginate($request->get('per_page', 15)));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'station_id' => 'required|exists:stations,id',
            'inspector_id' => 'required|exists:users,id',
            'inspection_date' => 'required|date',
            'inspection_type' => 'required|string|max:255',
            'form_data' => 'required|array',
            'overall_status' => 'required|in:pass,fail,needs_attention',
            'inspector_signature' => 'nullable|string',
            'reviewer_signature' => 'nullable|string',
            'reviewed_by' => 'nullable|exists:users,id',
            'reviewed_at' => 'nullable|date',
            'notes' => 'nullable|string',
        ]);

        $record = StationInspection::create($validated);

        return response()->json($record->load(['station', 'inspector']), 201);
    }

    public function show(StationInspection $stationInspection): JsonResponse
    {
        return response()->json(
            $stationInspection->load(['station', 'inspector', 'reviewer'])
        );
    }

    public function update(Request $request, StationInspection $stationInspection): JsonResponse
    {
        $validated = $request->validate([
            'inspection_date' => 'sometimes|date',
            'inspection_type' => 'sometimes|string|max:255',
            'form_data' => 'sometimes|array',
            'overall_status' => 'sometimes|in:pass,fail,needs_attention',
            'inspector_signature' => 'nullable|string',
            'reviewer_signature' => 'nullable|string',
            'reviewed_by' => 'nullable|exists:users,id',
            'reviewed_at' => 'nullable|date',
            'notes' => 'nullable|string',
        ]);

        $stationInspection->update($validated);

        return response()->json($stationInspection->load(['station', 'inspector', 'reviewer']));
    }

    public function destroy(StationInspection $stationInspection): JsonResponse
    {
        $stationInspection->delete();

        return response()->json(null, 204);
    }
}
