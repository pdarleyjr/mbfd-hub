<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\ApparatusDefect;
use App\Models\ApparatusServiceTicket;
use App\Models\EquipmentItem;
use App\Models\Station;
use App\Models\StationInspection;
use App\Models\StationRequest;
use App\Models\StationSupplyRequest;
use App\Services\DailyCheckoutComplianceService;
use App\Services\Display\DisplaySnapshotService;
use App\Services\StationStaffingService;
use Filament\Widgets\Widget;
use Illuminate\Support\Collection;

class StationOperationsHubWidget extends Widget
{
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
        /** @var Collection<int, Station> $allStations */
        $allStations = Station::query()
            ->with('apparatuses:id,station_id,unit_id,designation,status,daily_checkout_requirement')
            ->where('is_active', true)
            ->orderBy('station_number')
            ->get();

        $dailyCheckoutByStation = app(DailyCheckoutComplianceService::class)
            ->summariesForStations($allStations);
        $stationData = $this->loadAllStationData($allStations, $dailyCheckoutByStation);

        $stationOptions = $allStations
            ->filter(fn (Station $station): bool => in_array((int) $station->station_number, [1, 2, 3, 4, 6], true))
            ->map(fn (Station $station): array => [
                'id' => (int) $station->id,
                'station_number' => (int) $station->station_number,
            ])
            ->values()
            ->all();
        $validStationIds = collect($stationOptions)->pluck('id')->map(fn (mixed $id): string => (string) $id)->all();
        if ($this->selectedStationId !== 'all' && ! in_array($this->selectedStationId, $validStationIds, true)) {
            $this->selectedStationId = 'all';
        }

        $visibleStations = $this->selectedStationId === 'all'
            ? $allStations
            : $allStations->where('id', (int) $this->selectedStationId)->values();

        return [
            'stations' => $visibleStations->map(fn (Station $station): array => [
                'id' => (int) $station->id,
                'station_number' => (int) $station->station_number,
            ])->values()->all(),
            'stationOptions' => $stationOptions,
            'selectedStationId' => $this->selectedStationId,
            'department' => $this->departmentSummary($allStations, $stationData),
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
        $stationIds = $stations->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
        $apparatusToStation = $stations
            ->flatMap(fn (Station $station) => $station->apparatuses->mapWithKeys(
                fn ($apparatus): array => [(int) $apparatus->id => (int) $station->id]
            ))
            ->all();
        $apparatusIds = array_keys($apparatusToStation);

        $openRequests = StationRequest::query()
            ->whereIn('station_id', $stationIds)->open()->orderByDesc('created_at')
            ->get(['id', 'station_id', 'request_type', 'title', 'created_at'])->groupBy('station_id');
        $openServiceTickets = ApparatusServiceTicket::query()
            ->whereIn('station_id', $stationIds)->open()->orderByDesc('created_at')
            ->get(['id', 'station_id', 'ticket_number', 'title', 'created_at'])->groupBy('station_id');
        $openSupplyRequests = StationSupplyRequest::query()
            ->whereIn('station_id', $stationIds)->where('status', 'open')
            ->get(['id', 'station_id'])->groupBy('station_id');
        $openDefects = $apparatusIds === [] ? collect() : ApparatusDefect::query()
            ->whereIn('apparatus_id', $apparatusIds)->where('resolved', false)->orderByDesc('created_at')
            ->get(['id', 'apparatus_id', 'item', 'status', 'created_at']);
        $defectsByStation = $openDefects
            ->filter(fn (ApparatusDefect $defect): bool => isset($apparatusToStation[$defect->apparatus_id]))
            ->groupBy(fn (ApparatusDefect $defect): int => $apparatusToStation[$defect->apparatus_id]);
        $recentInspections = StationInspection::query()
            ->whereIn('station_id', $stationIds)->orderByDesc('inspection_date')
            ->limit(max(count($stationIds) * 4, 4))
            ->get(['id', 'station_id', 'inspection_date', 'inspection_type', 'overall_status'])->groupBy('station_id');
        $displaySnapshots = app(DisplaySnapshotService::class);
        $staffing = app(StationStaffingService::class);
        $data = [];

        foreach ($stations as $station) {
            $stationId = (int) $station->id;
            $dailyCheckout = $dailyCheckoutByStation[$stationId] ?? [];
            $statusCounts = $displaySnapshots->classifyApparatusCollection($station->apparatuses);
            $requests = $openRequests->get($stationId, collect());
            $tickets = $openServiceTickets->get($stationId, collect());
            $defects = $defectsByStation->get($stationId, collect());
            $summary = $staffing->summaryFor($station);

            $data[$stationId] = [
                'stationNumber' => (int) $station->station_number,
                'stationUrl' => \App\Filament\Resources\StationResource::getUrl('view', ['record' => $stationId]),
                'dailyCheckout' => $dailyCheckout,
                'dailyCheckoutSubtitle' => ($dailyCheckout['required_total'] ?? 0) > 0
                    ? 'Canonical Daily Checkout status'
                    : 'No required apparatus — completion unavailable',
                'readinessPercent' => $dailyCheckout['completion_percent'] ?? null,
                'apparatus' => [
                    'total' => $station->apparatuses->count(),
                    'in_service' => $statusCounts['in_service'],
                    'out_of_service' => $statusCounts['out_of_service'],
                    'maintenance' => $statusCounts['maintenance'],
                    'unclassified' => $statusCounts['unclassified'],
                    'configured' => $summary['assigned_apparatus_count'],
                ],
                'counts' => [
                    'dailyCheckoutCompleted' => (int) ($dailyCheckout['completed'] ?? 0),
                    'stationRequests' => $requests->count(),
                    'serviceTickets' => $tickets->count(),
                    'defects' => $defects->count(),
                    'missingDefects' => $defects->where('status', 'Missing')->count(),
                    'supplyRequests' => $openSupplyRequests->get($stationId, collect())->count(),
                ],
                'recentInspections' => $recentInspections->get($stationId, collect())->take(4)->map(fn (StationInspection $inspection): array => [
                    'date' => $inspection->inspection_date?->toDateString(),
                    'type' => $inspection->inspection_type ?: 'Station inspection',
                    'status' => $inspection->overall_status ?: 'pending',
                ])->values()->all(),
                'recentWork' => $requests->take(2)->map(fn (StationRequest $request): array => [
                    'label' => $request->title ?: 'Station request', 'type' => $request->request_type,
                ])->concat($tickets->take(2)->map(fn (ApparatusServiceTicket $ticket): array => [
                    'label' => $ticket->ticket_number ?: $ticket->title ?: 'Service ticket', 'type' => 'service',
                ]))->take(4)->values()->all(),
            ];
        }

        return $data;
    }

