<?php

declare(strict_types=1);

namespace App\Services\Display;

use App\Models\Apparatus;
use App\Models\ApparatusDefect;
use App\Models\ApparatusDefectRecommendation;
use App\Models\ApparatusInspection;
use App\Models\EmployeeEquipmentRequest;
use App\Models\EquipmentItem;
use App\Models\Station;
use App\Models\StationInspection;
use App\Models\StationRequest;
use App\Models\StationRequestUpdate;
use App\Models\StationSupplyRequest;
use App\Services\DailyCheckoutComplianceService;
use App\Services\StationStaffingService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Read-only snapshot rollup for the command display dashboard.
 *
 * Every method here is a pure reader: it queries Eloquent, aggregates in PHP,
 * redacts all sensitive/personnel fields, and returns plain arrays. It NEVER
 * writes, saves, or dispatches a mutating job. Per-station rollups are
 * batch-loaded (no N+1), mirroring StationOperationsHubWidget but with full
 * redaction applied.
 *
 * Apparatus status strings drift across imports ('In Service' vs 'in_service'
 * vs 'Active'); all status comparisons here are normalised and matched
 * case-insensitively via {@see classifyStatus()}.
 */
final class DisplaySnapshotService
{
    public const SNAPSHOT_CACHE_KEY = 'display.snapshot';

    public const STATIONS_CACHE_KEY = 'display.stations';

    public const CACHE_TTL = 300; // 5 minutes

    private const STATUS_IN_SERVICE = 'in_service';

    private const STATUS_OUT_OF_SERVICE = 'out_of_service';

    private const STATUS_MAINTENANCE = 'maintenance';

    /**
     * Full department snapshot (discovery report §9 schema).
     *
     * @return array<string, mixed>
     */
    public function overview(): array
    {
        return Cache::remember(self::SNAPSHOT_CACHE_KEY, self::CACHE_TTL, function (): array {
            $generatedAt = Carbon::now();

            $stations = $this->stationRollup();
            $apparatusCounts = $this->apparatusStatusCounts();
            $pmHealth = $this->pmHealthCounts();
            $defects = $this->defectSummary();
            $dailyCheckout = app(DailyCheckoutComplianceService::class)
                ->summaryForApparatuses(
                    Apparatus::query()
                        ->get(['id', 'status', 'daily_checkout_requirement'])
                );
            $submissions = $this->submissionSummary($dailyCheckout);
            $requests = $this->requestSummary($stations['stationIds']);
            $inventory = $this->inventoryExceptions();

            $apparatusTotal = $apparatusCounts['in_service']
                + $apparatusCounts['out_of_service']
                + $apparatusCounts['maintenance']
                + $apparatusCounts['unclassified'];

            $readinessPercent = $this->aggregateReadinessPercent($stations['rows']);

            return [
                'metadata' => [
                    'generated_at' => $generatedAt->toISOString(),
                    'cache_ttl_seconds' => self::CACHE_TTL,
                    'environment' => (string) app()->environment(),
                ],
                'organization' => [
                    'name' => 'Miami Beach Fire Department',
                ],
                'overview' => [
                    'stations_total' => $stations['total'],
                    'stations_active' => $stations['active'],
                    'apparatus_total' => $apparatusTotal,
                    'apparatus_status' => [
                        'in_service' => $apparatusCounts['in_service'],
                        'out_of_service' => $apparatusCounts['out_of_service'],
                        'maintenance' => $apparatusCounts['maintenance'],
                    ],
                    'pm_health' => [
                        'green' => $pmHealth['green'],
                        'yellow' => $pmHealth['yellow'],
                        'red' => $pmHealth['red'],
                        'critical_overdue' => $pmHealth['critical_overdue'],
                    ],
                    'readiness_percent' => $readinessPercent,
                ],
                'stations' => $stations['rows'],
                'defects' => $defects,
                'submissions' => $submissions,
                'requests' => $requests,
                'inventory_exceptions' => $inventory,
                'source_health' => $this->sourceHealth($generatedAt),
            ];
        });
    }

    /**
     * Slim per-station readiness grid.
     *
     * @return array<string, mixed>
     */
    public function stations(): array
    {
        return Cache::remember(self::STATIONS_CACHE_KEY, self::CACHE_TTL, function (): array {
            $rollup = $this->stationRollup();

            return [
                'generated_at' => Carbon::now()->toISOString(),
                'stations' => $rollup['rows'],
            ];
        });
    }

