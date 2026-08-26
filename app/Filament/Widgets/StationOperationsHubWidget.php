<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Resources\ApparatusResource;
use App\Filament\Resources\DefectResource;
use App\Filament\Resources\StationInspectionResource;
use App\Filament\Resources\StationRequestResource;
use App\Filament\Resources\StationResource;
use App\Models\Apparatus;
use App\Models\ApparatusDefect;
use App\Models\Station;
use App\Models\StationInspection;
use App\Models\StationRequest;
use App\Models\StationSupplyRequest;
use App\Models\User;
use App\Services\DailyCheckoutComplianceService;
use Filament\Widgets\Widget;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class StationOperationsHubWidget extends Widget
{
    protected static string $view = 'filament.widgets.station-operations-hub-widget';

    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $pollingInterval = '30s';

    /** @var array<int, list<int>> station_id => [apparatus_ids] */
    protected array $stationApparatusMap = [];

    /**
     * Reload data on every render cycle (including poll ticks).
     * mount() is only called once; getViewData() runs on every render.
     */
    public function getViewData(): array
    {
        $stationModels = Station::with('apparatuses:id,station_id,unit_id,designation,status,daily_checkout_requirement')
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

        $dailyCheckoutByStation = app(DailyCheckoutComplianceService::class)
            ->summariesForStations($stationModels);
        /** @var Collection<int, Apparatus> $apparatusById */
        $apparatusById = $stationModels
            ->flatMap(function (Station $station): Collection {
                /** @var Collection<int, Apparatus> $apparatuses */
                $apparatuses = $station->apparatuses;

                return $apparatuses;
            })
            ->keyBy(static fn (Apparatus $apparatus): int => (int) $apparatus->getKey());
        $stationData = $this->loadAllStationData($stations, $dailyCheckoutByStation, $apparatusById);

        return [
            'stations' => $stations,
            'stationData' => $stationData,
        ];
    }

    /**
     * @param  array<int, array<string, int>>  $stations
     * @param  array<int, array<string, mixed>>  $dailyCheckoutByStation
     * @param  Collection<int, Apparatus>  $apparatusById
     */
    protected function loadAllStationData(array $stations, array $dailyCheckoutByStation, Collection $apparatusById): array
    {
        $stationIds = collect($stations)->pluck('id')->toArray();
        $allApparatusIds = collect($this->stationApparatusMap)->flatten()->toArray();

        // Batch queries with eager loading
        $stationInspections = $this->getStationInspections($stationIds);
        $stationRequests = $this->getStationRequests($stationIds);
        $defects = $this->getUnresolvedDefects($allApparatusIds);
        $supplyRequests = $this->getOpenSupplyRequests($stationIds);

        $data = [];
        foreach ($stations as $station) {
            $sid = $station['id'];
            $apparatusIds = $this->stationApparatusMap[$sid] ?? [];

            $stationDefects = $defects->filter(
                fn ($d) => in_array($d->apparatus_id, $apparatusIds)
            )->values();

            $stationRequestRows = $stationRequests->where('station_id', $sid)->values();
            $stationStationInsp = $stationInspections->where('station_id', $sid)->values();
            $stationSupplyReqs = $supplyRequests->where('station_id', $sid)->values();
            $dailyCheckout = $dailyCheckoutByStation[$sid] ?? null;

            $data[$sid] = [
                'dailyCheckout' => $dailyCheckout,
                'dailyCheckoutRows' => $dailyCheckout === null
                    ? []
                    : $this->formatDailyCheckoutMatrix($dailyCheckout, $apparatusById),
                'dailyCheckoutSubtitle' => $this->dailyCheckoutSubtitle($dailyCheckout),
                'stationInspections' => $this->formatStationInspections($stationStationInsp),
                'stationRequests' => $this->formatStationRequests($stationRequestRows),
                'defects' => $this->formatDefects($stationDefects),
                'supplyRequests' => $this->formatSupplyRequests($stationSupplyReqs, $sid),
                'counts' => [
                    'dailyCheckoutCompleted' => $dailyCheckout['completed'] ?? 0,
                    'stationInspections' => $stationStationInsp->count(),
                    'stationRequests' => $stationRequestRows->count(),
                    'defects' => $stationDefects->count(),
                    'supplyRequests' => $stationSupplyReqs->count(),
                ],
            ];
        }

        return $data;
    }

    // ── Query methods ────────────────────────────────────────────

    private function getStationInspections(array $stationIds): Collection
    {
        return StationInspection::with('inspector:id,name')
            ->whereIn('station_id', $stationIds)
            ->where('inspection_date', '>=', Carbon::now()->subDays(30))
            ->orderByDesc('inspection_date')
            ->limit(50)
            ->get(['id', 'station_id', 'inspector_id', 'inspection_date', 'inspection_type', 'overall_status']);
    }

    private function getStationRequests(array $stationIds): Collection
    {
        return StationRequest::with('room:id,name,station_id')
            ->whereIn('station_id', $stationIds)
            ->orderByRaw("CASE WHEN status IN ('completed', 'denied', 'cancelled') THEN 1 ELSE 0 END")
            ->orderByDesc('created_at')
            ->limit(100)
            ->get(['id', 'request_number', 'station_id', 'room_id', 'request_type', 'title', 'requester_name_snapshot', 'priority', 'status', 'created_at']);
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

    /**
     * @param  array<string, mixed>  $dailyCheckout
     * @param  Collection<int, Apparatus>  $apparatusById
     * @return list<array<string, string|int>>
     */
    private function formatDailyCheckoutMatrix(array $dailyCheckout, Collection $apparatusById): array
    {
        /** @var list<array{apparatus_id: int, state: string, included_in_completed: bool}> $matrix */
        $matrix = $dailyCheckout['matrix'];

        return collect($matrix)
            ->map(function (array $row) use ($apparatusById): array {
                $apparatusId = (int) $row['apparatus_id'];
                $apparatus = $apparatusById->get($apparatusId);
                $state = (string) $row['state'];

                return [
                    'id' => $apparatusId,
                    'unit' => $apparatus instanceof Apparatus
                        ? ($apparatus->designation ?? $apparatus->getAttribute('unit_id') ?? "Apparatus {$apparatusId}")
                        : "Apparatus {$apparatusId}",
                    'state' => $state,
                    'completion' => $row['included_in_completed']
                        ? 'completed'
                        : (in_array($state, ['out_of_service', 'exempt'], true) ? 'excluded' : 'not complete'),
                    'url' => ApparatusResource::getUrl('edit', ['record' => $apparatusId]),
                ];
            })
            ->values()
            ->all();
    }

    /** @param array<string, mixed>|null $dailyCheckout */
    private function dailyCheckoutSubtitle(?array $dailyCheckout): string
    {
        if ($dailyCheckout === null) {
            return 'Daily Checkout unavailable';
        }

        if (($dailyCheckout['completion_available'] ?? false) !== true) {
            return 'No required apparatus — completion unavailable';
        }

        return sprintf(
            '%d / %d complete · %d review pending · %d OOS · %d exempt · %d classification required',
            $dailyCheckout['completed'],
            $dailyCheckout['required_total'],
            $dailyCheckout['review_pending'],
            $dailyCheckout['out_of_service'],
            $dailyCheckout['exempt'],
            $dailyCheckout['classification_required'],
        );
    }

    /** @param Collection<int, StationInspection> $inspections */
    private function formatStationInspections(Collection $inspections): array
    {
        return $inspections->map(function (StationInspection $i): array {
            $inspector = $i->inspector;

            return [
                'id' => $i->id,
                'date' => Carbon::parse($i->inspection_date)->format('M j, Y'),
                'type' => str_replace('_', ' ', ucfirst($i->inspection_type ?? '')),
                'inspector' => $inspector instanceof User ? $inspector->name : 'Unknown',
                'status' => $i->overall_status ?? 'pending',
                'url' => StationInspectionResource::getUrl('view', ['record' => $i->id]),
            ];
        })->toArray();
    }

    private function formatStationRequests(Collection $requests): array
    {
        return $requests->map(fn (StationRequest $r) => [
            'id' => $r->id,
            'number' => $r->request_number,
            'type' => $r->request_type === 'repair_service' ? 'Repair / Service' : 'Equipment',
            'title' => Str::limit($r->title, 45),
            'requested_by' => $r->requester_name_snapshot,
            'priority' => $r->priority ?? 'normal',
            'status' => $r->status ?? 'pending',
            'date' => $r->created_at?->format('M j, Y') ?? '',
            'url' => StationRequestResource::getUrl('view', ['record' => $r->id]),
        ])->toArray();
    }

    private function formatDefects(Collection $defects): array
    {
        return $defects->map(function (ApparatusDefect $defect): array {
            $apparatus = $defect->getAttribute('apparatus');

            return [
                'id' => $defect->id,
                'unit' => $apparatus instanceof Apparatus
                    ? ($apparatus->designation ?? $apparatus->getAttribute('unit_id') ?? 'Unknown')
                    : 'Unknown',
                'item' => $defect->item ?? 'Unknown',
                'issue_type' => $defect->issue_type ?? '',
                'status' => $defect->status ?? 'Unknown',
                'reported_date' => $defect->reported_date
                    ? Carbon::parse($defect->reported_date)->format('M j, Y')
                    : ($defect->created_at?->format('M j, Y') ?? ''),
                'url' => DefectResource::getUrl('edit', ['record' => $defect->id]),
            ];
        })->toArray();
    }

    private function formatSupplyRequests(Collection $requests, int $stationId): array
    {
        return $requests->map(fn (StationSupplyRequest $r) => [
            'id' => $r->id,
            'request_text' => Str::limit($r->request_text ?? '', 60),
            'created_by' => $r->created_by_name ?? 'Unknown',
            'shift' => $r->created_by_shift ?? '',
            'date' => $r->created_at?->format('M j, Y') ?? '',
            'url' => StationResource::getUrl('view', ['record' => $stationId]).'?activeRelationManager=supplyRequests',
        ])->toArray();
    }
}
