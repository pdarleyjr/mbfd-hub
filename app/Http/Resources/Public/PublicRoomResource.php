<?php

declare(strict_types=1);

namespace App\Http\Resources\Public;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Schema;

/**
 * Public, redacted view of a Room for the unauthenticated daily-checkout SPA.
 * Allowlist only: name/type/floor/counts the rooms tab renders. Internal notes
 * are never included.
 */
class PublicRoomResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $hasIsActive = Schema::hasColumn('rooms', 'is_active');

        return [
            'id' => $this->id,
            'station_id' => $this->station_id,
            'name' => $this->name,
            'blueprint_key' => $this->resource->getAttribute('blueprint_key'),
            'sort_order' => (int) ($this->resource->getAttribute('sort_order') ?? 1000),
            'floor' => $this->floor,
            'type' => $this->type ?? $this->room_type ?? 'other',
            'capacity' => $this->capacity,
            'is_active' => $hasIsActive ? (bool) $this->is_active : true,
            'assets_count' => $this->whenCounted('assets'),
            'audits_count' => $this->whenCounted('audits'),
        ];
    }
}