    /**
     * Detail for one active station (404 sentinel when missing/inactive).
     *
     * @return array<string, mixed>
     */
    public function stationDetail(int $id): array
    {
        $station = $this->findActiveStation($id);
        if ($station === null) {
            return ['found' => false];
        }

        /** @var Collection<int, Apparatus> $apparatuses */
        $apparatuses = $station->apparatuses;
        $apparatusIds = $apparatuses->pluck('id')->all();
        $dailyCheckout = app(DailyCheckoutComplianceService::class)
            ->summaryForApparatuses($apparatuses);
        $inspectionsToday = $dailyCheckout['completed'];
        $stationInspections30d = StationInspection::query()
            ->where('station_id', $id)
            ->where('inspection_date', '>=', Carbon::now()->subDays(30))
            ->count();
        $requestQuery = StationRequest::query()->where('station_id', $id);
        $stationRequests = (clone $requestQuery)->count();
        $openStationRequests = (clone $requestQuery)->open()->count();
        $repairRequests = (clone $requestQuery)->where('request_type', 'repair_service')->count();
        $equipmentRequests = (clone $requestQuery)->where('request_type', 'equipment')->count();
        $openDefects = $this->openDefectsForApparatus($apparatusIds);
        $supplyRequests = StationSupplyRequest::query()
            ->where('station_id', $id)
            ->where('status', 'open')
            ->count();

        $statusCounts = $this->classifyApparatusCollection($apparatuses);
        $criticalDefectCount = $this->countCriticalDefects($apparatusIds);
        $lastInspection = $this->lastStationInspection($id);
        $pendingEquip = $this->pendingStationEquipmentCounts([$id]);
        $staffing = app(StationStaffingService::class)->summaryFor($station);

        $readiness = DisplayReadiness::compute(
            requiredApparatusCount: $dailyCheckout['required_total'],
            checkedApparatusCount: $dailyCheckout['checked'],
            attentionApparatusCount: $dailyCheckout['attention'],
            reviewPendingApparatusCount: $dailyCheckout['review_pending'],
            notCheckedApparatusCount: $dailyCheckout['not_checked'],
            unknownApparatusCount: $dailyCheckout['classification_required'],
            inServiceCount: $statusCounts['in_service'],
            outOfServiceCount: $dailyCheckout['out_of_service'],
            maintenanceCount: $statusCounts['maintenance'],
            openDefects: $openDefects,
            criticalDefects: $criticalDefectCount,
            lastStationInspectionStatus: $lastInspection['status'],
            stationInspectionAgeDays: $lastInspection['age_days'],
            pendingEquipmentRequests: $pendingEquip[$id]['pending'] ?? 0,
            criticalPendingEquipmentRequests: $pendingEquip[$id]['critical'] ?? 0,
            snapshotAgeSeconds: 0,
        );

        return [
            'found' => true,
            'generated_at' => Carbon::now()->toISOString(),
            'station' => [
                'id' => $station->id,
                'number' => $station->station_number,
                'name' => $station->getRawOriginal('name') ?: ('Station '.$station->station_number),
                'address' => $station->address ?? '',
                'city' => $station->city ?? '',
                'state' => $station->state ?? '',
                'zip_code' => $station->zip_code ?? '',
                'latitude' => $station->latitude,
                'longitude' => $station->longitude,
                'is_active' => (bool) $station->is_active,
            ],
            'apparatus' => $this->redactedApparatus($apparatuses),
            'counts' => [
                'inspections_today' => $inspectionsToday,
                'station_inspections_30d' => $stationInspections30d,
                'station_requests' => $stationRequests,
                'open_station_requests' => $openStationRequests,
                'repair_service_requests' => $repairRequests,
                'equipment_requests' => $equipmentRequests,
                'open_defects' => $openDefects,
                'supply_requests' => $supplyRequests,
                'daily_checkout' => $dailyCheckout,
            ],
            'readiness' => [
                'percent' => $readiness['percent'],
                'status' => $readiness['status'],
                'reasons' => $readiness['reasons'],
            ],
        ];
    }

