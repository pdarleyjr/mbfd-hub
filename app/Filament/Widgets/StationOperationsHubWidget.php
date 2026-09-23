<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Resources\ApparatusInspectionExceptionResource;
use App\Filament\Resources\ApparatusResource;
use App\Filament\Resources\ApparatusServiceTicketResource;
use App\Filament\Resources\DefectResource;
use App\Filament\Resources\HubSupportTicketResource;
use App\Filament\Resources\InspectionResource;
use App\Filament\Resources\StationInspectionResource;
use App\Filament\Resources\StationRequestResource;
use App\Filament\Resources\StationResource;
use App\Models\Apparatus;
use App\Models\ApparatusDefect;
use App\Models\ApparatusInspection;
use App\Models\ApparatusInspectionException;
use App\Models\ApparatusServiceTicket;
use App\Models\HubSupportTicket;
use App\Models\Station;
use App\Models\StationInspection;
use App\Models\StationInventorySubmission;
use App\Models\StationRequest;
use App\Models\StationSupplyRequest;
use App\Services\Display\DisplaySnapshotService;
use App\Support\OperationalDisplayWindow;
use BackedEnum;
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

final class StationOperationsHubWidget extends Widget
{
    protected static string $view = 'filament.widgets.station-operations-hub-widget';

    protected static ?int $sort = 3;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    public string $lastSuccessfulRefreshAt = '';

    public bool $displayMode = false;

    public function mount(): void
    {
        $this->displayMode = request()->boolean('display');
        $this->markSuccessfulRefresh();
    }

    public function refreshBoard(): void
    {
        $this->markSuccessfulRefresh();
    }

    /** @return array<string, mixed> */
    public function getViewData(): array
    {
        $window = OperationalDisplayWindow::forNow();
        $stationNumbers = array_map('strval', array_keys((array) config('station_operations.stations', [])));

        /** @var Collection<int, Station> $stations */
        $stations = Station::query()
            ->select(['id', 'station_number', 'name'])
            ->with(['apparatuses' => fn ($query) => $query->select([
                'id', 'station_id', 'unit_id', 'designation', 'status',
                'current_engine_hours', 'current_miles', 'last_pm_date', 'last_pm_mileage',
                'last_pm_engine_hours', 'pm_interval_miles', 'pm_interval_hours', 'updated_at',
            ])->orderBy('designation')->orderBy('unit_id')])
            ->where('is_active', true)
            ->whereIn('station_number', $stationNumbers)
            ->orderByRaw($this->stationOrderSql($stationNumbers))
            ->get();

        $stationIds = $stations->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();
        $stationNumbersById = $stations->mapWithKeys(static fn (Station $station): array => [(int) $station->id => (string) $station->station_number]);
        $apparatusById = $this->apparatusById($stations);
        $apparatusIds = $apparatusById->keys()->map(static fn (mixed $id): int => (int) $id)->all();

        $activity = $this->loadOperationalActivity($stationIds, $apparatusIds, $apparatusById, $window);
        $attention = $this->loadPersistentAttention($stationIds, $apparatusIds, $apparatusById);
        $fleet = $this->fleetSummary($stations);
        $pm = $this->pmSummary($stations);
        $attentionRows = $this->departmentAttention($attention, $fleet['attention'], $pm['records'], $stationNumbersById);
        $stationData = $this->stationData($stations, $activity, $attention, $fleet['byStation'], $pm['byStation']);

        return [
            'stations' => $stations->map(static fn (Station $station): array => [
                'id' => (int) $station->id,
                'station_number' => (int) $station->station_number,
                'name' => $station->name,
            ])->values()->all(),
            'operationalWindow' => $window->toDisplayArray(),
            'lastUpdated' => $this->lastSuccessfulRefreshAt,
            'displayMode' => $this->displayMode,
            'department' => [
                'checkouts' => $activity['checkouts']->values()->all(),
                'attention' => $attentionRows->values()->all(),
                'attentionCounts' => $attentionRows->countBy('type')->all(),
                'apparatus' => $fleet['totals'],
                'pm' => [
                    'approaching' => $pm['approaching'],
                    'due' => $pm['due'],
                    'critical' => $pm['critical'],
                    'records' => $pm['records']->values()->all(),
                ],
                'openServiceTickets' => $attention['serviceTickets']->count(),
                'openDefects' => $attention['defects']->count(),
                'openStationRequests' => $attention['stationRequests']->count(),
                'links' => [
                    'apparatus' => ApparatusResource::getUrl('index'),
                    'inspections' => InspectionResource::getUrl('index'),
                    'serviceTickets' => ApparatusServiceTicketResource::getUrl('index'),
                    'defects' => DefectResource::getUrl('index'),
                    'stationRequests' => StationRequestResource::getUrl('index'),
                    'supportTickets' => HubSupportTicketResource::getUrl('index'),
                ],
            ],
            'stationData' => $stationData,
        ];
    }

