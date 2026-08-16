<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ApparatusInspection;
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

        $apparatusInspections = ApparatusInspection::query()
            ->with('apparatus:id,designation,unit_id,name')
            ->whereIn('apparatus_id', $apparatusIds)
            ->latest('completed_at')
            ->limit($limit)
            ->get()
            ->map(fn (ApparatusInspection $inspection): array => [
                'type' => 'apparatus_inspection',
                'label' => 'Apparatus inspection — '.($inspection->apparatus?->designation ?: $inspection->apparatus?->unit_id ?: 'Unit'),
                'status' => $inspection->review_status ?: 'submitted',
                'occurred_at' => $inspection->completed_at ?: $inspection->created_at,
            ]);

        $stationInspections = StationInspection::query()
            ->where('station_id', $station->id)
            ->latest('created_at')
            ->limit($limit)
            ->get()
            ->map(fn (StationInspection $inspection): array => [
                'type' => 'station_inspection',
                'label' => 'Station inspection — '.str($inspection->inspection_type ?: 'inspection')->replace('_', ' ')->title(),
                'status' => $inspection->overall_status ?: 'submitted',
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

        return collect()
            ->concat($apparatusInspections)
            ->concat($stationInspections)
            ->concat($inventory)
            ->concat($supplyRequests)
            ->concat($stationRequests)
            ->sortByDesc('occurred_at')
            ->take($limit)
            ->values();
    }
}