    /**
     * Redacted apparatus list for one station (404 sentinel when missing).
     *
     * @return array<string, mixed>
     */
    public function stationApparatus(int $id): array
    {
        $station = $this->findActiveStation($id);
        if ($station === null) {
            return ['found' => false];
        }

        /** @var Collection<int, Apparatus> $apparatuses */
        $apparatuses = $station->apparatuses;

        return [
            'found' => true,
            'generated_at' => Carbon::now()->toISOString(),
            'station_id' => $station->id,
            'apparatus' => $this->redactedApparatus($apparatuses),
            'daily_checkout' => app(DailyCheckoutComplianceService::class)
                ->summaryForApparatuses($apparatuses),
        ];
    }

    /**
     * Recent submissions for one station, with all personnel identity redacted.
     *
     * @return array<string, mixed>
     */
    public function stationSubmissions(int $id): array
    {
        $station = $this->findActiveStation($id);
        if ($station === null) {
            return ['found' => false];
        }

        /** @var Collection<int, Apparatus> $apparatuses */
        $apparatuses = $station->apparatuses;
        $apparatusIds = $apparatuses->pluck('id')->all();
        $dailyCheckout = app(DailyCheckoutComplianceService::class)
            ->summaryForApparatuses($apparatuses);

        $apparatusInspections = empty($apparatusIds)
            ? collect()
            : ApparatusInspection::query()
                ->with('apparatus:id,unit_id,designation,name')
                ->whereIn('apparatus_id', $apparatusIds)
                ->orderByDesc('created_at')
                ->limit(25)
                ->get(['id', 'apparatus_id', 'shift', 'review_status', 'completed_at', 'created_at']);

        $stationInspections = StationInspection::query()
            ->where('station_id', $id)
            ->where('inspection_date', '>=', Carbon::now()->subDays(30))
            ->orderByDesc('inspection_date')
            ->limit(25)
            ->get(['id', 'station_id', 'inspection_date', 'inspection_type', 'overall_status', 'created_at']);
        $stationRequests = StationRequest::query()
            ->where('station_id', $id)
            ->with('updates:id,station_request_id,status,public_note,created_at')
            ->orderByDesc('created_at')
            ->limit(25)
            ->get(['id', 'station_id', 'request_number', 'request_type', 'title', 'priority', 'status', 'current_public_response', 'created_at']);

        return [
            'found' => true,
            'generated_at' => Carbon::now()->toISOString(),
            'station_id' => $station->id,
            // This endpoint also exposes recent inspection history, but that
            // history is never a readiness source. Consumers must use this
            // canonical matrix/summary for Daily Checkout state.
            'daily_checkout' => $dailyCheckout,
            'apparatus_inspection_history_only' => true,
            'apparatus_inspections' => $apparatusInspections->map(fn (ApparatusInspection $i): array => [
                'id' => $i->id,
                'apparatus_name' => $i->apparatus?->designation
                    ?: $i->apparatus?->name
                    ?: $i->apparatus?->unit_id
                    ?: 'Unknown',
                'shift' => $i->shift,
                'submitted_at' => optional($i->created_at)->toISOString(),
                'completed_at' => optional($i->completed_at)->toISOString(),
                'review_status' => $i->review_status,
            ])->values()->all(),
            'station_inspections' => $stationInspections->map(fn (StationInspection $i): array => [
                'id' => $i->id,
                'inspection_date' => optional($i->inspection_date)->toDateString(),
                'inspection_type' => $i->inspection_type,
                'overall_status' => $i->overall_status,
                'created_at' => optional($i->created_at)->toISOString(),
            ])->values()->all(),
            'station_requests' => $stationRequests->map(fn (StationRequest $r): array => [
                'id' => $r->id,
                'request_number' => $r->request_number,
                'request_type' => $r->request_type,
                'title' => $r->title,
                'priority' => $r->priority,
                'status' => $r->status,
                'public_response' => $r->current_public_response,
                'created_at' => optional($r->created_at)->toISOString(),
                'updates' => $r->updates->map(fn (StationRequestUpdate $update): array => [
                    'status' => $update->status,
                    'public_note' => $update->public_note,
                    'created_at' => optional($update->created_at)->toISOString(),
                ])->values()->all(),
            ])->values()->all(),
        ];
    }