    /**
     * @param  list<int>  $stationIds
     * @param  list<int>  $apparatusIds
     * @param  Collection<int, Apparatus>  $apparatusById
     * @return array<string, Collection<int, array<string, mixed>>>
     */
    private function loadOperationalActivity(array $stationIds, array $apparatusIds, Collection $apparatusById, OperationalDisplayWindow $window): array
    {
        if ($stationIds === []) {
            return collect(['checkouts', 'stationInspections', 'stationRequests', 'serviceTickets', 'inventory', 'supply'])
                ->mapWithKeys(static fn (string $key): array => [$key => collect()])->all();
        }

        $start = $window->databaseStart();
        $end = $window->databaseEnd();

        $checkouts = $apparatusIds === [] ? collect() : ApparatusInspection::query()
            ->select(['id', 'apparatus_id', 'client_submission_id', 'designation_at_time', 'unit_number', 'processing_status', 'review_status', 'completed_at', 'created_at'])
            ->whereIn('apparatus_id', $apparatusIds)
            ->whereNotNull('client_submission_id')
            ->where(function (Builder $query) use ($start, $end): void {
                $query->where(fn (Builder $completed) => $completed->where('completed_at', '>=', $start)->where('completed_at', '<', $end))
                    ->orWhere(fn (Builder $created) => $created->whereNull('completed_at')->where('created_at', '>=', $start)->where('created_at', '<', $end));
            })
            ->orderByRaw('COALESCE(completed_at, created_at) DESC')
            ->orderByDesc('id')
            ->get()
            ->map(function (ApparatusInspection $record) use ($apparatusById): array {
                $apparatus = $apparatusById->get((int) $record->apparatus_id);
                $stationId = $apparatus instanceof Apparatus ? (int) $apparatus->station_id : null;
                $label = $record->designation_at_time ?: ($apparatus instanceof Apparatus ? $this->apparatusLabel($apparatus) : null) ?: $record->unit_number ?: 'Apparatus';

                return $this->activityRow(
                    id: (int) $record->id,
                    type: 'daily_checkout',
                    label: (string) $label.' Checkout',
                    status: $record->displayStatus(),
                    timestamp: $record->completed_at ?: $record->created_at,
                    url: InspectionResource::getUrl('view', ['record' => $record]),
                    stationId: $stationId,
                    apparatusId: (int) $record->apparatus_id,
                    tone: $record->processing_status === 'accepted_with_exception' || $record->review_status === 'pending_review' ? 'warning' : 'neutral',
                );
            });

        return [
            'checkouts' => $checkouts,
            'stationInspections' => StationInspection::query()
                ->select(['id', 'station_id', 'inspection_type', 'overall_status', 'inspection_date', 'reviewed_at', 'created_at'])
                ->whereIn('station_id', $stationIds)->where('created_at', '>=', $start)->where('created_at', '<', $end)
                ->orderByDesc('created_at')->orderByDesc('id')->get()
                ->map(fn (StationInspection $record): array => $this->activityRow(
                    (int) $record->id, 'station_inspection', $this->headline($record->inspection_type ?: 'Station inspection'),
                    $this->headline((string) $record->overall_status), $record->created_at,
                    StationInspectionResource::getUrl('view', ['record' => $record]), (int) $record->station_id,
                    tone: $record->reviewed_at === null ? 'warning' : 'neutral',
                )),
            'stationRequests' => StationRequest::query()
                ->select(['id', 'station_id', 'request_number', 'title', 'status', 'priority', 'acknowledged_at', 'created_at'])
                ->whereIn('station_id', $stationIds)->where('created_at', '>=', $start)->where('created_at', '<', $end)
                ->orderByDesc('created_at')->orderByDesc('id')->get()
                ->map(fn (StationRequest $record): array => $this->activityRow(
                    (int) $record->id, 'station_request', $record->request_number ?: $record->title ?: 'Station request',
                    $this->headline((string) $record->status), $record->created_at,
                    StationRequestResource::getUrl('view', ['record' => $record]), (int) $record->station_id,
                    tone: $record->acknowledged_at === null ? 'info' : 'neutral',
                )),
            'serviceTickets' => ApparatusServiceTicket::query()
                ->select(['id', 'station_id', 'apparatus_id', 'ticket_number', 'title', 'status', 'priority', 'acknowledged_at', 'created_at'])
                ->whereIn('station_id', $stationIds)->where('created_at', '>=', $start)->where('created_at', '<', $end)
                ->orderByDesc('created_at')->orderByDesc('id')->get()
                ->map(fn (ApparatusServiceTicket $record): array => $this->activityRow(
                    (int) $record->id, 'service_ticket', $record->ticket_number ?: $record->title ?: 'Service ticket',
                    $this->headline((string) $record->status), $record->created_at,
                    ApparatusServiceTicketResource::getUrl('view', ['record' => $record]), (int) $record->station_id,
                    (int) $record->apparatus_id,
                    $record->acknowledged_at === null ? 'info' : 'neutral',
                )),
            'inventory' => StationInventorySubmission::query()
                ->select(['id', 'station_id', 'submitted_at', 'created_at'])
                ->whereIn('station_id', $stationIds)->where('created_at', '>=', $start)->where('created_at', '<', $end)
                ->orderByDesc('created_at')->orderByDesc('id')->get()
                ->map(fn (StationInventorySubmission $record): array => $this->activityRow(
                    (int) $record->id, 'inventory_submission', 'Station inventory submission', 'Submitted', $record->created_at,
                    $this->stationRelationUrl((int) $record->station_id, 'inventorySubmissions'), (int) $record->station_id,
                )),
            'supply' => StationSupplyRequest::query()
                ->select(['id', 'station_id', 'status', 'created_at'])
                ->whereIn('station_id', $stationIds)->where('created_at', '>=', $start)->where('created_at', '<', $end)
                ->orderByDesc('created_at')->orderByDesc('id')->get()
                ->map(fn (StationSupplyRequest $record): array => $this->activityRow(
                    (int) $record->id, 'supply_request', 'Supply request', $this->headline((string) $record->status), $record->created_at,
                    $this->stationRelationUrl((int) $record->station_id, 'supplyRequests'), (int) $record->station_id,
                    tone: $record->status === 'open' ? 'info' : 'neutral',
                )),
        ];
    }

