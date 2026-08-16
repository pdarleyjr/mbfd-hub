<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\StationRequestStatus;
use App\Models\Room;
use App\Models\RoomAsset;
use App\Models\StationRequest;
use App\Models\StationRequestItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StationRequestWorkflowService
{
    public function __construct(private readonly StationRequestSideEffectService $sideEffects) {}

    /** @param array<string, mixed> $data */
    public function transition(StationRequest $stationRequest, array $data, User $actor): StationRequest
    {
        return DB::transaction(function () use ($stationRequest, $data, $actor): StationRequest {
            /** @var StationRequest $request */
            $request = StationRequest::query()->lockForUpdate()->findOrFail($stationRequest->id);
            $newStatus = (string) $data['status'];
            $wasCompleted = $request->status === StationRequestStatus::Completed->value;
            $assetOperations = (array) ($data['asset_operations'] ?? []);

            if (in_array($request->status, StationRequestStatus::terminalValues(), true)
                && $request->status !== $newStatus) {
                throw ValidationException::withMessages([
                    'status' => 'A terminal request cannot be moved to another status.',
                ]);
            }
            if ($assetOperations !== []
                && $newStatus !== StationRequestStatus::Completed->value) {
                throw ValidationException::withMessages([
                    'asset_operations' => 'Asset fulfillment can only be recorded when completing a request.',
                ]);
            }
            $itemIds = collect($assetOperations)->pluck('station_request_item_id')->filter()->values();
            if ($itemIds->unique()->count() !== $itemIds->count()) {
                throw ValidationException::withMessages([
                    'asset_operations' => 'Each request item can have only one asset fulfillment operation.',
                ]);
            }

            $attributes = [
                'status' => $newStatus,
                'status_detail' => $data['status_detail'] ?? $request->status_detail,
                'assigned_to_user_id' => array_key_exists('assigned_to_user_id', $data)
                    ? $data['assigned_to_user_id']
                    : $request->assigned_to_user_id,
                'assigned_vendor' => array_key_exists('assigned_vendor', $data)
                    ? $data['assigned_vendor']
                    : $request->assigned_vendor,
            ];
            if (filled($data['public_note'] ?? null)) {
                $attributes['current_public_response'] = trim((string) $data['public_note']);
            }
            if ($newStatus === StationRequestStatus::Acknowledged->value && $request->acknowledged_at === null) {
                $attributes['acknowledged_at'] = now();
                $attributes['acknowledged_by'] = $actor->id;
            }
            if ($newStatus === StationRequestStatus::Completed->value && $request->completed_at === null) {
                $attributes['completed_at'] = now();
            }
            if ($newStatus === StationRequestStatus::Denied->value && $request->denied_at === null) {
                $attributes['denied_at'] = now();
            }
            if ($newStatus === StationRequestStatus::Cancelled->value && $request->cancelled_at === null) {
                $attributes['cancelled_at'] = now();
            }

            $request->update($attributes);
            $request->updates()->create([
                'status' => $newStatus,
                'public_note' => $data['public_note'] ?? null,
                'internal_note' => $data['internal_note'] ?? null,
                'changed_by_user_id' => $actor->id,
                'metadata' => ['event' => 'workflow_transition'],
            ]);

            if (! $wasCompleted && $newStatus === StationRequestStatus::Completed->value) {
                foreach ($assetOperations as $operation) {
                    $this->applyAssetOperation($request, $operation, $actor);
                }
            }

            if (! $wasCompleted
                && $newStatus === StationRequestStatus::Completed->value
                && $request->request_type === 'repair_service') {
                $request->unsetRelation('items');
                $this->recordRepairCompletionEvents($request, $actor);
            }

            DB::afterCommit(fn () => $this->sideEffects->requestChanged($request));

            return $request->fresh([
                'station:id,station_number',
                'room:id,station_id,name',
                'requestedByEmployee:id,name,rank',
                'assignedTo:id,name',
                'acknowledgedBy:id,name',
                'items.roomAsset',
                'updates.changedBy:id,name',
                'assetEvents.roomAsset',
            ]) ?? $request;
        }, 3);
    }

    /** @param array<string, mixed> $operation */
    private function applyAssetOperation(StationRequest $request, array $operation, User $actor): void
    {
        $kind = $operation['operation'];
        $item = $this->requestItem($request, (int) $operation['station_request_item_id']);
        if ($kind === 'create') {
            $room = $this->stationRoom($request, (int) $operation['room_id']);
            $asset = $room->assets()->create($this->assetAttributes($operation));
            $item->update(['room_asset_id' => $asset->id]);
            $this->recordEvent($asset, $request, 'created_from_request', $operation, $actor);

            return;
        }

        $asset = RoomAsset::query()->with('room')->lockForUpdate()->findOrFail($operation['room_asset_id']);
        if ((int) $asset->room->station_id !== (int) $request->station_id) {
            throw ValidationException::withMessages([
                'asset_operations' => 'Every asset operation must remain within the request station.',
            ]);
        }

        if ($kind === 'link') {
            if (filled($operation['condition'] ?? null) && $asset->condition !== $operation['condition']) {
                $previousCondition = $asset->condition;
                $asset->update(['condition' => $operation['condition']]);
                $this->recordEvent($asset, $request, 'condition_changed', $operation, $actor, [
                    'previous_condition' => $previousCondition,
                    'new_condition' => $operation['condition'],
                ]);
            }
            $item->update(['room_asset_id' => $asset->id]);
            $this->recordEvent($asset, $request, 'linked_to_request', $operation, $actor);

            return;
        }

        if ($kind !== 'replace') {
            throw ValidationException::withMessages(['asset_operations' => 'Unsupported room asset operation.']);
        }

        $roomId = (int) ($operation['room_id'] ?? $asset->room_id);
        $room = $this->stationRoom($request, $roomId);
        $replacement = $room->assets()->create($this->assetAttributes($operation));
        $asset->update([
            'is_active' => false,
            'retired_at' => now(),
            'replaced_by_room_asset_id' => $replacement->id,
        ]);
        $item->update(['room_asset_id' => $replacement->id]);
        $this->recordEvent($asset, $request, 'replaced', $operation, $actor, [
            'replacement_room_asset_id' => $replacement->id,
        ]);
        $this->recordEvent($replacement, $request, 'created_as_replacement', $operation, $actor, [
            'replaced_room_asset_id' => $asset->id,
        ]);
    }

    private function requestItem(StationRequest $request, int $itemId): StationRequestItem
    {
        $item = $request->items()->lockForUpdate()->whereKey($itemId)->first();
        if ($item === null) {
            throw ValidationException::withMessages([
                'asset_operations' => 'Every asset operation must target an item on this request.',
            ]);
        }

        return $item;
    }

    private function recordRepairCompletionEvents(StationRequest $request, User $actor): void
    {
        $request->loadMissing('items.roomAsset');
        foreach ($request->items as $item) {
            if ($item->roomAsset === null) {
                continue;
            }
            $alreadyRecorded = $item->roomAsset->events()
                ->where('station_request_id', $request->id)
                ->where('event_type', 'repair_completed')
                ->exists();
            if (! $alreadyRecorded) {
                $this->recordEvent($item->roomAsset, $request, 'repair_completed', [], $actor);
            }
        }
    }

    private function stationRoom(StationRequest $request, int $roomId): Room
    {
        $room = Room::query()->whereKey($roomId)->where('station_id', $request->station_id)->first();
        if ($room === null) {
            throw ValidationException::withMessages([
                'asset_operations' => 'Every asset must be created in a room at the request station.',
            ]);
        }

        return $room;
    }

    /** @param array<string, mixed> $operation @return array<string, mixed> */
    private function assetAttributes(array $operation): array
    {
        return [
            'asset_tag' => $operation['asset_tag'] ?? null,
            'name' => $operation['name'],
            'description' => $operation['description'] ?? null,
            'category' => $operation['category'] ?? null,
            'quantity' => $operation['quantity'] ?? 1,
            'unit' => $operation['unit'] ?? null,
            'condition' => $operation['condition'] ?? 'new',
            'serial_number' => $operation['serial_number'] ?? null,
            'manufacturer' => $operation['manufacturer'] ?? null,
            'model_number' => $operation['model_number'] ?? null,
            'location_within_room' => $operation['location_within_room'] ?? null,
            'is_active' => true,
            'notes' => $operation['notes'] ?? null,
        ];
    }

    /** @param array<string, mixed> $operation @param array<string, mixed> $metadata */
    private function recordEvent(
        RoomAsset $asset,
        StationRequest $request,
        string $eventType,
        array $operation,
        User $actor,
        array $metadata = [],
    ): void {
        $asset->events()->create([
            'station_request_id' => $request->id,
            'event_type' => $eventType,
            'event_at' => now(),
            'changed_by_user_id' => $actor->id,
            'vendor' => $operation['vendor'] ?? $request->assigned_vendor,
            'cost' => $operation['cost'] ?? null,
            'notes' => $operation['notes'] ?? null,
            'metadata' => $metadata ?: null,
        ]);
    }
}
