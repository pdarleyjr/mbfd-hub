<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Resources\ApparatusResource;
use App\Filament\Resources\ApparatusServiceTicketResource;
use App\Filament\Resources\InspectionResource;
use App\Filament\Resources\StationInspectionResource;
use App\Filament\Resources\StationRequestResource;
use App\Filament\Resources\StationResource;
use App\Models\Apparatus;
use App\Models\ApparatusDefect;
use App\Models\ApparatusInspection;
use App\Models\ApparatusServiceTicket;
use App\Models\EquipmentItem;
use App\Models\Station;
use App\Models\StationInspection;
use App\Models\StationInventorySubmission;
use App\Models\StationRequest;
use App\Models\StationSupplyRequest;
use App\Services\DailyCheckoutComplianceService;
use App\Services\Display\DisplayReadiness;
use App\Services\Display\DisplaySnapshotService;
use App\Services\StationStaffingService;
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class StationOperationsHubWidget extends Widget
{
    /** @var list<string> */
    private const OPERATIONAL_STATION_NUMBERS = ['1', '2', '3', '4', '6'];

    protected static string $view = 'filament.widgets.station-operations-hub-widget';

    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $pollingInterval = '20s';

    public string $selectedStationId = 'all';

    public function updatedSelectedStationId(): void
    {
        if ($this->selectedStationId !== 'all' && ! ctype_digit($this->selectedStationId)) {
            $this->selectedStationId = 'all';
        }
    }

    /** @return array<string, mixed> */
    public function getViewData(): array
    {
        /** @var Collection<int, Station> $stations */
        $stations = Station::query()
            ->with('apparatuses:id,station_id,unit_id,designation,status,daily_checkout_requirement')
            ->where('is_active', true)
            ->whereIn('station_number', self::OPERATIONAL_STATION_NUMBERS)
            ->orderByRaw("CASE station_number WHEN '1' THEN 1 WHEN '2' THEN 2 WHEN '3' THEN 3 WHEN '4' THEN 4 WHEN '6' THEN 5 END")
            ->get();

        $stationOptions = $stations->map(fn (Station $station): array => [
            'id' => (int) $station->id,
            'station_number' => (int) $station->station_number,
        ])->values()->all();
        $validStationIds = $stations->pluck('id')->map(static fn (mixed $id): string => (string) $id)->all();
        if ($this->selectedStationId !== 'all' && ! in_array($this->selectedStationId, $validStationIds, true)) {
            $this->selectedStationId = 'all';
        }

        $dailyCheckoutByStation = app(DailyCheckoutComplianceService::class)->summariesForStations($stations);
        $stationData = $this->loadAllStationData($stations, $dailyCheckoutByStation);
        $visibleStations = $this->selectedStationId === 'all'
            ? $stations
            : $stations->where('id', (int) $this->selectedStationId)->values();

        return [
            'stations' => $visibleStations->map(fn (Station $station): array => [
                'id' => (int) $station->id,
                'station_number' => (int) $station->station_number,
            ])->values()->all(),
            'stationOptions' => $stationOptions,
            'selectedStationId' => $this->selectedStationId,
            'department' => $this->departmentSummary($stations, $stationData),
            'stationData' => $stationData,
        ];
    }

    /**
     * @param  Collection<int, Station>  $stations
     * @param  array<int, array<string, mixed>>  $dailyCheckoutByStation
     * @return array<int, array<string, mixed>>
     */
    protected function loadAllStationData(Collection $stations, array $dailyCheckoutByStation): array
    {
        $stationIds = $stations->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();
        $apparatusToStation = [];
        foreach ($stations as $station) {
            /** @var Collection<int, Apparatus> $stationApparatus */
            $stationApparatus = $station->apparatuses;
            foreach ($stationApparatus as $apparatus) {
                $apparatusToStation[(int) $apparatus->id] = (int) $station->id;
            }
        }
        $apparatusIds = array_keys($apparatusToStation);

        $requestCounts = $this->countByStation(StationRequest::query()->whereIn('station_id', $stationIds)->open());
        $ticketCounts = $this->countByStation(ApparatusServiceTicket::query()->whereIn('station_id', $stationIds)->open());
        $supplyCounts = $this->countByStation(StationSupplyRequest::query()->whereIn('station_id', $stationIds)->open());
        $equipmentCounts = $this->countByStation(StationRequest::query()
            ->whereIn('station_id', $stationIds)->where('request_type', 'equipment')->open());
        $criticalEquipmentCounts = $this->countByStation(StationRequest::query()
            ->whereIn('station_id', $stationIds)->where('request_type', 'equipment')
            ->whereIn('priority', ['critical', 'high'])->open());

        $defectCounts = $this->defectCountsByStation($apparatusToStation, false);
        $missingDefectCounts = $this->defectCountsByStation($apparatusToStation, true);
        $recentInspections = $this->rankedByStation(
            StationInspection::query()->whereIn('station_id', $stationIds),
            StationInspection::class, 'station_inspections', 'station_id', 'inspection_date', 4,
        )->groupBy('station_id');
        $recentRequests = $this->rankedByStation(
            StationRequest::query()->whereIn('station_id', $stationIds),
            StationRequest::class, 'station_requests', 'station_id', 'created_at', 3,
        )->groupBy('station_id');
        $recentTickets = $this->rankedByStation(
            ApparatusServiceTicket::query()->whereIn('station_id', $stationIds),
            ApparatusServiceTicket::class, 'apparatus_service_tickets', 'station_id', 'created_at', 3,
        )->groupBy('station_id');
        $recentInventory = $this->rankedByStation(
            StationInventorySubmission::query()->whereIn('station_id', $stationIds),
            StationInventorySubmission::class, 'station_inventory_submissions', 'station_id', 'submitted_at', 3,
        )->groupBy('station_id');
        $recentSupply = $this->rankedByStation(
            StationSupplyRequest::query()->whereIn('station_id', $stationIds),
            StationSupplyRequest::class, 'station_supply_requests', 'station_id', 'created_at', 2,
        )->groupBy('station_id');
        $recentApparatusInspections = $apparatusIds === [] ? collect() : $this->rankedByStation(
            ApparatusInspection::query()
                ->join('apparatuses', 'apparatuses.id', '=', 'apparatus_inspections.apparatus_id')
                ->whereIn('apparatuses.station_id', $stationIds),
            ApparatusInspection::class, 'apparatus_inspections', 'apparatuses.station_id', 'completed_at', 3, 'console_station_id',
        )->groupBy('console_station_id');

        $display = app(DisplaySnapshotService::class);
        $staffing = app(StationStaffingService::class);
        $data = [];

        foreach ($stations as $station) {
            $stationId = (int) $station->id;
            /** @var Collection<int, Apparatus> $stationApparatus */
            $stationApparatus = $station->apparatuses;
            $dailyCheckout = $dailyCheckoutByStation[$stationId] ?? [];
            $statusCounts = $display->classifyApparatusCollection($stationApparatus);
            $latestInspection = $recentInspections->get($stationId, collect())->first();
            $inspectionAgeDays = $latestInspection?->inspection_date === null
                ? null
                : (int) max(0, Carbon::now()->startOfDay()->diffInDays($latestInspection->inspection_date));
            $readiness = DisplayReadiness::compute(
                requiredApparatusCount: (int) ($dailyCheckout['required_total'] ?? 0),
                checkedApparatusCount: (int) ($dailyCheckout['checked'] ?? 0),
                attentionApparatusCount: (int) ($dailyCheckout['attention'] ?? 0),
                reviewPendingApparatusCount: (int) ($dailyCheckout['review_pending'] ?? 0),
                notCheckedApparatusCount: (int) ($dailyCheckout['not_checked'] ?? 0),
                unknownApparatusCount: (int) ($dailyCheckout['classification_required'] ?? 0),
                inServiceCount: $statusCounts['in_service'],
                outOfServiceCount: (int) ($dailyCheckout['out_of_service'] ?? $statusCounts['out_of_service']),
                maintenanceCount: $statusCounts['maintenance'],
                openDefects: $defectCounts[$stationId] ?? 0,
                criticalDefects: $missingDefectCounts[$stationId] ?? 0,
                lastStationInspectionStatus: $latestInspection?->overall_status,
                stationInspectionAgeDays: $inspectionAgeDays,
                pendingEquipmentRequests: $equipmentCounts[$stationId] ?? 0,
                criticalPendingEquipmentRequests: $criticalEquipmentCounts[$stationId] ?? 0,
                snapshotAgeSeconds: 0,
            );
            $summary = $staffing->summaryFor($station);
            $stationUrl = StationResource::getUrl('view', ['record' => $stationId]);

            $data[$stationId] = [
                'stationNumber' => (int) $station->station_number,
                'stationUrl' => $stationUrl,
                'links' => [
                    'requests' => $this->stationFilterUrl(StationRequestResource::getUrl('index'), $stationId),
                    'tickets' => $this->stationFilterUrl(ApparatusServiceTicketResource::getUrl('index'), $stationId),
                    'defects' => $this->stationFilterUrl(\App\Filament\Resources\DefectResource::getUrl('index'), $stationId),
                    'supplies' => $stationUrl.'?activeRelationManager=supplyRequests',
                    'inventorySubmissions' => $stationUrl.'?activeRelationManager=inventorySubmissions',
                ],
                'dailyCheckout' => $dailyCheckout,
                'dailyCheckoutSubtitle' => ($dailyCheckout['required_total'] ?? 0) > 0 ? 'Daily Checkout completion' : 'No required apparatus — completion unavailable',
                'readiness' => $readiness,
                'apparatus' => [
                    'total' => $stationApparatus->count(), 'in_service' => $statusCounts['in_service'],
                    'out_of_service' => $statusCounts['out_of_service'], 'maintenance' => $statusCounts['maintenance'],
                    'unclassified' => $statusCounts['unclassified'], 'configured' => $summary['assigned_apparatus_count'],
                    'records' => $stationApparatus->map(static function (Apparatus $apparatus) use ($display): array {
                        $designation = $apparatus->getAttribute('designation');
                        $unitId = $apparatus->getAttribute('unit_id');

                        return [
                            'id' => (int) $apparatus->getKey(),
                            'label' => $designation ?: $unitId ?: 'Apparatus',
                            'status' => $display->classifyApparatusStatus(is_string($apparatus->getAttribute('status')) ? $apparatus->getAttribute('status') : null),
                            'url' => ApparatusResource::getUrl('view', ['record' => $apparatus]),
                        ];
                    })->values()->all(),
                ],
                'counts' => [
                    'dailyCheckoutCompleted' => (int) ($dailyCheckout['completed'] ?? 0),
                    'stationRequests' => $requestCounts[$stationId] ?? 0, 'serviceTickets' => $ticketCounts[$stationId] ?? 0,
                    'defects' => $defectCounts[$stationId] ?? 0, 'missingDefects' => $missingDefectCounts[$stationId] ?? 0,
                    'supplyRequests' => $supplyCounts[$stationId] ?? 0,
                ],
                'recentInspections' => $recentInspections->get($stationId, collect())
                    ->map(fn (StationInspection $inspection): array => $this->stationInspectionActivity($inspection))->values()->all(),
                'recentActivity' => $this->recentActivity(
                    $recentInspections->get($stationId, collect()), $recentApparatusInspections->get($stationId, collect()),
                    $recentRequests->get($stationId, collect()), $recentTickets->get($stationId, collect()),
                    $recentInventory->get($stationId, collect()), $recentSupply->get($stationId, collect()), $stationUrl,
                ),
            ];
        }

        return $data;
    }

    /** @return array<int, int> */
    private function countByStation(Builder $query): array
    {
        return $query->selectRaw('station_id, COUNT(*) as aggregate')->groupBy('station_id')
            ->pluck('aggregate', 'station_id')->map(static fn (mixed $value): int => (int) $value)->all();
    }

    /** @param array<int, int> $apparatusToStation @return array<int, int> */
    private function defectCountsByStation(array $apparatusToStation, bool $missing): array
    {
        if ($apparatusToStation === []) {
            return [];
        }
        $query = ApparatusDefect::query()->whereIn('apparatus_id', array_keys($apparatusToStation))->where('resolved', false);
        if ($missing) {
            $query->where('status', 'Missing');
        }
        $result = [];
        foreach ($query->selectRaw('apparatus_id, COUNT(*) as aggregate')->groupBy('apparatus_id')->get() as $row) {
            $stationId = $apparatusToStation[(int) $row->apparatus_id] ?? null;
            if ($stationId !== null) {
                $result[$stationId] = ($result[$stationId] ?? 0) + (int) $row->getAttribute('aggregate');
            }
        }

        return $result;
    }

    /** @return Collection<int, mixed> */
    private function rankedByStation(Builder $query, string $model, string $table, string $partition, string $timestamp, int $limit, ?string $outerPartition = null): Collection
    {
        $qualifiedTimestamp = str_contains($timestamp, '.') ? $timestamp : "{$table}.{$timestamp}";
        $ranked = (clone $query)->select("{$table}.*");
        if ($outerPartition !== null && $outerPartition !== $partition) {
            $ranked->selectRaw("{$partition} AS {$outerPartition}");
        }
        $ranked->selectRaw("ROW_NUMBER() OVER (PARTITION BY {$partition} ORDER BY {$qualifiedTimestamp} DESC NULLS LAST, {$table}.id DESC) AS console_rank");

        return $model::query()->fromSub($ranked, 'station_ranked')->where('console_rank', '<=', $limit)
            ->orderBy($outerPartition ?? $partition)->orderByDesc($timestamp)->orderByDesc('id')->get();
    }

    /** @return list<array<string, mixed>> */
    private function recentActivity(Collection $inspections, Collection $apparatusInspections, Collection $requests, Collection $tickets, Collection $inventory, Collection $supply, string $stationUrl): array
    {
        return collect()
            ->concat($inspections->map(fn (StationInspection $record): array => $this->stationInspectionActivity($record)))
            ->concat($apparatusInspections->map(fn (ApparatusInspection $record): array => ['id' => (int) $record->id, 'type' => 'daily_checkout', 'label' => 'Daily Checkout / apparatus inspection', 'status' => $record->review_status ?: 'submitted', 'timestamp' => $this->timestamp($record->completed_at ?: $record->created_at), 'url' => InspectionResource::getUrl('view', ['record' => $record])]))
            ->concat($requests->map(fn (StationRequest $record): array => ['id' => (int) $record->id, 'type' => 'station_request', 'label' => $record->request_number ?: $record->title ?: 'Station request', 'status' => $record->status, 'timestamp' => $this->timestamp($record->created_at), 'url' => StationRequestResource::getUrl('view', ['record' => $record])]))
            ->concat($tickets->map(fn (ApparatusServiceTicket $record): array => ['id' => (int) $record->id, 'type' => 'service_ticket', 'label' => $record->ticket_number ?: $record->title ?: 'Service ticket', 'status' => $record->status, 'timestamp' => $this->timestamp($record->created_at), 'url' => ApparatusServiceTicketResource::getUrl('view', ['record' => $record])]))
            ->concat($inventory->map(fn (StationInventorySubmission $record): array => ['id' => (int) $record->id, 'type' => 'inventory_submission', 'label' => 'Inventory submission #'.$record->id, 'status' => 'submitted', 'timestamp' => $this->timestamp($record->submitted_at ?: $record->created_at), 'url' => $stationUrl.'?activeRelationManager=inventorySubmissions']))
            ->concat($supply->map(fn (StationSupplyRequest $record): array => ['id' => (int) $record->id, 'type' => 'supply_request', 'label' => 'Supply request #'.$record->id, 'status' => $record->status, 'timestamp' => $this->timestamp($record->created_at), 'url' => $stationUrl.'?activeRelationManager=supplyRequests']))
            ->sortByDesc('timestamp')->take(8)->values()->all();
    }

    /** @return array<string, mixed> */
    private function stationInspectionActivity(StationInspection $record): array
    {
        $inspectionType = $record->getAttribute('inspection_type');
        $status = $record->getAttribute('overall_status');

        return ['id' => (int) $record->getKey(), 'type' => 'station_inspection', 'label' => is_string($inspectionType) && $inspectionType !== '' ? $inspectionType : 'Station inspection', 'status' => is_string($status) && $status !== '' ? $status : 'pending', 'timestamp' => $this->timestamp($record->inspection_date), 'url' => StationInspectionResource::getUrl('view', ['record' => $record])];
    }

    private function timestamp(mixed $value): string
    {
        return $value instanceof Carbon ? $value->toIso8601String() : (string) $value;
    }

    private function stationFilterUrl(string $url, int $stationId): string
    {
        return $url.'?'.http_build_query(['tableFilters' => ['station_id' => ['value' => $stationId]]]);
    }

    /** @param Collection<int, Station> $stations @param array<int, array<string, mixed>> $stationData @return array<string, mixed> */
    private function departmentSummary(Collection $stations, array $stationData): array
    {
        /** @var Collection<int, Apparatus> $allApparatus */
        $allApparatus = new Collection;
        foreach ($stations as $station) {
            /** @var Collection<int, Apparatus> $stationApparatus */
            $stationApparatus = $station->apparatuses;
            $allApparatus = $allApparatus->concat($stationApparatus)->values();
        }
        $apparatus = app(DisplaySnapshotService::class)->classifyApparatusCollection($allApparatus);
        $daily = ['completed' => 0, 'required' => 0, 'attention' => 0, 'review_pending' => 0, 'not_checked' => 0];
        $work = ['requests' => 0, 'service_tickets' => 0, 'defects' => 0, 'missing' => 0, 'supplies' => 0];
        $readiness = 0;
        foreach ($stationData as $data) {
            $checkout = $data['dailyCheckout'];
            foreach (['completed', 'attention', 'review_pending', 'not_checked'] as $key) {
                $daily[$key] += (int) ($checkout[$key] ?? 0);
            }
            $daily['required'] += (int) ($checkout['required_total'] ?? 0);
            foreach (['stationRequests' => 'requests', 'serviceTickets' => 'service_tickets', 'defects' => 'defects', 'missingDefects' => 'missing', 'supplyRequests' => 'supplies'] as $source => $target) {
                $work[$target] += (int) ($data['counts'][$source] ?? 0);
            }
            $readiness += (int) ($data['readiness']['percent'] ?? 0);
        }
        $lowStock = EquipmentItem::query()->where('is_active', true)->withSum('stockMutations as stock_total', 'amount')->get()
            ->filter(static fn (EquipmentItem $item): bool => (int) ($item->stock_total ?? 0) <= (int) $item->reorder_min)->count();

        return compact('apparatus', 'daily', 'work', 'lowStock') + ['readinessPercent' => $stations->isEmpty() ? 0 : (int) round($readiness / $stations->count())];
    }
}
