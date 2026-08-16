<?php

declare(strict_types=1);

namespace App\Http\Resources\Public;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PublicStationRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'request_number' => $this->request_number,
            'station_id' => $this->station_id,
            'room_id' => $this->room_id,
            'room_name_snapshot' => $this->room_name_snapshot,
            'room' => $this->whenLoaded('room', fn (): ?array => $this->room === null ? null : [
                'id' => $this->room->id,
                'name' => $this->room->name,
            ]),
            'request_type' => $this->request_type,
            'subject_type' => $this->subject_type,
            'title' => $this->title,
            'description' => $this->description,
            'priority' => $this->priority,
            'status' => $this->status,
            'is_open' => $this->is_open,
            'current_public_response' => $this->current_public_response,
            'acknowledged_at' => $this->acknowledged_at,
            'completed_at' => $this->completed_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item): array => [
                'id' => $item->id,
                'room_asset_id' => $item->room_asset_id,
                'item_name' => $item->item_name,
                'category' => $item->category,
                'quantity' => $item->quantity,
                'reason' => $item->reason,
                'requested_action' => $item->requested_action,
                'condition' => $item->condition,
            ])->all()),
            'updates' => $this->whenLoaded('updates', fn () => $this->updates->map(fn ($update): array => [
                'id' => $update->id,
                'status' => $update->status,
                'public_note' => $update->public_note,
                'created_at' => $update->created_at,
            ])->all()),
        ];
    }
}
