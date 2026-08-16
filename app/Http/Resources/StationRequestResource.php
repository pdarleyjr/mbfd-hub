<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StationRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'request_number' => $this->request_number,
            'station_id' => $this->station_id,
            'room_id' => $this->room_id,
            'room_name_snapshot' => $this->room_name_snapshot,
            'requested_by_employee_id' => $this->requested_by_employee_id,
            'requester_name_snapshot' => $this->requester_name_snapshot,
            'request_type' => $this->request_type,
            'subject_type' => $this->subject_type,
            'title' => $this->title,
            'description' => $this->description,
            'priority' => $this->priority,
            'status' => $this->status,
            'current_public_response' => $this->current_public_response,
            'status_detail' => $this->status_detail,
            'assigned_to_user_id' => $this->assigned_to_user_id,
            'assigned_vendor' => $this->assigned_vendor,
            'acknowledged_by' => $this->acknowledged_by,
            'acknowledged_at' => $this->acknowledged_at,
            'completed_at' => $this->completed_at,
            'denied_at' => $this->denied_at,
            'cancelled_at' => $this->cancelled_at,
            'legacy_source' => $this->legacy_source,
            'legacy_id' => $this->legacy_id,
            'metadata' => $this->metadata,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'station' => $this->whenLoaded('station'),
            'room' => $this->whenLoaded('room'),
            'requested_by_employee' => $this->whenLoaded('requestedByEmployee'),
            'assigned_to' => $this->whenLoaded('assignedTo'),
            'acknowledged_by_user' => $this->whenLoaded('acknowledgedBy'),
            'items' => $this->whenLoaded('items'),
            'updates' => $this->whenLoaded('updates'),
            'asset_events' => $this->whenLoaded('assetEvents'),
        ];
    }
}
