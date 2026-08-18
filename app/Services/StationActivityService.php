<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Apparatus;
use App\Models\ApparatusInspection;
use App\Models\ApparatusServiceTicketUpdate;
use App\Models\Station;
use App\Models\StationInspection;
use App\Models\StationInventorySubmission;
use App\Models\StationRequest;
use App\Models\StationSupplyRequest;
use Illuminate\Support\Collection;

class StationActivityService
{
    /** @return Collection<int, array<string, mixed>> */
    public function forStation(Station $station, int $limit = 50): Collection
    {
        $apparatusIds = $station->apparatuses()->pluck('id');
        /** @var Collection<int, Apparatus> $apparatusById */
        $apparatusById = Apparatus::query()
            ->whereKey($apparatusIds)
            ->get(['id', 'designation', 'unit_id'])
            ->keyBy('id');

        $apparatusInspections = ApparatusInspection::query()
            ->whereIn('apparatus_id', $apparatusIds)
            ->latest('completed_at')
            ->limit($limit)
            ->get()
            ->map(function (ApparatusInspection $inspection) use ($apparatusById): array {
                $apparatus = $apparatusById->get((int) $inspection->apparatus_id);

                return [
                    'type' => 'apparatus_inspection',
                    'label' => 'Apparatus inspection — '.($apparatus?->designation ?: $apparatus?->getAttribute('unit_id') ?: 'Unit'),
                    'status' => $inspection->review_status ?: 'submitted',
                    'occurred_at' => $inspection->completed_at ?: $inspection->created_at,
                ];
            });

        $stationInspections = StationInspection::query()
            ->where('station_id', $station->id)
            ->latest('created_at')
            ->limit($limit)
            ->get()
            ->map(fn (StationInspection $inspection): array => [
                'type' => 'station_inspection',
                'label' => 'Station inspection — '.str($inspection->inspection_type ?: 'inspection')->replace('_', ' ')->title(),
                'status' => $inspection->overall_status,
                'occurred_at' => $inspection->created_at,
            ]);

        $inventory = StationInventorySubmission::query()
            ->where('station_id', $station->id)
            ->latest('submitted_at')
            ->limit($limit)
            ->get()
            ->map(fn (StationInventorySubmission $submission): array => [
                'type' => 'inventory_submission',
                'label' => 'Station inventory submitted',
                'status' => 'submitted',
                'occurred_at' => $submission->submitted_at ?: $submission->created_at,
            ]);

        $supplyRequests = StationSupplyRequest::query()
            ->where('station_id', $station->id)
            ->latest('created_at')
            ->limit($limit)
            ->get()
            ->map(fn (StationSupplyRequest $supply): array => [
                'type' => 'supply_request',
                'label' => 'Supply request',
                'status' => $supply->status,
                'occurred_at' => $supply->created_at,
            ]);

        $stationRequests = StationRequest::query()
            ->where('station_id', $station->id)
            ->latest('created_at')
            ->limit($limit)
            ->get()
            ->map(fn (StationRequest $request): array => [
                'type' => 'station_request',
                'label' => "{$request->request_number} — {$request->title}",
                'status' => $request->status,
                'request_type' => $request->request_type,
                'request_number' => $request->request_number,
                'occurred_at' => $request->created_at,
            ]);

        $apparatusServiceTicketUpdates = ApparatusServiceTicketUpdate::query()
            ->whereHas('ticket', fn ($query) => $query->where('station_id', $station->id))
            ->with('ticket:id,station_id,ticket_number,unit_designation_snapshot,title')
            ->latest('created_at')
            ->latest('id')
            ->limit($limit)
            ->get(['id', 'apparatus_service_ticket_id', 'status', 'created_at'])
            ->map(function (ApparatusServiceTicketUpdate $update): array {
                $ticket = $update->ticket;

                return [
                    'type' => 'apparatus_service_ticket',
                    'label' => "{$ticket->ticket_number} — {$ticket->unit_designation_snapshot}: {$ticket->title}",
                    'status' => $update->status,
                    'request_number' => $ticket->ticket_number,
                    'occurred_at' => $update->created_at,
                ];
            });

        return collect()
            ->concat($apparatusInspections)
            ->concat($stationInspections)
            ->concat($inventory)
            ->concat($supplyRequests)
            ->concat($stationRequests)
            ->concat($apparatusServiceTicketUpdates)
            ->sortByDesc('occurred_at')
            ->take($limit)
            ->values();
    }
}
