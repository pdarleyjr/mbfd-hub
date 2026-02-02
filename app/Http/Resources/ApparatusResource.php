<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ApparatusResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type,
            'unit_number' => $this->unit_number,
            'year' => $this->year,
            'make' => $this->make,
            'model' => $this->model,
            'vin' => $this->vin,
            'plate' => $this->plate,
            'current_mileage' => $this->current_mileage,
            'current_hours' => $this->current_hours,
            'fuel_type' => $this->fuel_type,
            'capacity' => $this->capacity,
            'gpm' => $this->gpm,
            'tank_capacity' => $this->tank_capacity,
            'station_id' => $this->station_id,
            'is_active' => $this->is_active,
            'notes' => $this->notes,
            'current_defects_count' => $this->whenCounted('currentDefects'),
            'current_defects' => $this->whenLoaded('currentDefects'),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}