    /**
     * Department-wide critical items: critical defects + low/out-of-stock +
     * pending recommendations, all redacted of free text and identity.
     *
     * @return array<string, mixed>
     */
    public function criticalItems(): array
    {
        $criticalDefects = ApparatusDefect::query()
            ->with('apparatus:id,unit_id,designation,name')
            ->where('resolved', false)
            ->where('status', 'Missing')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get(['id', 'apparatus_id', 'item', 'issue_type', 'status', 'reported_date', 'created_at']);

        $stockItems = EquipmentItem::query()
            ->with('location')
            ->where('is_active', true)
            ->get()
            ->filter(fn (EquipmentItem $item): bool => $item->stock <= $item->reorder_min)
            ->sortBy('stock')
            ->take(25);

        $pendingRecommendations = ApparatusDefectRecommendation::query()
            ->where('status', 'pending')
            ->count();

        return [
            'generated_at' => Carbon::now()->toISOString(),
            'critical_defects' => $criticalDefects->map(fn (ApparatusDefect $d): array => [
                'id' => $d->id,
                'apparatus_name' => $d->apparatus?->designation
                    ?: $d->apparatus?->name
                    ?: $d->apparatus?->unit_id
                    ?: 'Unknown',
                'item' => $d->item,
                'issue_type' => $d->issue_type,
                'status' => $d->status,
                'reported_date' => optional($d->reported_date)->toDateString()
                    ?? optional($d->created_at)->toDateString(),
            ])->values()->all(),
            'low_stock' => $stockItems->map(fn (EquipmentItem $item): array => [
                'name' => $item->name,
                'stock' => (int) $item->stock,
                'reorder_min' => (int) $item->reorder_min,
                'out_of_stock' => $item->stock <= 0,
                'location' => $item->location?->full_location ?? 'Unknown',
            ])->values()->all(),
            'pending_recommendations' => $pendingRecommendations,
        ];
    }

    // ── Internal aggregation helpers ─────────────────────────────────────

