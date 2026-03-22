<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RoomAuditItemResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'room_audit_id' => $this->room_audit_id,
            'room_asset_id' => $this->room_asset_id,
            'item_type' => $this->item_type,
            'expected_quantity' => $this->expected_quantity,
            'actual_quantity' => $this->actual_quantity,
            'discrepancy' => $this->discrepancy,
            'condition_found' => $this->condition_found,
            'finding_type' => $this->finding_type,
            'finding_description' => $this->finding_description,
            'resolved_by' => $this->resolved_by,
            'resolved_at' => $this->resolved_at,
            'resolution_notes' => $this->resolution_notes,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'asset' => $this->whenLoaded('asset'),
            'resolver' => $this->whenLoaded('resolver'),
        ];
    }
}