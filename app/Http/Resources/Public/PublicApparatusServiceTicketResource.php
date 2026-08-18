<?php

declare(strict_types=1);

namespace App\Http\Resources\Public;

use App\Models\ApparatusServiceTicket;
use App\Models\ApparatusServiceTicketUpdate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PublicApparatusServiceTicketResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var ApparatusServiceTicket $ticket */
        $ticket = $this->resource;

        return [
            'id' => $ticket->id,
            'ticket_number' => $ticket->ticket_number,
            'apparatus_id' => $ticket->apparatus_id,
            'station_id' => $ticket->station_id,
            'unit_designation' => $ticket->unit_designation_snapshot,
            'origin' => $ticket->origin,
            'category' => $ticket->category,
            'service_type' => $ticket->service_type,
            'title' => $ticket->title,
            'priority' => $ticket->priority,
            'status' => $ticket->status,
            'is_open' => $ticket->is_open,
            'scheduled_for' => $ticket->scheduled_for?->toIso8601String(),
            'scheduled_location' => $ticket->scheduled_location,
            'expected_return_at' => $ticket->expected_return_at?->toIso8601String(),
            'current_public_response' => $ticket->current_public_response,
            'created_at' => $ticket->created_at?->toIso8601String(),
            'updated_at' => $ticket->updated_at?->toIso8601String(),
            'updates' => $this->whenLoaded('updates', fn (): array => $ticket->updates->map(fn (ApparatusServiceTicketUpdate $update): array => [
                'id' => $update->id,
                'status' => $update->status,
                'public_note' => $update->public_note,
                'scheduled_for' => $update->scheduled_for?->toIso8601String(),
                'created_at' => $update->created_at?->toIso8601String(),
            ])->all()),
        ];
    }
}