    /**
     * Batch-loaded per-station rollup. Returns slim readiness rows plus the
     * raw station-id list reused by the overview request summaries.
     *
     * @return array{rows: list<array<string, mixed>>, stationIds: list<int>, total: int, active: int}
     */
    private function stationRollup(): array
    {
        $totalStations = Station::query()->count();

        // Select all columns rather than an explicit projection: the deployed
        // stations schema has drifted from the committed migration set (e.g.
        // latitude/longitude exist in prod but not in every environment), so
        // we read those as nullable attributes instead of enumerating them.
        /** @var Collection<int, Station> $stations */
        $stations = Station::query()
            ->with('apparatuses:id,station_id,unit_id,designation,status,daily_checkout_requirement')
            ->where('is_active', true)
            ->orderBy('station_number')
            ->get();

        $stationIds = $stations->pluck('id')->all();
        $allApparatusIds = $stations->flatMap(fn (Station $s) => $s->apparatuses->pluck('id'))->all();

        // Batch-load all per-station signal sources, then group in PHP.
        $openDefectsByApparatus = $this->openDefectCountsByApparatus($allApparatusIds);
        $criticalDefectsByApparatus = $this->criticalDefectCountsByApparatus($allApparatusIds);
        $lastInspectionByStation = $this->lastStationInspectionByStation($stationIds);
        $pendingEquipByStation = $this->pendingStationEquipmentCounts($stationIds);
        $staffingService = app(StationStaffingService::class);
        $dailyCheckoutByStation = app(DailyCheckoutComplianceService::class)
            ->summariesForStations($stations);

        $rows = $stations->map(function (Station $station) use (
            $openDefectsByApparatus,
            $criticalDefectsByApparatus,
            $lastInspectionByStation,
            $pendingEquipByStation,
            $staffingService,
            $dailyCheckoutByStation,
        ): array {
            /** @var Collection<int, Apparatus> $apparatuses */
            $apparatuses = $station->apparatuses;
            $apparatusIds = $apparatuses->pluck('id')->all();

            $openDefects = 0;
            $criticalDefects = 0;
            foreach ($apparatusIds as $aid) {
                $openDefects += $openDefectsByApparatus[$aid] ?? 0;
                $criticalDefects += $criticalDefectsByApparatus[$aid] ?? 0;
            }

            $dailyCheckout = $dailyCheckoutByStation[$station->id];
            $statusCounts = $this->classifyApparatusCollection($apparatuses);
            $lastInspection = $lastInspectionByStation[$station->id]
                ?? ['status' => null, 'age_days' => null];
            $pendingEquip = $pendingEquipByStation[$station->id]
                ?? ['pending' => 0, 'critical' => 0];
            $staffing = $staffingService->summaryFor($station);

            $readiness = DisplayReadiness::compute(
                requiredApparatusCount: $dailyCheckout['required_total'],
                checkedApparatusCount: $dailyCheckout['checked'],
                attentionApparatusCount: $dailyCheckout['attention'],
                reviewPendingApparatusCount: $dailyCheckout['review_pending'],
                notCheckedApparatusCount: $dailyCheckout['not_checked'],
                unknownApparatusCount: $dailyCheckout['classification_required'],
                inServiceCount: $statusCounts['in_service'],
                outOfServiceCount: $dailyCheckout['out_of_service'],
                maintenanceCount: $statusCounts['maintenance'],
                openDefects: $openDefects,
                criticalDefects: $criticalDefects,
                lastStationInspectionStatus: $lastInspection['status'],
                stationInspectionAgeDays: $lastInspection['age_days'],
                pendingEquipmentRequests: $pendingEquip['pending'],
                criticalPendingEquipmentRequests: $pendingEquip['critical'],
                snapshotAgeSeconds: 0,
            );

            return [
                'id' => $station->id,
                'number' => $station->station_number,
                'name' => $station->getRawOriginal('name') ?: ('Station '.$station->station_number),
                'latitude' => $station->latitude,
                'longitude' => $station->longitude,
                'apparatus_count' => $staffing['assigned_apparatus_count'],
                'assigned_apparatus_count' => $staffing['assigned_apparatus_count'],
                'assigned_personnel_count' => $staffing['assigned_personnel_count'],
                'dorm_beds_count' => $staffing['dorm_beds_count'],
                'in_service' => $staffing['in_service_assigned_count'],
                'out_of_service' => $staffing['out_of_service_assigned_count'],
                'maintenance' => $staffing['maintenance_assigned_count'],
                'daily_checkout' => $dailyCheckout,
                'open_defects' => $openDefects,
                'readiness_percent' => $readiness['percent'],
                'readiness_status' => $readiness['status'],
                'readiness_reasons' => $readiness['reasons'],
            ];
        })->values()->all();

        return [
            'rows' => $rows,
            'stationIds' => $stationIds,
            'total' => $totalStations,
            'active' => $stations->count(),
        ];
    }

    /**
     * @return array{green: int, yellow: int, red: int, critical_overdue: int}
     */
    private function pmHealthCounts(): array
    {
        $counts = ['green' => 0, 'yellow' => 0, 'red' => 0, 'critical_overdue' => 0];

        Apparatus::query()
            ->select([
                'id',
                'current_engine_hours',
                'current_miles',
                'last_pm_engine_hours',
                'last_pm_mileage',
                'last_pm_date',
                'pm_interval_hours',
            ])
            ->chunk(200, function (Collection $chunk) use (&$counts): void {
                foreach ($chunk as $apparatus) {
                    $health = $apparatus->getPmHealthStatus();
                    $status = $health['status'] ?? 'green';
                    if (isset($counts[$status])) {
                        $counts[$status]++;
                    }
                    if (($health['overdue'] ?? false) === true) {
                        $counts['critical_overdue']++;
                    }
                }
            });

        return $counts;
    }

    /**
     * @return array{in_service: int, out_of_service: int, maintenance: int, unclassified: int}
     */
    private function apparatusStatusCounts(): array
    {
        $counts = ['in_service' => 0, 'out_of_service' => 0, 'maintenance' => 0, 'unclassified' => 0];

        Apparatus::query()
            ->select(['id', 'status'])
            ->chunk(500, function (Collection $chunk) use (&$counts): void {
                foreach ($chunk as $apparatus) {
                    $counts[$this->classifyStatus($apparatus->status)]++;
                }
            });

        return $counts;
    }

