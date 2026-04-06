<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Resources\DefectResource;
use App\Filament\Resources\FireEquipmentRequestResource;
use App\Filament\Resources\InspectionResource;
use App\Filament\Resources\StationInspectionResource;
use App\Filament\Resources\StationResource;
use App\Models\ApparatusDefect;
use App\Models\ApparatusInspection;
use App\Models\BigTicketRequest;
use App\Models\FireEquipmentRequest;
use App\Models\Station;
use App\Models\StationInspection;
use App\Models\StationSupplyRequest;
use Filament\Widgets\Widget;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class StationOperationsHubWidget extends Widget
{
    protected static string $view = 'filament.widgets.station-operations-hub-widget';

    protected static ?int $sort = 2;

    protected int | string | array $columnSpan = 'full';

    protected static ?string $pollingInterval = '30s';

    /** @var array<int, list<int>> station_id => [apparatus_ids] */
    protected array $stationApparatusMap = [];

    /**
     * Reload data on every render cycle (including poll ticks).
     * mount() is only called once; getViewData() runs on every render.
     */
    public function getViewData(): array
    {
        $stationModels = Station::with('apparatuses:id,station_id,unit_id,designation')
            ->where('is_active', true)
            ->orderBy('station_number')
            ->get(['id', 'station_number']);

        $stations = $stationModels->map(fn (Station $s) => [
            'id' => $s->id,
            'station_number' => $s->station_number,
        ])->values()->toArray();

        $this->stationApparatusMap = $stationModels->mapWithKeys(
            fn (Station $s) => [$s->id => $s->apparatuses->pluck('id')->toArray()]
        )->toArray();

        $stationData = $this->loadAllStationData($stations);

        return [
            'stations' => $stations,
            'stationData' => $stationData,
        ];
    }

    protected function loadAllStationData(array $stations): array
    {
        $stationIds = collect($stations)->pluck('id')->toArray();
        $allApparatusIds = collect($this->stationApparatusMap)->flatten()->toArray();

        // Batch queries with eager loading
        $todayInspections = $this->getTodayVehicleInspections($allApparatusIds);
        $stationInspections = $this->getStationInspections($stationIds);
        $equipmentRequests = $this->getFireEquipmentRequests($stationIds);
        $bigTicketRequests = $this->getBigTicketRequests($stationIds);
        $defects = $this->getUnresolvedDefects($allApparatusIds);
        $supplyRequests = $this->getOpenSupplyRequests($stationIds);

        $data = [];
        foreach ($stations as $station) {
            $sid = $station['id'];
            $apparatusIds = $this->stationApparatusMap[$sid] ?? [];

            $stationVehicleInspections = $todayInspections->filter(
                fn ($i) => in_array($i->apparatus_id, $apparatusIds)
            )->values();

            $stationDefects = $defects->filter(
                fn ($d) => in_array($d->apparatus_id, $apparatusIds)
            )->values();

            $stationEquipReqs = $equipmentRequests->where('station_id', $sid)->values();
            $stationBigTicket = $bigTicketRequests->where('station_id', $sid)->values();
            $stationStationInsp = $stationInspections->where('station_id', $sid)->values();
            $stationSupplyReqs = $supplyRequests->where('station_id', $sid)->values();

            $data[$sid] = [
                'vehicleInspections' => $this->formatVehicleInspections($stationVehicleInspections),
                'stationInspections' => $this->formatStationInspections($stationStationInsp),
                'equipmentRequests' => $this->formatEquipmentRequests($stationEquipReqs),
                'bigTicketRequests' => $this->formatBigTicketRequests($stationBigTicket, $sid),
                'defects' => $this->formatDefects($stationDefects),
                'supplyRequests' => $this->formatSupplyRequests($stationSupplyReqs, $sid),
                'counts' => [
                    'vehicleInspections' => $stationVehicleInspections->count(),
                    'stationInspections' => $stationStationInsp->count(),
                    'equipmentRequests' => $stationEquipReqs->count(),
                    'bigTicketRequests' => $stationBigTicket->count(),
                    'defects' => $stationDefects->count(),
                    'supplyRequests' => $stationSupplyReqs->count(),
                ],
            ];
        }

        return $data;
    }

    // ── Query methods ────────────────────────────────────────────

    private function getTodayVehicleInspections(array $apparatusIds): Collection
    {
        if (empty($apparatusIds)) {
            return collect();
        }

        return ApparatusInspection::with('apparatus:id,station_id,unit_id,designation')
            ->whereIn('apparatus_id', $apparatusIds)
            ->whereDate('created_at', Carbon::today())
            ->orderByDesc('created_at')
            ->limit(50)
            ->get(['id', 'apparatus_id', 'operator_name', 'shift', 'completed_at', 'created_at']);
    }

    private function getStationInspections(array $stationIds): Collection
    {
        return StationInspection::with('inspector:id,name')
            ->whereIn('station_id', $stationIds)
            ->where('inspection_date', '>=', Carbon::now()->subDays(30))
            ->orderByDesc('inspection_date')
            ->limit(50)
            ->get(['id', 'station_id', 'inspector_id', 'inspection_date', 'inspection_type', 'overall_status']);
    }

    private function getFireEquipmentRequests(array $stationIds): Collection
    {
        return FireEquipmentRequest::whereIn('station_id', $stationIds)
            ->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")
            ->orderByDesc('created_at')
            ->limit(50)
            ->get(['id', 'station_id', 'equipment_type', 'requested_by_name', 'priority', 'status', 'created_at']);
    }

    private function getBigTicketRequests(array $stationIds): Collection
    {
        return BigTicketRequest::with('creator:id,name')
            ->whereIn('station_id', $stationIds)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get(['id', 'station_id', 'room_type', 'items', 'created_by', 'created_at']);
    }

    private function getUnresolvedDefects(array $apparatusIds): Collection
    {
        if (empty($apparatusIds)) {
            return collect();
        }

        return ApparatusDefect::with('apparatus:id,station_id,unit_id,designation')
            ->whereIn('apparatus_id', $apparatusIds)
            ->where('resolved', false)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get(['id', 'apparatus_id', 'item', 'issue_type', 'status', 'reported_date', 'created_at']);
    }

    private function getOpenSupplyRequests(array $stationIds): Collection
    {
        return StationSupplyRequest::whereIn('station_id', $stationIds)
            ->where('status', 'open')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get(['id', 'station_id', 'request_text', 'created_by_name', 'created_by_shift', 'created_at']);
    }

    // ── Formatting methods (convert to arrays with URLs) ─────────

    private function formatVehicleInspections(Collection $inspections): array
    {
        return $inspections->map(fn (ApparatusInspection $i) => [
            'id' => $i->id,
            'unit' => $i->apparatus?->designation ?? $i->apparatus?->unit_id ?? 'Unknown',
            'operator' => $i->operator_name ?? 'Unknown',
            'shift' => $i->shift ?? '',
            'time' => $i->completed_at
                ? Carbon::parse($i->completed_at)->format('g:i A')
                : ($i->created_at?->format('g:i A') ?? ''),
            'url' => InspectionResource::getUrl('view', ['record' => $i->id]),
        ])->toArray();
    }

    private function formatStationInspections(Collection $inspections): array
    {
        return $inspections->map(fn (StationInspection $i) => [
            'id' => $i->id,
            'date' => Carbon::parse($i->inspection_date)->format('M j, Y'),
            'type' => str_replace('_', ' ', ucfirst($i->inspection_type ?? '')),
            'inspector' => $i->inspector?->name ?? 'Unknown',
            'status' => $i->overall_status ?? 'pending',
            'url' => StationInspectionResource::getUrl('view', ['record' => $i->id]),
        ])->toArray();
    }

    private function formatEquipmentRequests(Collection $requests): array
    {
        return $requests->map(fn (FireEquipmentRequest $r) => [
            'id' => $r->id,
            'equipment_type' => $r->equipment_type ?? 'Unknown',
            'requested_by' => $r->requested_by_name ?? 'Unknown',
            'priority' => $r->priority ?? 'medium',
            'status' => $r->status ?? 'pending',
            'date' => $r->created_at?->format('M j, Y') ?? '',
            'url' => FireEquipmentRequestResource::getUrl('view', ['record' => $r->id]),
        ])->toArray();
    }

    private function formatBigTicketRequests(Collection $requests, int $stationId): array
    {
        return $requests->map(fn (BigTicketRequest $r) => [
            'id' => $r->id,
            'room' => str_replace('_', ' ', ucfirst($r->room_type ?? '')),
            'items' => $this->summarizeBigTicketItems($r->items),
            'created_by' => $r->creator?->name ?? 'Unknown',
            'date' => $r->created_at?->format('M j, Y') ?? '',
            'url' => StationResource::getUrl('view', ['record' => $stationId]) . '?activeRelationManager=bigTicketRequests',
        ])->toArray();
    }

    private function formatDefects(Collection $defects): array
    {
        return $defects->map(fn (ApparatusDefect $d) => [
            'id' => $d->id,
            'unit' => $d->apparatus?->designation ?? $d->apparatus?->unit_id ?? 'Unknown',
            'item' => $d->item ?? 'Unknown',
            'issue_type' => $d->issue_type ?? '',
            'status' => $d->status ?? 'Unknown',
            'reported_date' => $d->reported_date
                ? Carbon::parse($d->reported_date)->format('M j, Y')
                : ($d->created_at?->format('M j, Y') ?? ''),
            'url' => DefectResource::getUrl('edit', ['record' => $d->id]),
        ])->toArray();
    }

    private function formatSupplyRequests(Collection $requests, int $stationId): array
    {
        return $requests->map(fn (StationSupplyRequest $r) => [
            'id' => $r->id,
            'request_text' => Str::limit($r->request_text ?? '', 60),
            'created_by' => $r->created_by_name ?? 'Unknown',
            'shift' => $r->created_by_shift ?? '',
            'date' => $r->created_at?->format('M j, Y') ?? '',
            'url' => StationResource::getUrl('view', ['record' => $stationId]) . '?activeRelationManager=supplyRequests',
        ])->toArray();
    }

    private function summarizeBigTicketItems(?array $items): string
    {
        if (empty($items)) {
            return 'No items';
        }

        $names = collect($items)->pluck('name')->filter()->take(3)->toArray();

        if (empty($names)) {
            return count($items) . ' item(s)';
        }

        $summary = implode(', ', $names);

        if (count($items) > 3) {
            $summary .= ' +' . (count($items) - 3) . ' more';
        }

        return $summary;
    }
}
