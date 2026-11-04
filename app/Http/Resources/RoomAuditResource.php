<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RoomAuditResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'room_id' => $this->room_id,
            'auditor_id' => $this->auditor_id,
            'status' => $this->status,
            'audit_type' => $this->audit_type,
            'scheduled_date' => $this->scheduled_date,
            'completed_date' => $this->completed_date,
            'findings_summary' => $this->findings_summary,
            'notes' => $this->notes,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'auditor' => $this->whenLoaded('auditor'),
            'items' => RoomAuditItemResource::collection($this->whenLoaded('items')),
        ];
    }
}