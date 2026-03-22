<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RoomAssetResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'room_id' => $this->room_id,
            'asset_tag' => $this->asset_tag,
            'name' => $this->name,
            'description' => $this->description,
            'category' => $this->category,
            'quantity' => $this->quantity,
            'unit' => $this->unit,
            'condition' => $this->condition,
            'purchase_date' => $this->purchase_date,
            'purchase_price' => $this->purchase_price,
            'serial_number' => $this->serial_number,
            'manufacturer' => $this->manufacturer,
            'model_number' => $this->model_number,
            'location_within_room' => $this->location_within_room,
            'is_active' => $this->is_active,
            'notes' => $this->notes,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}