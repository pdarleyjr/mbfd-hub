<?php

declare(strict_types=1);

namespace App\Http\Resources\Public;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public, redacted view of a Station for the unauthenticated daily-checkout SPA.
 *
 * Allowlist only: emits the location/identity fields the public UI lists with.
 * Internal operational data (notes, internal-only metadata) is never included.
 */
class PublicStationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $staffing = $this->resource->getAttribute('staffing_summary');

        return [
            'id' => $this->id,
            'name' => $this->getRawOriginal('name') ?: $this->name,
            'station_number' => $this->station_number,
            'address' => $this->address ?? '',
            'city' => $this->city ?? '',
            'state' => $this->state ?? '',
            'zip_code' => $this->zip_code ?? '',
            'phone' => $this->phone ?? '',
            'is_active' => (bool) $this->is_active,
            'apparatuses_count' => is_array($staffing) ? $staffing['assigned_apparatus_count'] : $this->whenCounted('apparatuses'),
            'assigned_apparatus_count' => is_array($staffing) ? $staffing['assigned_apparatus_count'] : null,
            'in_service_assigned_count' => is_array($staffing) ? $staffing['in_service_assigned_count'] : null,
            'assigned_personnel_count' => is_array($staffing) ? $staffing['assigned_personnel_count'] : null,
            'dorm_beds_count' => is_array($staffing) ? $staffing['dorm_beds_count'] : null,
            'staffing_known' => is_array($staffing) ? $staffing['known'] : false,
            'rooms_count' => $this->whenCounted('rooms'),
            'capital_projects_count' => $this->whenCounted('capitalProjects'),
            'apparatuses' => PublicApparatusResource::collection(
                $this->whenLoaded('apparatuses')
            ),
            'rooms' => PublicRoomResource::collection(
                $this->whenLoaded('rooms')
            ),
        ];
    }
}
