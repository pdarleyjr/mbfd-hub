<?php

declare(strict_types=1);

namespace App\Http\Resources\Public;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public, redacted view of a FireEquipmentRequest for the unauthenticated
 * daily-checkout SPA. Allowlist only: equipment/priority/status/date.
 *
 * SECURITY (H-02): requester identity, internal notes, signatures, case
 * numbers, and approval metadata are never emitted.
 */
class PublicEquipmentRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'equipment_type' => $this->equipment_type,
            'description' => $this->description,
            'priority' => $this->priority,
            'status' => $this->status,
            'created_at' => $this->created_at,
        ];
    }
}
