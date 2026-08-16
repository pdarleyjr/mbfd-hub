<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Enums\StationRequestStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\StationRequestResource;
use App\Models\StationRequest;
use App\Models\User;
use App\Services\StationRequestWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class StationRequestController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'station_id' => ['nullable', 'integer', 'exists:stations,id'],
            'room_id' => ['nullable', 'integer', 'exists:rooms,id'],
            'status' => ['nullable', Rule::in(StationRequestStatus::values())],
            'request_type' => ['nullable', 'in:repair_service,equipment'],
            'priority' => ['nullable', Rule::in(['low', 'normal', 'high', 'critical'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $records = StationRequest::query()
            ->with(['station:id,station_number', 'room:id,station_id,name', 'requestedByEmployee:id,name,rank', 'assignedTo:id,name', 'acknowledgedBy:id,name'])
            ->when($validated['station_id'] ?? null, fn ($query, $value) => $query->where('station_id', $value))
            ->when($validated['room_id'] ?? null, fn ($query, $value) => $query->where('room_id', $value))
            ->when($validated['status'] ?? null, fn ($query, $value) => $query->where('status', $value))
            ->when($validated['request_type'] ?? null, fn ($query, $value) => $query->where('request_type', $value))
            ->when($validated['priority'] ?? null, fn ($query, $value) => $query->where('priority', $value))
            ->latest('created_at')
            ->paginate((int) ($validated['per_page'] ?? 25));

        return StationRequestResource::collection($records)->response();
    }

    public function show(StationRequest $stationRequest): StationRequestResource
    {
        return new StationRequestResource($this->load($stationRequest));
    }

    public function transition(
        Request $request,
        StationRequest $stationRequest,
        StationRequestWorkflowService $workflow,
    ): StationRequestResource {
        $validated = $request->validate([
            'status' => ['required', Rule::in(StationRequestStatus::values())],
            'public_note' => ['nullable', 'string', 'max:5000'],
            'internal_note' => ['nullable', 'string', 'max:10000'],
            'status_detail' => ['nullable', 'string', 'max:5000'],
            'assigned_to_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'assigned_vendor' => ['nullable', 'string', 'max:255'],
            'asset_operations' => ['nullable', 'array', 'max:25'],
            'asset_operations.*.operation' => ['required', Rule::in(['create', 'link', 'replace'])],
            'asset_operations.*.station_request_item_id' => ['required', 'integer', 'exists:station_request_items,id'],
            'asset_operations.*.room_id' => ['nullable', 'integer', 'exists:rooms,id'],
            'asset_operations.*.room_asset_id' => ['nullable', 'integer', 'exists:room_assets,id'],
            'asset_operations.*.asset_tag' => ['nullable', 'string', 'max:255'],
            'asset_operations.*.name' => ['nullable', 'string', 'max:255'],
            'asset_operations.*.description' => ['nullable', 'string', 'max:5000'],
            'asset_operations.*.category' => ['nullable', 'string', 'max:100'],
            'asset_operations.*.quantity' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'asset_operations.*.unit' => ['nullable', 'string', 'max:50'],
            'asset_operations.*.condition' => ['nullable', 'string', 'max:100'],
            'asset_operations.*.serial_number' => ['nullable', 'string', 'max:255'],
            'asset_operations.*.manufacturer' => ['nullable', 'string', 'max:255'],
            'asset_operations.*.model_number' => ['nullable', 'string', 'max:255'],
            'asset_operations.*.location_within_room' => ['nullable', 'string', 'max:255'],
            'asset_operations.*.vendor' => ['nullable', 'string', 'max:255'],
            'asset_operations.*.cost' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'asset_operations.*.notes' => ['nullable', 'string', 'max:5000'],
        ]);

        if (($validated['asset_operations'] ?? []) !== []
            && $validated['status'] !== StationRequestStatus::Completed->value) {
            throw ValidationException::withMessages([
                'asset_operations' => 'Asset fulfillment can only be recorded when completing a request.',
            ]);
        }

        foreach (($validated['asset_operations'] ?? []) as $index => $operation) {
            if (in_array($operation['operation'], ['create', 'replace'], true) && blank($operation['name'] ?? null)) {
                throw ValidationException::withMessages(["asset_operations.{$index}.name" => 'An asset name is required.']);
            }
            if ($operation['operation'] === 'create' && blank($operation['room_id'] ?? null)) {
                throw ValidationException::withMessages(["asset_operations.{$index}.room_id" => 'A room is required when creating an asset.']);
            }
            if (in_array($operation['operation'], ['link', 'replace'], true) && blank($operation['room_asset_id'] ?? null)) {
                throw ValidationException::withMessages(["asset_operations.{$index}.room_asset_id" => 'An existing asset is required.']);
            }
        }

        /** @var User $actor */
        $actor = $request->user();
        $updated = $workflow->transition($stationRequest, $validated, $actor);

        return new StationRequestResource($updated);
    }

    private function load(StationRequest $request): StationRequest
    {
        return $request->load([
            'station',
            'room',
            'requestedByEmployee:id,name,rank',
            'assignedTo:id,name',
            'acknowledgedBy:id,name',
            'items.roomAsset',
            'updates.changedBy:id,name',
            'assetEvents.roomAsset',
        ]);
    }
}
