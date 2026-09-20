<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\ApparatusDefect;
use App\Models\Station;
use App\Models\StationRequest;
use App\Models\StationSupplyRequest;
use App\Services\DailyCheckoutComplianceService;
use Filament\Widgets\Widget;

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
        $stationData = $this->loadAllStationData($stations, $dailyCheckoutByStation);

        return [
            'stations' => $stations,
            'stationData' => $stationData,
        ];
    }

    /**
     * @param  array<int, array<string, int>>  $stations
     * @param  array<int, array<string, mixed>>  $dailyCheckoutByStation
     */
    protected function loadAllStationData(array $stations, array $dailyCheckoutByStation): array
    {
        $stationIds = collect($stations)->pluck('id')->toArray();
        $allApparatusIds = collect($this->stationApparatusMap)->flatten()->toArray();

        $requestCounts = StationRequest::query()
            ->whereIn('station_id', $stationIds)
            ->whereNotIn('status', ['completed', 'denied', 'cancelled'])
            ->selectRaw('station_id, count(*) as aggregate')
            ->groupBy('station_id')
            ->pluck('aggregate', 'station_id');
        $supplyRequestCounts = StationSupplyRequest::query()
            ->whereIn('station_id', $stationIds)
            ->where('status', 'open')
            ->selectRaw('station_id, count(*) as aggregate')
            ->groupBy('station_id')
            ->pluck('aggregate', 'station_id');
        $apparatusToStation = collect($this->stationApparatusMap)
            ->mapWithKeys(fn (array $apparatusIds, int $stationId): array => array_fill_keys($apparatusIds, $stationId));
        $defectCounts = ApparatusDefect::query()
            ->whereIn('apparatus_id', $allApparatusIds)
            ->where('resolved', false)
            ->pluck('apparatus_id')
            ->map(fn (int $apparatusId): ?int => $apparatusToStation->get($apparatusId))
            ->filter()
            ->countBy();

        $data = [];
        foreach ($stations as $station) {
            $sid = $station['id'];
            $dailyCheckout = $dailyCheckoutByStation[$sid] ?? null;

            $data[$sid] = [
                'dailyCheckout' => $dailyCheckout,
                'counts' => [
                    'dailyCheckoutCompleted' => $dailyCheckout['completed'] ?? 0,
                    'stationRequests' => (int) ($requestCounts[$sid] ?? 0),
                    'defects' => (int) ($defectCounts[$sid] ?? 0),
                    'supplyRequests' => (int) ($supplyRequestCounts[$sid] ?? 0),
                ],
            ];
        }

        return $data;
    }
}
