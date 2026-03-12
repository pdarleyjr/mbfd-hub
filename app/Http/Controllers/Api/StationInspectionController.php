<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StationInspection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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

        // Process fail images in checklist items
        $formData = $validated['form_data'];
        $checklist = $formData['checklist'] ?? $formData;
        $timestamp = now()->format('Ymd_His');

        if (is_array($checklist)) {
            foreach ($checklist as $index => &$item) {
                if (!is_array($item)) {
                    continue;
                }
                $status = $item['status'] ?? null;
                $failImage = $item['failImage'] ?? null;

                if (strtolower($status ?? '') === 'fail' && !empty($failImage) && str_contains($failImage, 'base64')) {
                    // Extract base64 data (handle "data:image/...;base64,XXXX" format)
                    $imageData = preg_replace('/^data:image\/\w+;base64,/', '', $failImage);
                    $decoded = base64_decode($imageData, true);

                    if ($decoded !== false) {
                        $area = Str::slug($item['category'] ?? $item['area'] ?? 'general');
                        $itemId = Str::slug($item['id'] ?? $item['label'] ?? $index);
                        $filename = "si_{$area}_{$itemId}_{$timestamp}.jpg";
                        $path = "station-inspections/{$filename}";

                        Storage::disk('public')->put($path, $decoded);

                        $item['failImage'] = $path;
                    }
                }
            }
            unset($item);

            if (isset($formData['checklist'])) {
                $formData['checklist'] = $checklist;
            } else {
                $formData = $checklist;
            }
            $validated['form_data'] = $formData;
        }

        $record = StationInspection::create($validated);

        return response()->json($record->load(['station', 'inspector']), 201);
    }

    /**
     * Public endpoint for the React SPA station inspection form.
     * Accepts the frontend payload shape and transforms it for storage.
     */
    public function storePublic(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'station' => 'required|string',
            'inspection_type' => 'required|string',
            'date' => 'required|date',
            'checklist' => 'required|array',
            'checklist.*.id' => 'required|string',
            'checklist.*.label' => 'required|string',
            'checklist.*.category' => 'required|string',
            'checklist.*.status' => 'required|in:pass,fail,na',
            'extinguishing_system_date' => 'nullable|date',
            'notes' => 'nullable|string',
            'sog_mandate_acknowledged' => 'nullable|boolean',
            'signature' => 'nullable|string',
            'submitted_at' => 'nullable|string',
        ]);

        // Resolve station name to station_id
        $station = \App\Models\Station::where('name', $validated['station'])
            ->orWhere('station_number', $validated['station'])
            ->first();

        if (!$station) {
            return response()->json(['message' => 'Station not found: ' . $validated['station']], 422);
        }

        // Compute overall_status from checklist
        $hasFailures = collect($validated['checklist'])->contains('status', 'fail');
        $overallStatus = $hasFailures ? 'fail' : 'pass';

        // Process fail images in checklist (base64 → stored file)
        $checklist = $validated['checklist'];
        $timestamp = now()->format('Ymd_His');
        foreach ($checklist as $index => &$item) {
            $failImage = $item['failImage'] ?? null;
            if (strtolower($item['status']) === 'fail' && !empty($failImage) && str_contains($failImage, 'base64')) {
                $imageData = preg_replace('/^data:image\/\w+;base64,/', '', $failImage);
                $decoded = base64_decode($imageData, true);
                if ($decoded !== false) {
                    $area = Str::slug($item['category'] ?? 'general');
                    $itemId = Str::slug($item['id'] ?? (string) $index);
                    $filename = "si_{$area}_{$itemId}_{$timestamp}.jpg";
                    $path = "station-inspections/{$filename}";
                    Storage::disk('public')->put($path, $decoded);
                    $item['failImage'] = $path;
                }
            }
        }
        unset($item);

        $record = StationInspection::create([
            'station_id' => $station->id,
            'inspector_id' => null, // Public form — no authenticated user
            'inspection_date' => $validated['date'],
            'inspection_type' => $validated['inspection_type'],
            'form_data' => ['checklist' => $checklist],
            'overall_status' => $overallStatus,
            'inspector_signature' => $validated['signature'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'sog_mandate_acknowledged' => $validated['sog_mandate_acknowledged'] ?? false,
            'extinguishing_system_date' => $validated['extinguishing_system_date'] ?? null,
        ]);

        return response()->json($record->load('station'), 201);
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
