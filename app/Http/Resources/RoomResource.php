<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RoomResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'station_id' => $this->station_id,
            'name' => $this->name,
            'floor' => $this->floor,
            'room_type' => $this->room_type,
            'description' => $this->description,
            'capacity' => $this->capacity,
            'is_active' => $this->is_active,
            'notes' => $this->notes,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'assets_count' => $this->whenCounted('assets'),
            'assets' => RoomAssetResource::collection($this->whenLoaded('assets')),
            'audits' => RoomAuditResource::collection($this->whenLoaded('audits')),
        ];
    }
}