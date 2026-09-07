<?php

declare(strict_types=1);

namespace App\Http\Resources\Public;

use App\Models\Apparatus;
use App\Models\ApparatusInspection;
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
        /** @var ApparatusInspection $inspection */
        $inspection = $this->resource;
        $apparatusRelation = $inspection->getRelationValue('apparatus');
        $apparatus = $apparatusRelation instanceof Apparatus ? $apparatusRelation : null;

        return [
            'id' => $inspection->id,
            'inspection_reference' => $inspection->inspection_reference,
            'apparatus_name' => $apparatus?->designation
                ?: $apparatus?->name
                ?: $apparatus?->getAttribute('unit_id')
                ?: 'Unknown',
            'shift' => $inspection->shift,
            'completed_at' => $inspection->completed_at ?? $inspection->created_at,
            'defect_count' => (int) ($inspection->getAttribute('defects_count') ?? $inspection->defects()->count()),
            'review_status' => $inspection->review_status ?: 'pending_review',
        ];
    }
}