    /**
     * @param  list<int>  $stationIds
     * @param  list<int>  $apparatusIds
     * @param  Collection<int, Apparatus>  $apparatusById
     * @return array<string, Collection<int, mixed>>
     */
    private function loadPersistentAttention(array $stationIds, array $apparatusIds, Collection $apparatusById): array
    {
        $stationRequests = $stationIds === [] ? collect() : StationRequest::query()
            ->select(['id', 'station_id', 'request_number', 'title', 'status', 'priority', 'acknowledged_at', 'created_at'])
            ->whereIn('station_id', $stationIds)->open()->orderByDesc('created_at')->get();
        $serviceTickets = $stationIds === [] ? collect() : ApparatusServiceTicket::query()
            ->select(['id', 'station_id', 'apparatus_id', 'ticket_number', 'title', 'status', 'priority', 'acknowledged_at', 'created_at'])
            ->whereIn('station_id', $stationIds)->open()->orderByDesc('created_at')->get();
        $supplyRequests = $stationIds === [] ? collect() : StationSupplyRequest::query()
            ->select(['id', 'station_id', 'status', 'created_at'])->whereIn('station_id', $stationIds)->open()->orderByDesc('created_at')->get();
        $stationInspections = $stationIds === [] ? collect() : StationInspection::query()
            ->select(['id', 'station_id', 'inspection_type', 'overall_status', 'inspection_date', 'reviewed_at', 'created_at'])
            ->whereIn('station_id', $stationIds)->whereNull('reviewed_at')->orderByDesc('created_at')->get();
        $defects = $apparatusIds === [] ? collect() : ApparatusDefect::query()
            ->select(['id', 'apparatus_id', 'item', 'issue_type', 'status', 'operational_impact', 'created_at'])
            ->whereIn('apparatus_id', $apparatusIds)->unresolved()->orderByDesc('created_at')->get();
        $inspectionExceptions = $apparatusIds === [] ? collect() : ApparatusInspectionException::query()
            ->select(['id', 'apparatus_inspection_id', 'apparatus_id', 'field', 'reason', 'status', 'created_at'])
            ->whereIn('apparatus_id', $apparatusIds)->whereNotIn('status', ['resolved', 'dismissed'])->orderByDesc('created_at')->get();
        $followUpInspectionIds = $inspectionExceptions->pluck('apparatus_inspection_id')->map(static fn (mixed $id): int => (int) $id)->all();
        $checkoutFollowUps = $apparatusIds === [] ? collect() : ApparatusInspection::query()
            ->select(['id', 'apparatus_id', 'client_submission_id', 'designation_at_time', 'unit_number', 'processing_status', 'review_status', 'completed_at', 'created_at'])
            ->whereIn('apparatus_id', $apparatusIds)
            ->where(function (Builder $query) use ($followUpInspectionIds): void {
                $query->where('review_status', 'pending_review')->orWhere('processing_status', 'accepted_with_exception');
                if ($followUpInspectionIds !== []) {
                    $query->orWhereIn('id', $followUpInspectionIds);
                }
            })
            ->orderByRaw('COALESCE(completed_at, created_at) DESC')->get();
        $supportTickets = auth()->check() && HubSupportTicketResource::canViewAny()
            ? HubSupportTicket::query()
                ->select(['id', 'ticket_number', 'generated_title', 'impact', 'status', 'acknowledged_at', 'created_at'])
                ->open()->orderByDesc('created_at')->get()
            : collect();

        return compact(
            'stationRequests', 'serviceTickets', 'supplyRequests', 'stationInspections', 'defects',
            'inspectionExceptions', 'checkoutFollowUps', 'supportTickets', 'apparatusById',
        );
    }

