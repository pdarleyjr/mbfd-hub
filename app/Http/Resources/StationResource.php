<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StationResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'address' => $this->address,
            'phone' => $this->phone,
            'station_number' => $this->station_number,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'is_active' => $this->is_active,
            'notes' => $this->notes,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'apparatuses_count' => $this->whenCounted('apparatuses'),
            'rooms_count' => $this->whenCounted('rooms'),
            'capital_projects_count' => $this->whenCounted('capitalProjects'),
            'under_25k_projects_count' => $this->whenCounted('under25kProjects'),
            'apparatuses' => ApparatusResource::collection($this->whenLoaded('apparatuses')),
            'rooms' => RoomResource::collection($this->whenLoaded('rooms')),
            'capital_projects' => CapitalProjectResource::collection($this->whenLoaded('capitalProjects')),
            'under_25k_projects' => Under25kProjectResource::collection($this->whenLoaded('under25kProjects')),
        ];
    }
}