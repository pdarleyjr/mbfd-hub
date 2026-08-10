<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FireEquipmentRequest;
use App\Models\Station;
use App\Support\Security\Base64Image;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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

    /**
     * Accept the public Daily Forms SPA payload and persist the complete request
     * for the authenticated admin workflow.
     */
    public function storePublic(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'station' => ['required', 'string', 'max:100'],
            'date' => ['required', 'date'],
            'requested_by' => ['required', 'string', 'max:255'],
            'items' => ['required', 'array', 'min:1', 'max:25'],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:100'],
            'items.*.reason' => ['required', Rule::in(['Damaged/Broken', 'Lost', 'Stolen', 'Needed'])],
            'items.*.pd_case_number' => ['nullable', 'string', 'max:100', 'required_if:items.*.reason,Stolen'],
            'items.*.photo' => ['nullable', 'string', 'max:7000000'],
            'explanation' => ['required', 'string', 'max:5000'],
            'member_signature' => ['nullable', 'string', 'max:7000000'],
            'officer_signature' => ['nullable', 'string', 'max:7000000'],
            'submitted_at' => ['nullable', 'date'],
        ]);

        $stationNumber = $validated['station'];
        if (preg_match('/^Station\s+(\d+)$/i', $stationNumber, $matches)) {
            $stationNumber = $matches[1];
        }

        $station = Station::query()->where('station_number', $stationNumber)->first();
        if ($station === null) {
            throw ValidationException::withMessages([
                'station' => "Station not found: {$validated['station']}",
            ]);
        }

        $items = $validated['items'];
        foreach ($items as $index => &$item) {
            if (filled($item['photo'] ?? null)) {
                $item['photo'] = $this->storeImageOrFail(
                    $item['photo'],
                    'fire-equipment-requests/photos',
                    "request-item-{$index}",
                    "items.{$index}.photo",
                );
            }
        }
        unset($item);

        $memberSignature = filled($validated['member_signature'] ?? null)
            ? $this->storeImageOrFail(
                $validated['member_signature'],
                'fire-equipment-requests/signatures',
                'member-signature',
                'member_signature',
            )
            : null;
        $officerSignature = filled($validated['officer_signature'] ?? null)
            ? $this->storeImageOrFail(
                $validated['officer_signature'],
                'fire-equipment-requests/signatures',
                'officer-signature',
                'officer_signature',
            )
            : null;

        $reasons = collect($items)->pluck('reason');
        $priority = $reasons->contains(fn (string $reason): bool => in_array($reason, ['Lost', 'Stolen'], true))
            ? 'high'
            : ($reasons->contains('Damaged/Broken') ? 'medium' : 'low');
        $policeCases = collect($items)
            ->pluck('pd_case_number')
            ->filter()
            ->unique()
            ->implode(', ');
        $description = collect($items)
            ->map(fn (array $item): string => "{$item['quantity']}x {$item['description']}")
            ->implode('; ');

        $record = FireEquipmentRequest::create([
            'station_id' => $station->id,
            'requested_by' => null,
            'requested_by_name' => $validated['requested_by'],
            'equipment_type' => count($items) === 1 ? $items[0]['description'] : count($items).' requested items',
            'description' => $description,
            'explanation' => $validated['explanation'],
            'priority' => $priority,
            'status' => 'pending',
            'form_data' => [
                'date' => $validated['date'],
                'submitted_at' => $validated['submitted_at'] ?? now()->toIso8601String(),
                'items' => $items,
            ],
            'signature' => $memberSignature,
            'officer_signature' => $officerSignature,
            'pd_case_number' => $policeCases !== '' ? $policeCases : null,
        ]);

        return response()->json($record->load('station'), 201);
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

    private function storeImageOrFail(string $payload, string $directory, string $prefix, string $field): string
    {
        $path = Base64Image::store($payload, $directory, $prefix);

        if ($path === null) {
            throw ValidationException::withMessages([
                $field => 'The uploaded image must be a valid JPEG, PNG, WebP, or GIF image.',
            ]);
        }

        return $path;
    }
}
