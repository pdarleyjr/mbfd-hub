<?php

declare(strict_types=1);

namespace App\Http\Resources\Public;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PublicApparatusServiceTicketResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'ticket_number' => $this->ticket_number,
            'apparatus_id' => $this->apparatus_id,
            'station_id' => $this->station_id,
            'unit_designation' => $this->unit_designation_snapshot,
            'origin' => $this->origin,
            'category' => $this->category,
            'service_type' => $this->service_type,
            'title' => $this->title,
            'priority' => $this->priority,
            'status' => $this->status,
            'is_open' => $this->is_open,
            'scheduled_for' => $this->scheduled_for?->toIso8601String(),
            'scheduled_location' => $this->scheduled_location,
            'expected_return_at' => $this->expected_return_at?->toIso8601String(),
            'current_public_response' => $this->current_public_response,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'updates' => $this->whenLoaded('updates', fn (): array => $this->updates->map(fn ($update): array => [
                'id' => $update->id,
                'status' => $update->status,
                'public_note' => $update->public_note,
                'scheduled_for' => $update->scheduled_for?->toIso8601String(),
                'created_at' => $update->created_at?->toIso8601String(),
            ])->all()),
        ];
    }
}