    /**
     * @param  Collection<int, Station>  $stations
     * @return array{totals: array<string, int>, byStation: array<int, array<string, mixed>>, attention: Collection<int, array<string, mixed>>}
     */
    private function fleetSummary(Collection $stations): array
    {
        $display = app(DisplaySnapshotService::class);
        $totals = ['total' => 0, 'in_service' => 0, 'out_of_service' => 0, 'maintenance' => 0, 'unclassified' => 0];
        $byStation = [];
        $attention = collect();

        foreach ($stations as $station) {
            $apparatuses = $this->stationApparatus($station);
            $counts = $display->classifyApparatusCollection($apparatuses);
            $records = $apparatuses->map(function (Apparatus $apparatus) use ($display, $station, $attention): array {
                $statusValue = $apparatus->getAttribute('status');
                $status = $display->classifyApparatusStatus(is_string($statusValue) ? $statusValue : null);
                $tone = match ($status) {
                    'out_of_service' => 'critical',
                    'maintenance' => 'warning',
                    default => 'neutral',
                };
                $record = [
                    'id' => (int) $apparatus->id,
                    'label' => $this->apparatusLabel($apparatus),
                    'status' => $this->headline($status),
                    'statusKey' => $status,
                    'tone' => $tone,
                    'url' => ApparatusResource::getUrl('view', ['record' => $apparatus]),
                ];
                if ($tone !== 'neutral') {
                    $attention->push($this->attentionRow(
                        (int) $apparatus->id,
                        'apparatus_status',
                        $record['label'].' · '.$record['status'],
                        $record['status'],
                        $record['url'],
                        (int) $station->id,
                        $tone,
                        $apparatus->updated_at,
                    ));
                }

                return $record;
            })->values()->all();
            $byStation[(int) $station->id] = $counts + ['records' => $records];
            $totals['total'] += count($records);
            foreach (['in_service', 'out_of_service', 'maintenance', 'unclassified'] as $key) {
                $totals[$key] += $counts[$key];
            }
        }

        return compact('totals', 'byStation', 'attention');
    }

