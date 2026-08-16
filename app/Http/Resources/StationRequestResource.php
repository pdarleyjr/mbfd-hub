<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\StationRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StationRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var StationRequest $stationRequest */
        $stationRequest = $this->resource;

        return [
            'id' => $stationRequest->id,
            'request_number' => $stationRequest->request_number,
            'station_id' => $stationRequest->station_id,
            'room_id' => $stationRequest->room_id,
            'room_name_snapshot' => $stationRequest->room_name_snapshot,
            'requested_by_employee_id' => $stationRequest->requested_by_employee_id,
            'requester_name_snapshot' => $stationRequest->requester_name_snapshot,
            'request_type' => $stationRequest->request_type,
            'subject_type' => $stationRequest->subject_type,
            'title' => $stationRequest->title,
            'description' => $stationRequest->description,
            'priority' => $stationRequest->priority,
            'status' => $stationRequest->status,
            'current_public_response' => $stationRequest->current_public_response,
            'status_detail' => $stationRequest->status_detail,
            'assigned_to_user_id' => $stationRequest->assigned_to_user_id,
            'assigned_vendor' => $stationRequest->assigned_vendor,
            'acknowledged_by' => $stationRequest->acknowledged_by,
            'acknowledged_at' => $stationRequest->acknowledged_at,
            'completed_at' => $stationRequest->completed_at,
            'denied_at' => $stationRequest->denied_at,
            'cancelled_at' => $stationRequest->cancelled_at,
            'legacy_source' => $stationRequest->legacy_source,
            'legacy_id' => $stationRequest->legacy_id,
            'metadata' => $stationRequest->metadata,
            'created_at' => $stationRequest->created_at,
            'updated_at' => $stationRequest->updated_at,
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
