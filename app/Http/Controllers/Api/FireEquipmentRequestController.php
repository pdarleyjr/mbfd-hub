<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StationRequest;
use App\Models\User;
use App\Services\Identity\AuthenticatedMemberContextResolver;
use App\Services\StationRequestLegacyAdapterService;
use App\Services\StationRequestWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Compatibility adapter for the former FireEquipmentRequest API.
 *
 * All reads and writes resolve to StationRequest. The legacy table remains
 * available only as immutable migration evidence.
 */
class FireEquipmentRequestController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'station_id' => ['nullable', 'integer', 'exists:stations,id'],
            'status' => ['nullable', Rule::in([
                'pending', 'approved', 'denied', 'fulfilled', 'completed',
                'shift_chief_approved', 'support_services_approved',
            ])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $query = StationRequest::query()
            ->where('request_type', 'equipment')
            ->with(['station', 'requestedByEmployee:id,name,rank', 'assignedTo:id,name', 'items']);

        if (isset($validated['station_id'])) {
            $query->where('station_id', $validated['station_id']);
        }
        if (isset($validated['status'])) {
            $query->where('status', $this->canonicalStatus($validated['status']));
        }

        return response()->json($query->latest()->paginate((int) ($validated['per_page'] ?? 15)));
    }

    public function store(
        Request $request,
        StationRequestLegacyAdapterService $adapter,
        AuthenticatedMemberContextResolver $memberContextResolver,
    ): JsonResponse {
        $validated = $request->validate([
            'station_id' => ['required', 'exists:stations,id'],
            'requested_by' => ['nullable', 'exists:users,id'],
            'requested_by_name' => ['nullable', 'string', 'max:255'],
            'equipment_type' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:5000'],
            'explanation' => ['nullable', 'string', 'max:5000'],
            'priority' => ['required', Rule::in(['low', 'medium', 'normal', 'high', 'critical'])],
            'form_data' => ['nullable', 'array'],
            'form_data.items' => ['nullable', 'array', 'min:1', 'max:25'],
            'form_data.items.*' => ['array'],
            'form_data.items.*.item_name' => ['nullable', 'string', 'max:255'],
            'form_data.items.*.description' => ['nullable', 'string', 'max:255'],
            'form_data.items.*.category' => ['nullable', 'string', 'max:100'],
            'form_data.items.*.quantity' => ['nullable', 'integer', 'min:1', 'max:100'],
            'form_data.items.*.reason' => ['nullable', Rule::in(['Damaged/Broken', 'Lost', 'Stolen', 'Needed', 'Replacement', 'End of Service Life', 'Other'])],
            'form_data.items.*.pd_case_number' => ['nullable', 'string', 'max:100'],
            'form_data.items.*.photo' => ['nullable', 'string', 'max:7000000'],
            'signature' => ['nullable', 'string', 'max:7000000'],
            'officer_signature' => ['nullable', 'string', 'max:7000000'],
            'pd_case_number' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'client_submission_id' => ['nullable', 'uuid'],
        ]);

        $actor = $memberContextResolver->resolve($request)->actor();
        $actor->requireEmployee();
        $result = $adapter->submitFireEquipment($validated, $actor);

        return response()->json($result->request, $result->created ? 201 : 200);
    }

    public function storePublic(
        Request $request,
        StationRequestLegacyAdapterService $adapter,
        AuthenticatedMemberContextResolver $memberContextResolver,
    ): JsonResponse {
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
            'client_submission_id' => ['nullable', 'uuid'],
        ]);

        $actor = $memberContextResolver->resolve($request)->actor();
        $actor->requireEmployee();
        $result = $adapter->submitFireEquipment($validated, $actor);

        return response()->json($result->request, $result->created ? 201 : 200);
    }

    public function show(int $fireEquipmentRequest): JsonResponse
    {
        return response()->json($this->resolveCanonical($fireEquipmentRequest));
    }

    public function update(
        Request $request,
        int $fireEquipmentRequest,
        StationRequestWorkflowService $workflow,
    ): JsonResponse {
        $validated = $request->validate([
            'equipment_type' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'string', 'max:5000'],
            'explanation' => ['nullable', 'string', 'max:5000'],
            'priority' => ['sometimes', Rule::in(['low', 'medium', 'normal', 'high', 'critical'])],
            'status' => ['sometimes', Rule::in([
                'pending', 'approved', 'denied', 'fulfilled', 'completed',
                'shift_chief_approved', 'support_services_approved',
            ])],
            'notes' => ['nullable', 'string', 'max:5000'],
            'approved_by' => ['nullable', 'exists:users,id'],
            'approved_at' => ['nullable', 'date'],
        ]);
        $canonical = $this->resolveCanonical($fireEquipmentRequest);
        $canonical->update(array_filter([
            'title' => $validated['equipment_type'] ?? null,
            'subject_type' => $validated['equipment_type'] ?? null,
            'description' => $validated['explanation'] ?? $validated['description'] ?? null,
            'priority' => isset($validated['priority']) ? ($validated['priority'] === 'medium' ? 'normal' : $validated['priority']) : null,
        ], static fn ($value): bool => $value !== null));

        if (isset($validated['status'])) {
            /** @var User $actor */
            $actor = $request->user();
            $canonical = $workflow->transition($canonical, [
                'status' => $this->canonicalStatus($validated['status']),
                'public_note' => $validated['notes'] ?? null,
                'internal_note' => 'Updated through the legacy fire equipment compatibility endpoint.',
                'assigned_to_user_id' => $validated['approved_by'] ?? null,
            ], $actor);
        } else {
            $canonical->updates()->create([
                'status' => $canonical->status,
                'internal_note' => 'Request details updated through the legacy fire equipment compatibility endpoint.',
                'changed_by_user_id' => $request->user()?->id,
            ]);
        }

        return response()->json($canonical->fresh(['station', 'requestedByEmployee', 'assignedTo', 'items', 'updates']));
    }

    public function destroy(
        Request $request,
        int $fireEquipmentRequest,
        StationRequestWorkflowService $workflow,
    ): JsonResponse {
        $canonical = $this->resolveCanonical($fireEquipmentRequest);
        /** @var User $actor */
        $actor = $request->user();
        $workflow->transition($canonical, [
            'status' => 'cancelled',
            'public_note' => 'Request cancelled.',
            'internal_note' => 'Legacy delete mapped to a non-destructive canonical cancellation.',
        ], $actor);

        return response()->json(null, 204);
    }

    private function resolveCanonical(int $legacyOrCanonicalId): StationRequest
    {
        return StationRequest::query()
            ->where('request_type', 'equipment')
            ->where(fn ($query) => $query
                ->whereKey($legacyOrCanonicalId)
                ->orWhere(fn ($legacy) => $legacy
                    ->where('legacy_source', 'fire_equipment_requests')
                    ->where('legacy_id', $legacyOrCanonicalId)))
            ->with(['station', 'requestedByEmployee:id,name,rank', 'assignedTo:id,name', 'items', 'updates'])
            ->firstOrFail();
    }

    private function canonicalStatus(string $status): string
    {
        return match ($status) {
            'shift_chief_approved' => 'acknowledged',
            'support_services_approved', 'approved' => 'approved',
            'fulfilled', 'completed' => 'completed',
            'denied' => 'denied',
            default => 'pending',
        };
    }
}