    /**
     * @return array{total_open: int, critical_missing: int, items: list<array<string, mixed>>}
     */
    private function defectSummary(): array
    {
        $totalOpen = ApparatusDefect::query()->where('resolved', false)->count();
        $criticalMissing = ApparatusDefect::query()
            ->where('resolved', false)
            ->where('status', 'Missing')
            ->count();

        $items = ApparatusDefect::query()
            ->with('apparatus:id,unit_id,designation,name')
            ->where('resolved', false)
            ->orderByRaw("CASE WHEN status = 'Missing' THEN 0 ELSE 1 END")
            ->orderByDesc('created_at')
            ->limit(25)
            ->get(['id', 'apparatus_id', 'item', 'issue_type', 'status', 'created_at'])
            ->map(fn (ApparatusDefect $d): array => [
                'id' => $d->id,
                'apparatus_name' => $d->apparatus?->designation
                    ?: $d->apparatus?->name
                    ?: $d->apparatus?->unit_id
                    ?: 'Unknown',
                'item' => $d->item,
                'issue_type' => $d->issue_type,
                'status' => $d->status,
            ])->values()->all();

        return [
            'total_open' => $totalOpen,
            'critical_missing' => $criticalMissing,
            'items' => $items,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    /** @param array<string, mixed> $dailyCheckout */
    private function submissionSummary(array $dailyCheckout): array
    {
        $stationInspections30d = StationInspection::query()
            ->where('inspection_date', '>=', Carbon::now()->subDays(30));

        $passCount = (clone $stationInspections30d)->where('overall_status', 'pass')->count();
        $totalRecent = $stationInspections30d->count();
        $passRate = $totalRecent > 0 ? (int) round(($passCount / $totalRecent) * 100) : null;

        // "Pending review" station inspections: submitted but not yet reviewed.
        $stationPendingReview = StationInspection::query()->whereNull('reviewed_by')->count();

        // Apparatus inspections flagged for review (H-01 review queue).
        $apparatusPendingReview = ApparatusInspection::query()
            ->where('review_status', 'pending_review')
            ->count();

        return [
            // The top-level Daily Checkout object is the only canonical
            // completion source. The legacy `inspections` week/month values
            // remain record-history diagnostics and must never be used to
            // derive per-apparatus readiness.
            'daily_checkout' => $dailyCheckout,
            'inspections' => [
                'today' => $dailyCheckout['completed'],
                'this_week' => ApparatusInspection::query()->where('completed_at', '>=', Carbon::now()->startOfWeek())->count(),
                'this_month' => ApparatusInspection::query()->where('completed_at', '>=', Carbon::now()->startOfMonth())->count(),
                'pending_review' => $dailyCheckout['review_pending'],
                'history_records_pending_review' => $apparatusPendingReview,
            ],
            'station_inspections' => [
                'pending_review' => $stationPendingReview,
                'pass_rate_30d' => $passRate,
            ],
            'inventory' => $this->inventorySubmissionCounts(),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function inventorySubmissionCounts(): array
    {
        return [
            'total_active_items' => EquipmentItem::query()->where('is_active', true)->count(),
            'pending_recommendations' => ApparatusDefectRecommendation::query()->where('status', 'pending')->count(),
        ];
    }

    /**
     * @param  list<int>  $stationIds
     * @return array<string, mixed>
     */
    private function requestSummary(array $stationIds): array
    {
        $openRequests = StationRequest::query()->open();
        $criticalOpen = StationRequest::query()->open()
            ->whereIn('priority', ['critical', 'high'])
            ->count();

        $employeeEquipPending = EmployeeEquipmentRequest::query()
            ->where('status', 'Pending')
            ->count();

        return [
            'station_requests' => [
                'open' => (clone $openRequests)->count(),
                'critical_open' => $criticalOpen,
                'repair_service_open' => (clone $openRequests)->where('request_type', 'repair_service')->count(),
                'equipment_open' => (clone $openRequests)->where('request_type', 'equipment')->count(),
            ],
            'employee_equipment' => [
                'pending' => $employeeEquipPending,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function inventoryExceptions(): array
    {
        $activeItems = EquipmentItem::query()->with('location')->where('is_active', true)->get();

        $outOfStock = $activeItems->filter(fn (EquipmentItem $i): bool => $i->stock <= 0);
        $lowStock = $activeItems->filter(
            fn (EquipmentItem $i): bool => $i->stock > 0 && $i->stock <= $i->reorder_min
        );

        $exceptionItems = $activeItems
            ->filter(fn (EquipmentItem $i): bool => $i->stock <= $i->reorder_min)
            ->sortBy('stock')
            ->take(25)
            ->map(fn (EquipmentItem $i): array => [
                'name' => $i->name,
                'stock' => (int) $i->stock,
                'reorder_min' => (int) $i->reorder_min,
                'out_of_stock' => $i->stock <= 0,
                'location' => $i->location?->full_location ?? 'Unknown',
            ])->values()->all();

        return [
            'total_active_items' => $activeItems->count(),
            'out_of_stock' => $outOfStock->count(),
            'low_stock' => $lowStock->count(),
            'items' => $exceptionItems,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function sourceHealth(Carbon $generatedAt): array
    {
        return [
            'hub_up' => true,
            'ai_available' => (bool) config('cloudflare.ai.enabled', true),
            'incidents_worker_up' => Cache::has('pulsepoint_incidents'),
            'last_deploy_sha' => $this->lastDeploySha(),
            'snapshot_age_seconds' => 0,
        ];
    }

    private function lastDeploySha(): ?string
    {
        $sha = env('GIT_SHA') ?? env('APP_REVISION');
        if (is_string($sha) && $sha !== '') {
            return substr($sha, 0, 12);
        }

        $headFile = base_path('.git/HEAD');
        if (is_readable($headFile)) {
            $head = trim((string) @file_get_contents($headFile));
            if (str_starts_with($head, 'ref: ')) {
                $refPath = base_path('.git/'.trim(substr($head, 5)));
                if (is_readable($refPath)) {
                    return substr(trim((string) @file_get_contents($refPath)), 0, 12);
                }
            } elseif ($head !== '') {
                return substr($head, 0, 12);
            }
        }

        return null;
    }

    /**
     * Redacted apparatus list — strips vin, snipeit ids, notes, current_location.
     *
     * @param  Collection<int, Apparatus>  $apparatus
     * @return list<array<string, mixed>>
     */
    private function redactedApparatus(Collection $apparatus): array
    {
        return $apparatus->map(function (Apparatus $a): array {
            $health = $a->getPmHealthStatus();

            return [
                'id' => $a->id,
                'unit_id' => $a->unit_id,
                'name' => $a->designation ?: $a->name ?: $a->unit_id,
                'designation' => $a->designation,
                'type' => $a->type !== null ? strtolower((string) $a->type) : null,
                'status' => $this->classifyStatus($a->status),
                'pm_health' => [
                    'status' => $health['status'] ?? null,
                    'hours_since_pm' => $health['hours_since_pm'] ?? null,
                    'miles_since_pm' => $health['miles_since_pm'] ?? null,
                    'overdue' => $health['overdue'] ?? null,
                    'interval_hours' => $health['interval_hours'] ?? null,
                    'last_pm_date' => $health['last_pm_date'] ?? null,
                ],
                'open_defects_count' => $a->relationLoaded('openDefects')
                    ? $a->openDefects->count()
                    : $a->openDefects()->count(),
            ];
        })->values()->all();
    }

    /**
     * Normalise a drifting apparatus status string into a canonical bucket.
     */
    private function classifyStatus(?string $status): string
    {
        $normalized = strtolower(trim((string) $status));
        $normalized = str_replace([' ', '-'], '_', $normalized);

        return match ($normalized) {
            'in_service', 'active', 'available', 'ready' => self::STATUS_IN_SERVICE,
            'out_of_service', 'oos', 'down' => self::STATUS_OUT_OF_SERVICE,
            'maintenance', 'in_maintenance', 'shop', 'repair' => self::STATUS_MAINTENANCE,
            default => 'unclassified',
        };
    }

    /**
     * @param  Collection<int, Apparatus>  $apparatus
     * @return array{in_service: int, out_of_service: int, maintenance: int}
     */
    private function classifyApparatusCollection(Collection $apparatus): array
    {
        $counts = ['in_service' => 0, 'out_of_service' => 0, 'maintenance' => 0];
        foreach ($apparatus as $a) {
            $bucket = $this->classifyStatus($a->status);
            if (isset($counts[$bucket])) {
                $counts[$bucket]++;
            }
        }

        return $counts;
    }

    private function findActiveStation(int $id): ?Station
    {
        return Station::query()
            ->with('apparatuses')
            ->where('is_active', true)
            ->find($id);
    }

    /**
     * @param  list<int>  $apparatusIds
     */
    private function openDefectsForApparatus(array $apparatusIds): int
    {
        if (empty($apparatusIds)) {
            return 0;
        }

        return ApparatusDefect::query()
            ->whereIn('apparatus_id', $apparatusIds)
            ->where('resolved', false)
            ->count();
    }

    /**
     * @param  list<int>  $apparatusIds
     */
    private function countCriticalDefects(array $apparatusIds): int
    {
        if (empty($apparatusIds)) {
            return 0;
        }

        return ApparatusDefect::query()
            ->whereIn('apparatus_id', $apparatusIds)
            ->where('resolved', false)
            ->where('status', 'Missing')
            ->count();
    }

    /**
     * @param  list<int>  $apparatusIds
     * @return array<int, int>
     */
    private function openDefectCountsByApparatus(array $apparatusIds): array
    {
        if (empty($apparatusIds)) {
            return [];
        }

        return ApparatusDefect::query()
            ->whereIn('apparatus_id', $apparatusIds)
            ->where('resolved', false)
            ->selectRaw('apparatus_id, COUNT(*) as aggregate')
            ->groupBy('apparatus_id')
            ->pluck('aggregate', 'apparatus_id')
            ->map(fn ($v): int => (int) $v)
            ->all();
    }

    /**
     * @param  list<int>  $apparatusIds
     * @return array<int, int>
     */
    private function criticalDefectCountsByApparatus(array $apparatusIds): array
    {
        if (empty($apparatusIds)) {
            return [];
        }

        return ApparatusDefect::query()
            ->whereIn('apparatus_id', $apparatusIds)
            ->where('resolved', false)
            ->where('status', 'Missing')
            ->selectRaw('apparatus_id, COUNT(*) as aggregate')
            ->groupBy('apparatus_id')
            ->pluck('aggregate', 'apparatus_id')
            ->map(fn ($v): int => (int) $v)
            ->all();
    }

    /**
     * @return array{status: ?string, age_days: ?int}
     */
    private function lastStationInspection(int $stationId): array
    {
        return $this->lastStationInspectionByStation([$stationId])[$stationId]
            ?? ['status' => null, 'age_days' => null];
    }

    /**
     * @param  list<int>  $stationIds
     * @return array<int, array{status: ?string, age_days: ?int}>
     */
    private function lastStationInspectionByStation(array $stationIds): array
    {
        if (empty($stationIds)) {
            return [];
        }

        $rows = StationInspection::query()
            ->whereIn('station_id', $stationIds)
            ->where('inspection_date', '>=', Carbon::now()->subDays(30))
            ->orderByDesc('inspection_date')
            ->get(['station_id', 'inspection_date', 'overall_status']);

        $result = [];
        foreach ($rows as $row) {
            // First row per station wins (newest, ordered desc).
            if (isset($result[$row->station_id])) {
                continue;
            }
            $date = $row->inspection_date instanceof Carbon
                ? $row->inspection_date
                : Carbon::parse((string) $row->inspection_date);

            $result[$row->station_id] = [
                'status' => $row->overall_status,
                'age_days' => (int) $date->startOfDay()->diffInDays(Carbon::now()->startOfDay()),
            ];
        }

        return $result;
    }

    /**
     * @param  list<int>  $stationIds
     * @return array<int, array{pending: int, critical: int}>
     */
    private function pendingStationEquipmentCounts(array $stationIds): array
    {
        if (empty($stationIds)) {
            return [];
        }

        $rows = StationRequest::query()
            ->whereIn('station_id', $stationIds)
            ->where('request_type', 'equipment')
            ->open()
            ->get(['station_id', 'priority']);

        $result = [];
        foreach ($stationIds as $sid) {
            $result[$sid] = ['pending' => 0, 'critical' => 0];
        }

        foreach ($rows as $row) {
            $result[$row->station_id]['pending']++;
            if (in_array(strtolower((string) $row->priority), ['critical', 'high'], true)) {
                $result[$row->station_id]['critical']++;
            }
        }

        return $result;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function aggregateReadinessPercent(array $rows): int
    {
        if (empty($rows)) {
            return 0;
        }

        $sum = 0;
        foreach ($rows as $row) {
            $sum += (int) ($row['readiness_percent'] ?? 0);
        }

        return (int) round($sum / count($rows));
    }
}