    /**
     * @param  Collection<int, Station>  $stations
     * @return array{approaching: int, due: int, critical: int, records: Collection<int, array<string, mixed>>, byStation: array<int, list<array<string, mixed>>>}
     */
    private function pmSummary(Collection $stations): array
    {
        $records = collect();
        $byStation = [];
        $approaching = 0;
        $due = 0;
        $critical = 0;

        foreach ($stations as $station) {
            $stationRecords = [];
            foreach ($this->stationApparatus($station) as $apparatus) {
                $health = $apparatus->getPmHealthStatus();
                if ($health['status'] === 'green') {
                    continue;
                }
                $isCritical = $health['status'] === 'red' && $health['overdue'];
                $status = $health['status'] === 'yellow' ? 'PM approaching' : ($isCritical ? 'PM critically overdue' : 'PM due');
                $tone = $isCritical ? 'critical' : 'warning';
                if ($health['status'] === 'yellow') {
                    $approaching++;
                } elseif ($isCritical) {
                    $critical++;
                    $due++;
                } else {
                    $due++;
                }
                $row = $this->attentionRow(
                    (int) $apparatus->id,
                    'pm',
                    $this->apparatusLabel($apparatus).' · '.$status,
                    $status,
                    ApparatusResource::getUrl('view', ['record' => $apparatus]),
                    (int) $station->id,
                    $tone,
                );
                $records->push($row);
                $stationRecords[] = $row;
            }
            $byStation[(int) $station->id] = $stationRecords;
        }

        return compact('approaching', 'due', 'critical', 'records', 'byStation');
    }

    /**
     * @param  array<string, Collection<int, mixed>>  $attention
     * @param  Collection<int, array<string, mixed>>  $fleetAttention
     * @param  Collection<int, array<string, mixed>>  $pmRecords
     * @param  Collection<int, string>  $stationNumbersById
     * @return Collection<int, array<string, mixed>>
     */
    private function departmentAttention(array $attention, Collection $fleetAttention, Collection $pmRecords, Collection $stationNumbersById): Collection
    {
        /** @var Collection<int, Apparatus> $apparatusById */
        $apparatusById = $attention['apparatusById'];
        $rows = collect();

        foreach ($attention['supportTickets'] as $record) {
            $impact = $this->enumValue($record->impact);
            $rows->push($this->attentionRow(
                (int) $record->id, 'support_ticket', $record->ticket_number.' · '.$record->generated_title,
                $record->acknowledged_at === null ? 'New '.$this->headline($impact).' issue' : $this->headline($this->enumValue($record->status)),
                HubSupportTicketResource::getUrl('view', ['record' => $record]), null,
                in_array($impact, ['task_blocking', 'feature_unavailable'], true) ? 'critical' : ($record->acknowledged_at === null ? 'info' : 'neutral'),
                $record->created_at,
            ));
        }
        foreach ($attention['stationInspections'] as $record) {
            $rows->push($this->attentionRow(
                (int) $record->id, 'station_inspection', 'Station '.($stationNumbersById->get((int) $record->station_id) ?? '—').' inspection',
                'Awaiting review', StationInspectionResource::getUrl('view', ['record' => $record]),
                (int) $record->station_id, 'warning', $record->created_at,
            ));
        }
        foreach ($attention['stationRequests'] as $record) {
            $priority = (string) $record->priority;
            $rows->push($this->attentionRow(
                (int) $record->id, 'station_request', $record->request_number ?: $record->title ?: 'Station request',
                $record->acknowledged_at === null ? 'New · '.$this->headline((string) $record->status) : $this->headline((string) $record->status),
                StationRequestResource::getUrl('view', ['record' => $record]), (int) $record->station_id,
                $priority === 'critical' ? 'critical' : (in_array($priority, ['high', 'urgent'], true) ? 'warning' : ($record->acknowledged_at === null ? 'info' : 'neutral')),
                $record->created_at,
            ));
        }
        foreach ($attention['checkoutFollowUps'] as $record) {
            $apparatus = $apparatusById->get((int) $record->apparatus_id);
            $rows->push($this->attentionRow(
                (int) $record->id, 'checkout_follow_up', ($record->designation_at_time ?: $apparatus?->designation ?: $record->unit_number ?: 'Apparatus').' checkout',
                $record->displayStatus(), InspectionResource::getUrl('view', ['record' => $record]),
                $apparatus instanceof Apparatus ? (int) $apparatus->station_id : null, 'warning', $record->completed_at ?: $record->created_at,
            ));
        }
        foreach ($attention['serviceTickets'] as $record) {
            $priority = (string) $record->priority;
            $rows->push($this->attentionRow(
                (int) $record->id, 'service_ticket', $record->ticket_number ?: $record->title ?: 'Service ticket',
                $this->headline((string) $record->status), ApparatusServiceTicketResource::getUrl('view', ['record' => $record]),
                (int) $record->station_id,
                $priority === 'critical' ? 'critical' : (in_array($priority, ['attention', 'urgent', 'high'], true) ? 'warning' : 'neutral'),
                $record->created_at,
            ));
        }
        foreach ($attention['defects'] as $record) {
            $apparatus = $apparatusById->get((int) $record->apparatus_id);
            $impact = (string) ($record->operational_impact ?: 'unclassified');
            $rows->push($this->attentionRow(
                (int) $record->id, 'defect', ($apparatus instanceof Apparatus ? $this->apparatusLabel($apparatus) : 'Apparatus').' · '.($record->item ?: $this->headline((string) $record->issue_type)),
                $this->headline($impact), DefectResource::getUrl('index'),
                $apparatus instanceof Apparatus ? (int) $apparatus->station_id : null,
                $impact === 'out_of_service' ? 'critical' : (in_array($impact, ['needs_repair', 'needs_admin_review'], true) ? 'warning' : 'neutral'),
                $record->created_at,
            ));
        }

        return $rows->concat($fleetAttention)->concat($pmRecords)
            ->sortByDesc(fn (array $row): string => ($row['tone'] === 'critical' ? '3' : ($row['tone'] === 'warning' ? '2' : '1')).($row['sortTimestamp'] ?? ''))
            ->values();
    }