    /**
     * @param  Collection<int, Station>  $stations
     * @param  array<int, array<string, mixed>>  $stationData
     * @return array<string, mixed>
     */
    protected function departmentSummary(Collection $stations, array $stationData): array
    {
        $apparatus = app(DisplaySnapshotService::class)->classifyApparatusCollection(
            $stations->flatMap(fn (Station $station) => $station->apparatuses)->values()
        );
        $daily = ['completed' => 0, 'required' => 0, 'attention' => 0, 'review_pending' => 0, 'not_checked' => 0];
        $work = ['requests' => 0, 'service_tickets' => 0, 'defects' => 0, 'missing' => 0, 'supplies' => 0];
        foreach ($stationData as $data) {
            $checkout = $data['dailyCheckout'];
            $daily['completed'] += (int) ($checkout['completed'] ?? 0);
            $daily['required'] += (int) ($checkout['required_total'] ?? 0);
            $daily['attention'] += (int) ($checkout['attention'] ?? 0);
            $daily['review_pending'] += (int) ($checkout['review_pending'] ?? 0);
            $daily['not_checked'] += (int) ($checkout['not_checked'] ?? 0);
            $work['requests'] += $data['counts']['stationRequests'];
            $work['service_tickets'] += $data['counts']['serviceTickets'];
            $work['defects'] += $data['counts']['defects'];
            $work['missing'] += $data['counts']['missingDefects'];
            $work['supplies'] += $data['counts']['supplyRequests'];
        }
        $lowStock = EquipmentItem::query()->where('is_active', true)
            ->withSum('stockMutations as stock_total', 'amount')->get()
            ->filter(fn (EquipmentItem $item): bool => (int) ($item->stock_total ?? 0) <= (int) $item->reorder_min)->count();

        return compact('apparatus', 'daily', 'work', 'lowStock');
    }
}
