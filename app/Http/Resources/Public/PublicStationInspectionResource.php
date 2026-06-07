<?php

declare(strict_types=1);

namespace App\Http\Resources\Public;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public, redacted summary of a StationInspection for the unauthenticated
 * daily-checkout SPA inspections tab.
 *
 * SECURITY (H-02): inspector identity and free-text notes are never emitted.
 * Only the inspection type, status, and date are shown.
 */
class PublicStationInspectionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'inspection_date' => $this->inspection_date,
            'inspection_type' => $this->inspection_type,
            'overall_status' => $this->overall_status,
            'created_at' => $this->created_at,
        ];
    }
}