    /**
     * @param  Collection<int, Station>  $stations
     * @param  array<string, Collection<int, array<string, mixed>>>  $activity
     * @param  array<string, Collection<int, mixed>>  $attention
     * @param  array<int, array<string, mixed>>  $fleetByStation
     * @param  array<int, list<array<string, mixed>>>  $pmByStation
     * @return array<int, array<string, mixed>>
     */
    private function stationData(Collection $stations, array $activity, array $attention, array $fleetByStation, array $pmByStation): array
    {
        /** @var Collection<int, Apparatus> $apparatusById */
        $apparatusById = $attention['apparatusById'];
        $data = [];

        foreach ($stations as $station) {
            $stationId = (int) $station->id;
            $stationActivity = collect($activity)->except('checkouts')->flatten(1)
                ->concat($activity['checkouts'])
                ->where('stationId', $stationId)
                ->sortByDesc('sortTimestamp')->values();
            $stationExceptions = collect()
                ->concat($attention['stationRequests']->where('station_id', $stationId)->map(fn (StationRequest $record): array => $this->attentionRow(
                    (int) $record->id, 'station_request', $record->request_number ?: $record->title ?: 'Station request',
                    $this->headline((string) $record->status), StationRequestResource::getUrl('view', ['record' => $record]), $stationId,
                    in_array((string) $record->priority, ['critical', 'high', 'urgent'], true) ? 'warning' : 'neutral', $record->created_at,
                )))
                ->concat($attention['serviceTickets']->where('station_id', $stationId)->map(fn (ApparatusServiceTicket $record): array => $this->attentionRow(
                    (int) $record->id, 'service_ticket', $record->ticket_number ?: $record->title ?: 'Service ticket',
                    $this->headline((string) $record->status), ApparatusServiceTicketResource::getUrl('view', ['record' => $record]), $stationId,
                    in_array((string) $record->priority, ['critical', 'attention', 'high', 'urgent'], true) ? 'warning' : 'neutral', $record->created_at,
                )))
                ->concat($attention['stationInspections']->where('station_id', $stationId)->map(fn (StationInspection $record): array => $this->attentionRow(
                    (int) $record->id, 'station_inspection', 'Station inspection', 'Awaiting review',
                    StationInspectionResource::getUrl('view', ['record' => $record]), $stationId, 'warning', $record->created_at,
                )))
                ->concat($attention['checkoutFollowUps']->filter(function (ApparatusInspection $record) use ($apparatusById, $stationId): bool {
                    $apparatus = $apparatusById->get((int) $record->apparatus_id);

                    return $apparatus instanceof Apparatus && (int) $apparatus->station_id === $stationId;
                })->map(function (ApparatusInspection $record) use ($apparatusById, $stationId): array {
                    $apparatus = $apparatusById->get((int) $record->apparatus_id);

                    return $this->attentionRow(
                        (int) $record->id, 'checkout_follow_up',
                        ($record->designation_at_time ?: $apparatus?->designation ?: $record->unit_number ?: 'Apparatus').' checkout',
                        $record->displayStatus(), InspectionResource::getUrl('view', ['record' => $record]),
                        $stationId, 'warning', $record->completed_at ?: $record->created_at,
                    );
                }))
                ->concat($attention['supplyRequests']->where('station_id', $stationId)->map(fn (StationSupplyRequest $record): array => $this->attentionRow(
                    (int) $record->id, 'supply_request', 'Supply request', $this->headline((string) $record->status),
                    $this->stationRelationUrl($stationId, 'supplyRequests'), $stationId, 'neutral', $record->created_at,
                )))
                ->concat($attention['defects']->filter(function (ApparatusDefect $record) use ($apparatusById, $stationId): bool {
                    $apparatus = $apparatusById->get((int) $record->apparatus_id);

                    return $apparatus instanceof Apparatus && (int) $apparatus->station_id === $stationId;
                })->map(function (ApparatusDefect $record) use ($apparatusById, $stationId): array {
                    $apparatus = $apparatusById->get((int) $record->apparatus_id);

                    return $this->attentionRow(
                        (int) $record->id, 'defect', ($apparatus?->designation ?: 'Apparatus').' · '.($record->item ?: 'Finding'),
                        $this->headline((string) ($record->operational_impact ?: 'unclassified')), DefectResource::getUrl('index'),
                        $stationId, $record->operational_impact === 'out_of_service' ? 'critical' : 'neutral', $record->created_at,
                    );
                }))
                ->concat(collect($fleetByStation[$stationId]['records'] ?? [])->filter(
                    static fn (array $record): bool => ($record['tone'] ?? 'neutral') !== 'neutral',
                )->map(fn (array $record): array => $this->attentionRow(
                    (int) $record['id'], 'apparatus_status', $record['label'].' · '.$record['status'],
                    $record['status'], $record['url'], $stationId, $record['tone'],
                )))
                ->concat($pmByStation[$stationId] ?? [])
                ->sortByDesc('sortTimestamp')->values();
            $counts = [
                'stationRequests' => $attention['stationRequests']->where('station_id', $stationId)->count(),
                'serviceTickets' => $attention['serviceTickets']->where('station_id', $stationId)->count(),
                'defects' => $attention['defects']->filter(function (ApparatusDefect $record) use ($apparatusById, $stationId): bool {
                    $apparatus = $apparatusById->get((int) $record->apparatus_id);

                    return $apparatus instanceof Apparatus && (int) $apparatus->station_id === $stationId;
                })->count(),
                'missingDefects' => $attention['defects']->filter(function (ApparatusDefect $record) use ($apparatusById, $stationId): bool {
                    $apparatus = $apparatusById->get((int) $record->apparatus_id);

                    return $record->issue_type === 'missing' && $apparatus instanceof Apparatus && (int) $apparatus->station_id === $stationId;
                })->count(),
                'supplyRequests' => $attention['supplyRequests']->where('station_id', $stationId)->count(),
            ];

            $data[$stationId] = [
                'stationNumber' => (int) $station->station_number,
                'stationName' => $station->name,
                'stationUrl' => StationResource::getUrl('view', ['record' => $station]),
                'apparatus' => $fleetByStation[$stationId] ?? ['records' => []],
                'pm' => $pmByStation[$stationId] ?? [],
                'activity' => $stationActivity->all(),
                'exceptions' => $stationExceptions->all(),
                'attentionCount' => $stationExceptions->count(),
                'counts' => $counts,
                'links' => [
                    'requests' => $this->stationFilterUrl(StationRequestResource::getUrl('index'), $stationId),
                    'tickets' => $this->stationFilterUrl(ApparatusServiceTicketResource::getUrl('index'), $stationId),
                    'defects' => $this->stationFilterUrl(DefectResource::getUrl('index'), $stationId),
                    'supplies' => $this->stationRelationUrl($stationId, 'supplyRequests'),
                    'inventorySubmissions' => $this->stationRelationUrl($stationId, 'inventorySubmissions'),
                    'inspectionExceptions' => $this->stationFilterUrl(ApparatusInspectionExceptionResource::getUrl('index'), $stationId),
                ],
            ];
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function activityRow(int $id, string $type, string $label, string $status, mixed $timestamp, string $url, ?int $stationId, ?int $apparatusId = null, string $tone = 'neutral'): array
    {
        $time = $this->localTimestamp($timestamp);

        return compact('id', 'type', 'label', 'status', 'url', 'stationId', 'apparatusId', 'tone') + [
            'timestamp' => $time?->toIso8601String(),
            'timeLabel' => $time?->format('g:i A') ?? 'Time unavailable',
            'sortTimestamp' => $time?->utc()->format('Y-m-d\TH:i:s.u\Z') ?? '',
        ];
    }

    /** @return array<string, mixed> */
    private function attentionRow(int $id, string $type, string $label, string $status, string $url, ?int $stationId, string $tone, mixed $timestamp = null): array
    {
        $time = $this->localTimestamp($timestamp);

        return compact('id', 'type', 'label', 'status', 'url', 'stationId', 'tone') + [
            'timestamp' => $time?->toIso8601String(),
            'timeLabel' => $time?->format('M j, g:i A'),
            'sortTimestamp' => $time?->utc()->format('Y-m-d\TH:i:s.u\Z') ?? '',
        ];
    }

    private function localTimestamp(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Carbon::parse($value)->timezone(OperationalDisplayWindow::TIMEZONE);
    }

    private function stationFilterUrl(string $url, int $stationId): string
    {
        return $url.'?'.http_build_query(['tableFilters' => ['station_id' => ['value' => $stationId]]]);
    }

    private function stationRelationUrl(int $stationId, string $relation): string
    {
        return StationResource::getUrl('view', ['record' => $stationId]).'?'.http_build_query(['activeRelationManager' => $relation]);
    }

    /**
     * @param  Collection<int, Station>  $stations
     * @return Collection<int, Apparatus>
     */
    private function apparatusById(Collection $stations): Collection
    {
        $records = [];
        foreach ($stations as $station) {
            foreach ($this->stationApparatus($station) as $apparatus) {
                $records[(int) $apparatus->getKey()] = $apparatus;
            }
        }

        return collect($records);
    }

    /** @return Collection<int, Apparatus> */
    private function stationApparatus(Station $station): Collection
    {
        $records = [];
        foreach ($station->apparatuses as $record) {
            if ($record instanceof Apparatus) {
                $records[] = $record;
            }
        }

        return collect($records);
    }

    private function apparatusLabel(Apparatus $apparatus): string
    {
        return (string) ($apparatus->designation ?: $apparatus->getAttribute('unit_id') ?: 'Apparatus');
    }

    /** @param list<string> $stationNumbers */
    private function stationOrderSql(array $stationNumbers): string
    {
        $clauses = collect($stationNumbers)->values()->map(
            static fn (string $number, int $index): string => "WHEN '".str_replace("'", "''", $number)."' THEN ".($index + 1),
        )->implode(' ');

        return "CASE station_number {$clauses} ELSE ".(count($stationNumbers) + 1).' END';
    }

    private function headline(string $value): string
    {
        return str($value)->replace('_', ' ')->headline()->toString();
    }

    private function enumValue(mixed $value): string
    {
        return $value instanceof BackedEnum ? (string) $value->value : (string) $value;
    }

    private function markSuccessfulRefresh(): void
    {
        $this->lastSuccessfulRefreshAt = Carbon::now(OperationalDisplayWindow::TIMEZONE)->toIso8601String();
    }
}
