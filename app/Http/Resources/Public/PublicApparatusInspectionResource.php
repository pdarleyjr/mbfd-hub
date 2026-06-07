<?php

declare(strict_types=1);

namespace App\Http\Resources\Public;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public, redacted summary of an ApparatusInspection for the unauthenticated
 * daily-checkout SPA "Today's Inspections" widget.
 *
 * SECURITY (H-02): operator name and rank (personnel identity) are never
 * emitted. Only the apparatus, shift, time, and defect count are shown.
 */
class PublicApparatusInspectionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'apparatus_name' => $this->apparatus?->designation
                ?: $this->apparatus?->name
                ?: $this->apparatus?->unit_id
                ?: 'Unknown',
            'shift' => $this->shift,
            'completed_at' => $this->completed_at ?? $this->created_at,
            'defect_count' => $this->defects()->count(),
        ];
    }
}
