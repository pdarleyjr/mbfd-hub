<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StationInspection;
use App\Services\Identity\AuthenticatedMemberContextResolver;
use App\Support\Security\Base64Image;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

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

    /**
     * Public endpoint for the React SPA station inspection form.
     * Accepts the frontend payload shape and transforms it for storage.
     */
    public function storePublic(Request $request, AuthenticatedMemberContextResolver $memberContextResolver): JsonResponse
    {
        $actor = $memberContextResolver->resolve($request)->actor();
        $actor->requireEmployee();
        $validated = $request->validate([
            'client_submission_id' => 'nullable|uuid',
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
            'checklist.*.failImage' => 'nullable|string|max:7000000',
        ]);
        if (isset($validated['client_submission_id'])) {
            $existing = StationInspection::query()
                ->where('client_submission_id', $validated['client_submission_id'])
                ->first();
            if ($existing instanceof StationInspection) {
                return $this->idempotentResponse($existing, $actor->userId());
            }
        }

        // Resolve station name to station_id
        // 'name' is an accessor on Station, not a real column. Extract station_number.
        $stationValue = $validated['station'];
        // Handle "Station X" format → extract the number
        if (preg_match('/^Station\s+(\d+)$/i', $stationValue, $matches)) {
            $stationValue = $matches[1];
        }
        $station = \App\Models\Station::where('station_number', $stationValue)->first();

        if (! $station) {
            return response()->json(['message' => 'Station not found: '.$validated['station']], 422);
        }

        // Compute overall_status from checklist
        $hasFailures = collect($validated['checklist'])->contains('status', 'fail');
        $overallStatus = $hasFailures ? 'fail' : 'pass';

        // Process fail images in checklist (base64 → stored file)
        $checklist = $validated['checklist'];
        $timestamp = now()->format('Ymd_His');
        foreach ($checklist as $index => &$item) {
            $failImage = $item['failImage'] ?? null;
            if (strtolower($item['status']) === 'fail' && ! empty($failImage) && str_contains($failImage, 'base64')) {
                $area = Str::slug($item['category'] ?? 'general');
                $itemId = Str::slug($item['id'] ?? (string) $index);
                $item['failImage'] = $this->storeFailImageOrFail($failImage, "si_{$area}_{$itemId}_{$timestamp}");
            }
        }
        unset($item);

        $record = StationInspection::create([
            'client_submission_id' => $validated['client_submission_id'] ?? null,
            'station_id' => $station->id,
            'inspector_id' => $actor->userId(),
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

    private function storeFailImageOrFail(string $payload, string $prefix): string
    {
        $path = Base64Image::store($payload, 'station-inspections', $prefix);

        if ($path === null) {
            throw ValidationException::withMessages([
                'failImage' => 'The uploaded fail image must be a valid JPEG, PNG, WebP, or GIF image.',
            ]);
        }

        return $path;
    }

    private function idempotentResponse(StationInspection $inspection, int $actorUserId): JsonResponse
    {
        if ((int) $inspection->inspector_id !== $actorUserId) {
            return response()->json([
                'message' => 'This queued submission belongs to a different authenticated account.',
                'code' => 'OFFLINE_QUEUE_OWNER_MISMATCH',
            ], 409);
        }

        return response()->json($inspection->load(['station', 'inspector']));
    }
}
