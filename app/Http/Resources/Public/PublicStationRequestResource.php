<?php

declare(strict_types=1);

namespace App\Http\Resources\Public;

use App\Models\Room;
use App\Models\StationRequest;
use App\Models\StationRequestItem;
use App\Models\StationRequestUpdate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PublicStationRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var StationRequest $stationRequest */
        $stationRequest = $this->resource;

        return [
            'id' => $stationRequest->id,
            'request_number' => $stationRequest->request_number,
            'station_id' => $stationRequest->station_id,
            'room_id' => $stationRequest->room_id,
            'room_name_snapshot' => $stationRequest->room_name_snapshot,
            'room' => $this->whenLoaded('room', function () use ($stationRequest): ?array {
                /** @var Room|null $room */
                $room = $stationRequest->room;

                return $room === null ? null : ['id' => $room->id, 'name' => $room->name];
            }),
            'request_type' => $stationRequest->request_type,
            'subject_type' => $stationRequest->subject_type,
            'title' => $stationRequest->title,
            'description' => $stationRequest->description,
            'priority' => $stationRequest->priority,
            'status' => $stationRequest->status,
            'is_open' => $stationRequest->is_open,
            'current_public_response' => $stationRequest->current_public_response,
            'acknowledged_at' => $stationRequest->acknowledged_at,
            'completed_at' => $stationRequest->completed_at,
            'created_at' => $stationRequest->created_at,
            'updated_at' => $stationRequest->updated_at,
            'items' => $this->whenLoaded('items', fn () => $stationRequest->items->map(fn (StationRequestItem $item): array => [
                'id' => $item->id,
                'room_asset_id' => $item->room_asset_id,
                'item_name' => $item->item_name,
                'category' => $item->category,
                'quantity' => $item->quantity,
                'reason' => $item->reason,
                'requested_action' => $item->requested_action,
                'condition' => $item->condition,
            ])->all()),
            'updates' => $this->whenLoaded('updates', fn () => $stationRequest->updates->map(fn (StationRequestUpdate $update): array => [
                'id' => $update->id,
                'status' => $update->status,
                'public_note' => $update->public_note,
                'created_at' => $update->created_at,
            ])->all()),
        ];
    }
}
