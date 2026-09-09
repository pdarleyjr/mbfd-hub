<?php

declare(strict_types=1);

namespace App\Http\Resources\Public;

use App\Models\StationInventorySubmission;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class PublicStationInventorySubmissionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var StationInventorySubmission $submission */
        $submission = $this->resource;

        return [
            'id' => $submission->id,
            'station_id' => $submission->station_id,
            'shift' => $submission->shift,
            'item_count' => count($submission->items ?? []),
            'submitted_at' => $submission->submitted_at ?: $submission->created_at,
        ];
    }
}
