<?php

declare(strict_types=1);

namespace App\Http\Resources\Public;

use App\Models\StationInspection;
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
        /** @var StationInspection $inspection */
        $inspection = $this->resource;

        return [
            'id' => $inspection->id,
            'inspection_date' => $inspection->inspection_date->format('Y-m-d'),
            'inspection_type' => $inspection->inspection_type,
            'overall_status' => $inspection->overall_status,
            'review_status' => $inspection->review_status ?: ($inspection->reviewed_at ? 'reviewed' : 'pending_review'),
            'created_at' => $inspection->created_at,
        ];
    }
}
