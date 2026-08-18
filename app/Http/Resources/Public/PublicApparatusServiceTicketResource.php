<?php

declare(strict_types=1);

namespace App\Http\Resources\Public;

use App\Models\ApparatusServiceTicket;
use App\Models\ApparatusServiceTicketUpdate;
use Carbon\CarbonImmutable;
use DateTimeInterface;
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
            'scheduled_for' => $this->iso8601($ticket->scheduled_for),
            'scheduled_location' => $ticket->scheduled_location,
            'expected_return_at' => $this->iso8601($ticket->expected_return_at),
            'current_public_response' => $ticket->current_public_response,
            'created_at' => $this->iso8601($ticket->created_at),
            'updated_at' => $this->iso8601($ticket->updated_at),
            'updates' => $this->whenLoaded('updates', fn (): array => $this->publicUpdates($ticket)),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function publicUpdates(ApparatusServiceTicket $ticket): array
    {
        $updates = [];

        foreach ($ticket->updates as $update) {
            /** @var ApparatusServiceTicketUpdate $update */
            $updates[] = [
                'id' => $update->id,
                'status' => $update->status,
                'public_note' => $update->public_note,
                'scheduled_for' => $this->iso8601($update->scheduled_for),
                'created_at' => $this->iso8601($update->created_at),
            ];
        }

        return $updates;
    }

    private function iso8601(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        return is_string($value) && $value !== ''
            ? CarbonImmutable::parse($value)->toIso8601String()
            : null;
    }
}
