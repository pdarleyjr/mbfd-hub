<?php

declare(strict_types=1);

namespace App\Services\Command300;

use App\Enums\StationRequestStatus;
use App\Models\Apparatus;
use App\Models\ApparatusDefect;
use App\Models\ApparatusServiceTicket;
use App\Models\Station;
use App\Models\StationRequest;
use App\Models\StationRequestUpdate;
use App\Services\DailyRoster\StationRosterPresenter;
use App\Services\StationOverviewService;
use App\Support\StationShiftWindow;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Collection;

/**
 * What the 300 (Division Chief) sees of every station: a small, read-only, allowlisted picture of who is on shift,
 * which trucks have been checked out, which station requests are waiting and which vehicles have open issues.
 *
 * board() reads everything for all stations in a fixed number of queries; station() and checkout() are the drill-ins.
 * Nothing here writes, and no request owner, requester contact, photo path, VIN or note column is ever selected.
 */
final class Command300StationBoard
{
    /** Short list per station card. */
    private const CARD_REQUESTS = 3;

    private const CARD_ISSUES = 3;

    /** Hard caps so a runaway backlog cannot make the page heavy. */
    private const MAX_OPEN_REQUESTS = 300;

    private const MAX_DEFECTS = 500;

    private const MAX_TICKETS = 200;

    private const MAX_DETAIL_REQUESTS = 50;

    private const RECENT_UPDATE_HOURS = 24;

    public function __construct(
        private readonly StationOverviewService $overview,
        private readonly StationRosterPresenter $rosters,
    ) {}

    /** @return array<string, mixed> */
    public function board(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $window = StationShiftWindow::containing($now);
        $stations = Station::query()
            ->where('is_active', true)
            ->with(['apparatuses' => fn ($query) => $query->select('id', 'station_id', 'designation', 'name', 'unit_id', 'vehicle_number', 'type', 'slug', 'status', 'daily_checkout_requirement')])
            ->orderBy('station_number')
            ->orderBy('id')
            ->get(['id', 'station_number', 'name', 'is_active']);
        $stationIds = $stations->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $apparatuses = $stations->flatMap(fn (Station $station): Collection => $this->apparatusesOf($station))->keyBy('id');
        $apparatusIds = $apparatuses->keys()->map(fn ($id): int => (int) $id)->all();

        $inspections = $apparatusIds === [] ? collect() : $this->overview->submittedInWindow($apparatusIds, $window);
        $issues = $this->issuesByApparatus($apparatusIds, $stationIds);
        $requests = $this->requestsByStation($stationIds, $window, $now);
        $rosters = $this->rosters->forStations($stations);

        $cards = [];
        $totals = ['done' => 0, 'expected' => 0, 'open' => 0, 'pending' => 0, 'new_today' => 0, 'vehicles' => 0, 'oos' => 0, 'attention' => 0, 'working' => 0];
        foreach ($stations as $station) {
            $apparatusRows = $this->apparatusesOf($station)->keyBy('id');
            $stationInspections = $inspections->filter(fn ($inspection): bool => $apparatusRows->has($inspection->apparatus_id))->values();
            $units = $this->overview->units($apparatusRows, $stationInspections);
            $names = $stationInspections->mapWithKeys(fn ($inspection): array => [(int) $inspection->id => trim($inspection->rank.' '.$inspection->operator_name) ?: null])->all();
            $card = $this->card($station, $units, $names, $issues, $requests[(int) $station->id] ?? $this->emptyRequests(), $rosters[(int) $station->id], $now, $window);
            $cards[] = $card;
            $totals['done'] += $card['checkouts']['done'];
            $totals['expected'] += $card['checkouts']['expected'];
            $totals['open'] += $card['requests']['open'];
            $totals['pending'] += $card['requests']['pending'];
            $totals['new_today'] += $card['requests']['new_today'];
            $totals['vehicles'] += $card['vehicle_issues']['units_with_issues'];
            $totals['oos'] += $card['checkouts']['out_of_service'];
            $totals['working'] += $card['crew']['working_count'];
            $totals['attention'] += $card['attention']['level'] === 'attention' ? 1 : 0;
        }

        $firstRoster = $rosters === [] ? ['available' => false, 'message' => null] : reset($rosters);

        return [
            'generated_at' => $now->toIso8601String(),
            'shift_window' => ['starts_at' => $window->start->toIso8601String(), 'ends_at' => $window->end->toIso8601String()],
            'roster' => [
                'available' => (bool) $firstRoster['available'],
                'message' => $firstRoster['message'] ?? null,
                'shift' => $firstRoster['shift'] ?? null,
                'date_label' => $firstRoster['date_label'] ?? null,
            ],
            'summary' => [
                'stations' => count($cards),
                'checkouts_done' => $totals['done'],
                'checkouts_expected' => $totals['expected'],
                'requests_open' => $totals['open'],
                'requests_pending' => $totals['pending'],
                'requests_new_today' => $totals['new_today'],
                'vehicles_with_issues' => $totals['vehicles'],
                'units_out_of_service' => $totals['oos'],
                'crew_working' => $totals['working'],
                'stations_needing_attention' => $totals['attention'],
            ],
            'stations' => $cards,
            'total' => count($cards),
        ];
    }

    /**
     * Everything for one station, for the read-only drill-in.
     *
     * @return array<string, mixed>
     */
    public function station(Station $station, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $window = StationShiftWindow::containing($now);
        $station->load(['apparatuses' => fn ($query) => $query->select('id', 'station_id', 'designation', 'name', 'unit_id', 'vehicle_number', 'type', 'slug', 'status', 'daily_checkout_requirement')]);
        $apparatuses = $this->apparatusesOf($station)->keyBy('id');
        $apparatusIds = $apparatuses->keys()->map(fn ($id): int => (int) $id)->all();
        $inspections = $apparatusIds === [] ? collect() : $this->overview->submittedInWindow($apparatusIds, $window);
        $units = $this->overview->units($apparatuses, $inspections);
        $issues = $this->issuesByApparatus($apparatusIds, [(int) $station->id], detail: true);
        $requests = $this->detailRequests($station, $now);
        $roster = $this->rosters->forStation($station);

        $inspectionsById = $inspections->keyBy('id');
        $unitRows = array_map(function (array $unit) use ($issues, $inspectionsById, $apparatuses): array {
            $inspection = $unit['inspection_id'] === null ? null : $inspectionsById->get($unit['inspection_id']);
            $apparatus = $apparatuses->get($unit['apparatus_id']);

            return $this->unitRow($unit, $issues) + [
                'checkout' => $inspection === null ? null : $this->overview->checkoutRow($inspection, $apparatus instanceof Apparatus ? $apparatus : null),
            ];
        }, $units);

        return [
            'station' => ['id' => (int) $station->id, 'station_number' => $station->station_number, 'name' => $station->getRawOriginal('name') ?: $station->name],
            'as_of' => $now->toIso8601String(),
            'shift_window' => ['starts_at' => $window->start->toIso8601String(), 'ends_at' => $window->end->toIso8601String()],
            'crew' => $this->crewDetail($roster),
            'units' => $unitRows,
            'checkouts' => $inspections->map(fn ($inspection): array => $this->overview->checkoutRow($inspection, $apparatuses->get($inspection->apparatus_id)))->values()->all(),
            'requests' => $requests['items'],
            'requests_total' => $requests['total'],
            'vehicle_issues' => $this->issueGroups($apparatuses, $issues),
        ];
    }

    /**
     * One submitted checkout with the vehicle's own open issues alongside, for the checkout drill-in.
     *
     * @return array<string, mixed>|null
     */
    public function checkout(Station $station, int $inspectionId, ?CarbonImmutable $now = null): ?array
    {
        $checkout = $this->overview->checkout($station, $inspectionId, $now);
        if ($checkout === null) {
            return null;
        }
        $apparatusId = (int) $checkout['apparatus_id'];
        $issues = $this->issuesByApparatus([$apparatusId], [(int) $station->id], detail: true, inspectionId: $inspectionId);

        return $checkout + ['issues' => $issues['byApparatus'][$apparatusId] ?? ['defects' => [], 'tickets' => [], 'defect_count' => 0, 'ticket_count' => 0]];
    }

    /**
     * @param  list<array<string, mixed>>  $units
     * @param  array<int, string|null>  $names  submitter by inspection id
     * @param  array<string, mixed>  $issues
     * @param  array<string, mixed>  $requests
     * @param  array<string, mixed>  $roster
     * @return array<string, mixed>
     */
    private function card(Station $station, array $units, array $names, array $issues, array $requests, array $roster, CarbonImmutable $now, StationShiftWindow $window): array
    {
        $rows = array_map(fn (array $unit): array => $this->unitRow($unit, $issues, $names), $units);
        $owed = array_values(array_filter($rows, static fn (array $row): bool => $row['state'] !== 'out_of_service'));
        $done = count(array_filter($owed, static fn (array $row): bool => $row['state'] === 'submitted'));
        $missing = array_values(array_filter($owed, static fn (array $row): bool => $row['state'] === 'not_submitted' && $row['frontline']));
        $outOfService = count(array_filter($rows, static fn (array $row): bool => $row['state'] === 'out_of_service'));

        $stationIssues = $issues['byStation'][(int) $station->id] ?? ['defects' => 0, 'tickets' => 0];
        $unitsWithIssues = [];
        foreach ($this->apparatusesOf($station) as $apparatus) {
            $own = $issues['byApparatus'][(int) $apparatus->id] ?? null;
            if ($own !== null && ($own['defect_count'] + $own['ticket_count']) > 0) {
                $unitsWithIssues[] = [
                    'apparatus_id' => (int) $apparatus->id,
                    'designation' => $this->designation($apparatus),
                    'defect_count' => $own['defect_count'],
                    'ticket_count' => $own['ticket_count'],
                    'top' => array_slice(array_merge(
                        array_map(fn (array $defect): array => ['kind' => 'defect', 'label' => $defect['item'], 'significant' => $defect['severity'] === 'significant'], $own['defects']),
                        array_map(fn (array $ticket): array => ['kind' => 'ticket', 'label' => $ticket['title'], 'significant' => false], $own['tickets']),
                    ), 0, self::CARD_ISSUES),
                ];
            }
        }
        usort($unitsWithIssues, static fn (array $a, array $b): int => ($b['defect_count'] + $b['ticket_count']) <=> ($a['defect_count'] + $a['ticket_count']) ?: strnatcasecmp($a['designation'], $b['designation']));

        $crew = $this->crewSummary($roster);
        $attention = $this->attention($missing, $outOfService, $unitsWithIssues, $requests, $crew, $window->alertIsDue($now));

        return [
            'id' => (int) $station->id,
            'station_number' => $station->station_number,
            'name' => $station->getRawOriginal('name') ?: $station->name,
            'attention' => $attention,
            'crew' => $crew,
            'checkouts' => [
                'expected' => count($owed),
                'done' => $done,
                'missing' => count($missing),
                'out_of_service' => $outOfService,
                'units' => $rows,
            ],
            'requests' => [
                'open' => $requests['open'],
                'pending' => $requests['pending'],
                'new_today' => $requests['new_today'],
                'updated_recently' => $requests['updated_recently'],
                'items' => array_slice($requests['items'], 0, self::CARD_REQUESTS),
            ],
            'vehicle_issues' => [
                'units_with_issues' => count($unitsWithIssues),
                'defects' => $stationIssues['defects'],
                'tickets' => $stationIssues['tickets'],
                'units' => array_slice($unitsWithIssues, 0, self::CARD_ISSUES),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $unit
     * @param  array<string, mixed>  $issues
     * @param  array<int, string|null>  $names
     * @return array<string, mixed>
     */
    private function unitRow(array $unit, array $issues, array $names = []): array
    {
        $own = $issues['byApparatus'][$unit['apparatus_id']] ?? null;

        return [
            'apparatus_id' => $unit['apparatus_id'],
            'designation' => $unit['designation'],
            'vehicle_number' => $unit['vehicle_number'],
            'type' => $unit['type'],
            'frontline' => $unit['frontline'],
            'state' => $unit['state'],
            'inspection_id' => $unit['inspection_id'],
            'submitted_at' => $unit['submitted_at'],
            'submitted_by' => $unit['inspection_id'] === null ? null : ($names[$unit['inspection_id']] ?? null),
            'open_issues' => $own === null ? 0 : $own['defect_count'] + $own['ticket_count'],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $missing
     * @param  list<array<string, mixed>>  $unitsWithIssues
     * @param  array<string, mixed>  $requests
     * @param  array<string, mixed>  $crew
     * @return array{level: string, reasons: list<string>}
     */
    private function attention(array $missing, int $outOfService, array $unitsWithIssues, array $requests, array $crew, bool $afterNoon): array
    {
        $level = 'ok';
        $reasons = [];
        $raise = static function (string $to) use (&$level): void {
            $rank = ['ok' => 0, 'watch' => 1, 'attention' => 2];
            if ($rank[$to] > $rank[$level]) {
                $level = $to;
            }
        };
        if ($missing !== []) {
            $reasons[] = count($missing) === 1 ? '1 truck not checked out' : count($missing).' trucks not checked out';
            $raise($afterNoon ? 'attention' : 'watch');
        }
        if ($outOfService > 0) {
            $reasons[] = $outOfService === 1 ? '1 unit out of service' : $outOfService.' units out of service';
            $raise('watch');
        }
        $significant = array_filter($unitsWithIssues, static fn (array $unit): bool => array_filter($unit['top'], static fn (array $item): bool => $item['significant']) !== []);
        if ($significant !== []) {
            $reasons[] = 'Significant damage reported';
            $raise('attention');
        }
        if ($unitsWithIssues !== []) {
            $reasons[] = count($unitsWithIssues) === 1 ? '1 vehicle with open issues' : count($unitsWithIssues).' vehicles with open issues';
            $raise('watch');
        }
        if ($requests['pending'] > 0) {
            $reasons[] = $requests['pending'] === 1 ? '1 request not yet acknowledged' : $requests['pending'].' requests not yet acknowledged';
            $raise('watch');
        }
        if (! $crew['available']) {
            $reasons[] = 'Roster not available yet';
        }

        return ['level' => $level, 'reasons' => $reasons];
    }

    /**
     * The crew on shift as a card needs it: who, in which unit, and whether they are actually working.
     *
     * @param  array<string, mixed>  $roster
     * @return array<string, mixed>
     */
    private function crewSummary(array $roster): array
    {
        if (! $roster['available']) {
            return ['available' => false, 'message' => $roster['message'] ?? null, 'working_count' => 0, 'away_count' => 0, 'members' => []];
        }
        $members = [];
        foreach ($roster['sections'] as $section) {
            foreach ($section['seats'] as $seat) {
                foreach ($seat as $row) {
                    if ($row['is_vacant']) {
                        continue;
                    }
                    $members[] = [
                        'name' => $row['name'],
                        'rank' => $row['rank'],
                        'unit' => $section['unit'],
                        'presence' => $this->presence($row['status']),
                        'status_label' => $row['status']['label'],
                        'status_tone' => $row['status']['tone'],
                    ];
                }
            }
        }

        return [
            'available' => true,
            'message' => null,
            'shift' => $roster['shift'] ?? null,
            'working_count' => count(array_filter($members, static fn (array $member): bool => $member['presence'] === 'working')),
            'away_count' => count(array_filter($members, static fn (array $member): bool => $member['presence'] === 'away')),
            'members' => $members,
        ];
    }

    /**
     * @param  array<string, mixed>  $roster
     * @return array<string, mixed>
     */
    private function crewDetail(array $roster): array
    {
        $crew = $this->crewSummary($roster);
        if (! $roster['available']) {
            return $crew + ['units' => [], 'date_label' => null, 'window' => null];
        }
        $units = [];
        foreach ($roster['sections'] as $section) {
            $rows = [];
            foreach ($section['seats'] as $seat) {
                foreach ($seat as $row) {
                    $rows[] = [
                        'name' => $row['name'],
                        'rank' => $row['rank'],
                        'is_vacant' => $row['is_vacant'],
                        'presence' => $row['is_vacant'] ? 'vacant' : $this->presence($row['status']),
                        'status_label' => $row['status']['label'],
                        'status_tone' => $row['status']['tone'],
                        'from' => $row['from'],
                        'through' => $row['through'],
                    ];
                }
            }
            $units[] = ['unit' => $section['unit'], 'members' => $rows];
        }

        return $crew + ['units' => $units, 'date_label' => $roster['date_label'] ?? null, 'window' => $roster['window'] ?? null, 'notice' => $roster['notice'] ?? null];
    }

    /** @param  array<string, mixed>  $status */
    private function presence(array $status): string
    {
        $spoken = strtolower((string) ($status['screen_reader_text'] ?? ''));

        return match (true) {
            str_starts_with($spoken, 'not working') => 'away',
            str_starts_with($spoken, 'working') => 'working',
            default => 'unknown',
        };
    }

    /** @return array<string, mixed> */
    private function emptyRequests(): array
    {
        return ['open' => 0, 'pending' => 0, 'new_today' => 0, 'updated_recently' => 0, 'items' => []];
    }

    /**
     * Open-request counts and the newest few per station: three queries however many stations.
     *
     * @param  list<int>  $stationIds
     * @return array<int, array<string, mixed>>
     */
    private function requestsByStation(array $stationIds, StationShiftWindow $window, CarbonImmutable $now): array
    {
        if ($stationIds === []) {
            return [];
        }
        $counts = StationRequest::query()
            ->open()
            ->whereIn('station_id', $stationIds)
            ->selectRaw('station_id, count(*) as open_total, sum(case when status = ? then 1 else 0 end) as pending_total, sum(case when created_at >= ? then 1 else 0 end) as new_total', [StationRequestStatus::Pending->value, $window->start->utc()->format('Y-m-d H:i:s')])
            ->groupBy('station_id')
            ->get()
            ->keyBy('station_id');
        $rows = StationRequest::query()
            ->open()
            ->whereIn('station_id', $stationIds)
            ->latest('created_at')
            ->orderByDesc('id')
            ->limit(self::MAX_OPEN_REQUESTS)
            ->get(['id', 'station_id', 'request_number', 'title', 'status', 'priority', 'created_at']);
        $updated = $this->lastUpdates($rows->pluck('id')->all(), $now);

        $result = [];
        foreach ($stationIds as $stationId) {
            $count = $counts->get($stationId);
            $items = $rows->where('station_id', $stationId)->map(fn (StationRequest $request): array => $this->requestItem($request, $updated[(int) $request->id] ?? null))->values()->all();
            $result[$stationId] = [
                'open' => (int) ($count->open_total ?? 0),
                'pending' => (int) ($count->pending_total ?? 0),
                'new_today' => (int) ($count->new_total ?? 0),
                'updated_recently' => count(array_filter($items, static fn (array $item): bool => $item['last_update_at'] !== null)),
                'items' => $items,
            ];
        }

        return $result;
    }

    /**
     * When each request last got a real (non automatic) update within the recent window.
     *
     * @param  array<int, mixed>  $requestIds
     * @return array<int, string>
     */
    private function lastUpdates(array $requestIds, CarbonImmutable $now): array
    {
        if ($requestIds === []) {
            return [];
        }
        $latest = StationRequestUpdate::query()
            ->whereIn('station_request_id', $requestIds)
            ->whereNull('automation_intake_id')
            ->where('created_at', '>=', $now->subHours(self::RECENT_UPDATE_HOURS)->utc())
            ->selectRaw('station_request_id, max(created_at) as last_at')
            ->groupBy('station_request_id')
            ->pluck('last_at', 'station_request_id');

        $result = [];
        foreach ($latest as $id => $at) {
            $result[(int) $id] = CarbonImmutable::parse((string) $at, 'UTC')->toIso8601String();
        }

        return $result;
    }

    /** @return array<string, mixed> */
    private function requestItem(StationRequest $request, ?string $lastUpdateAt): array
    {
        return [
            'id' => (int) $request->id,
            'request_number' => $request->request_number,
            'title' => $request->title,
            'status' => $request->status,
            'priority' => $request->priority,
            'created_at' => $this->iso($request->created_at),
            'last_update_at' => $lastUpdateAt,
        ];
    }

    /**
     * Open requests plus any from the last 24 hours, each with its update history, for the station drill-in.
     *
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    private function detailRequests(Station $station, CarbonImmutable $now): array
    {
        $since = $now->subHours(24);
        $query = $station->stationRequests()
            ->where(fn ($outer) => $outer
                ->where(fn ($open) => $open->open())
                ->orWhere(fn ($recent) => $recent->active()->where('created_at', '>=', $since->utc())));
        $total = (clone $query)->count();
        $openStatuses = StationRequestStatus::openValues();
        $requests = $query
            ->with([
                'room:id,station_id,name',
                'items:id,station_request_id,item_name,quantity,reason',
                'updates' => fn ($updates) => $updates->select('id', 'station_request_id', 'status', 'public_note', 'created_at', 'automation_intake_id')
                    ->where(fn ($nested) => $nested->whereNotNull('public_note')->orWhereNotNull('status')),
            ])
            ->orderByRaw('CASE WHEN status IN ('.implode(',', array_fill(0, count($openStatuses), '?')).') THEN 0 ELSE 1 END', $openStatuses)
            ->latest('created_at')
            ->orderByDesc('id')
            ->limit(self::MAX_DETAIL_REQUESTS)
            ->get();

        return [
            'total' => $total,
            'items' => $requests->map(fn (StationRequest $request): array => [
                'id' => (int) $request->id,
                'request_number' => $request->request_number,
                'title' => $request->title,
                'description' => $request->description,
                'status' => $request->status,
                'priority' => $request->priority,
                'request_type' => $request->request_type,
                'is_open' => $request->is_open,
                'room_name' => $request->room?->name ?: $request->room_name_snapshot,
                'created_at' => $this->iso($request->created_at),
                'updated_at' => $this->iso($request->updated_at),
                'current_public_response' => $request->current_public_response,
                'items' => $request->items->map(fn ($item): array => ['id' => (int) $item->id, 'item_name' => $item->item_name, 'quantity' => $item->quantity, 'reason' => $item->reason])->all(),
                'updates' => $request->updates->map(fn (StationRequestUpdate $update): array => [
                    'id' => (int) $update->id,
                    'status' => $update->status,
                    'public_note' => $update->public_note,
                    'created_at' => $this->iso($update->created_at),
                    'sender' => $update->senderLabel(),
                ])->values()->all(),
            ])->values()->all(),
        ];
    }

    /**
     * Open defects and tickets per apparatus (and counts per station), in a fixed number of queries.
     *
     * @param  list<int>  $apparatusIds
     * @param  list<int>  $stationIds
     * @return array{byApparatus: array<int, array<string, mixed>>, byStation: array<int, array{defects: int, tickets: int}>}
     */
    private function issuesByApparatus(array $apparatusIds, array $stationIds, bool $detail = false, ?int $inspectionId = null): array
    {
        $byApparatus = [];
        $byStation = [];
        if ($apparatusIds === []) {
            return ['byApparatus' => $byApparatus, 'byStation' => $byStation];
        }
        $blank = static fn (): array => ['defects' => [], 'tickets' => [], 'defect_count' => 0, 'ticket_count' => 0];

        $defectCounts = ApparatusDefect::query()->unresolved()->whereIn('apparatus_id', $apparatusIds)
            ->selectRaw('apparatus_id, count(*) as aggregate')->groupBy('apparatus_id')->pluck('aggregate', 'apparatus_id');
        $defects = ApparatusDefect::query()->unresolved()->whereIn('apparatus_id', $apparatusIds)
            ->orderByRaw("case when severity = 'significant' then 0 else 1 end")
            ->orderByDesc('id')
            ->limit(self::MAX_DEFECTS)
            ->get(['id', 'apparatus_id', 'apparatus_inspection_id', 'compartment', 'item', 'issue_type', 'severity', 'action_status', 'reported_date', 'notes']);
        $ticketCounts = ApparatusServiceTicket::query()->open()->whereIn('apparatus_id', $apparatusIds)
            ->selectRaw('apparatus_id, count(*) as aggregate')->groupBy('apparatus_id')->pluck('aggregate', 'apparatus_id');
        $tickets = ApparatusServiceTicket::query()->open()->whereIn('apparatus_id', $apparatusIds)
            ->latest('id')
            ->limit(self::MAX_TICKETS)
            ->get(['id', 'apparatus_id', 'ticket_number', 'title', 'status', 'priority', 'created_at']);
        $stationTickets = ApparatusServiceTicket::query()->open()->whereIn('station_id', $stationIds)
            ->selectRaw('station_id, count(*) as aggregate')->groupBy('station_id')->pluck('aggregate', 'station_id');
        $apparatusStations = Apparatus::query()->whereIn('id', $apparatusIds)->pluck('station_id', 'id');

        foreach ($defectCounts as $apparatusId => $count) {
            $byApparatus[(int) $apparatusId] ??= $blank();
            $byApparatus[(int) $apparatusId]['defect_count'] = (int) $count;
            $stationId = (int) ($apparatusStations[$apparatusId] ?? 0);
            $byStation[$stationId]['defects'] = ($byStation[$stationId]['defects'] ?? 0) + (int) $count;
        }
        foreach ($ticketCounts as $apparatusId => $count) {
            $byApparatus[(int) $apparatusId] ??= $blank();
            $byApparatus[(int) $apparatusId]['ticket_count'] = (int) $count;
        }
        foreach ($stationIds as $stationId) {
            $byStation[$stationId] = ['defects' => $byStation[$stationId]['defects'] ?? 0, 'tickets' => (int) ($stationTickets[$stationId] ?? 0)];
        }
        $perUnit = $detail ? 25 : self::CARD_ISSUES;
        foreach ($defects as $defect) {
            $key = (int) $defect->apparatus_id;
            $byApparatus[$key] ??= $blank();
            if (count($byApparatus[$key]['defects']) < $perUnit) {
                $byApparatus[$key]['defects'][] = [
                    'id' => (int) $defect->id,
                    'compartment' => $defect->compartment,
                    'item' => $defect->item,
                    'issue_type' => $defect->issue_type,
                    'severity' => $defect->severity === 'significant' ? 'significant' : 'routine',
                    'reported_date' => $defect->reported_date?->toDateString(),
                    'notes' => $detail && $defect->notes !== null ? mb_substr((string) $defect->notes, 0, 500) : null,
                    'from_this_checkout' => $inspectionId !== null && (int) $defect->apparatus_inspection_id === $inspectionId,
                ];
            }
        }
        foreach ($tickets as $ticket) {
            $key = (int) $ticket->apparatus_id;
            $byApparatus[$key] ??= $blank();
            if (count($byApparatus[$key]['tickets']) < $perUnit) {
                $byApparatus[$key]['tickets'][] = [
                    'id' => (int) $ticket->id,
                    'ticket_number' => $ticket->ticket_number,
                    'title' => $ticket->title,
                    'status' => $ticket->status,
                    'priority' => $ticket->priority,
                    'created_at' => $this->iso($ticket->created_at),
                ];
            }
        }

        return ['byApparatus' => $byApparatus, 'byStation' => $byStation];
    }

    /**
     * @param  Collection<int, Apparatus>  $apparatuses
     * @param  array<string, mixed>  $issues
     * @return list<array<string, mixed>>
     */
    private function issueGroups(Collection $apparatuses, array $issues): array
    {
        $groups = [];
        foreach ($apparatuses as $apparatus) {
            $own = $issues['byApparatus'][(int) $apparatus->id] ?? null;
            if ($own === null || ($own['defect_count'] + $own['ticket_count']) === 0) {
                continue;
            }
            $groups[] = [
                'apparatus_id' => (int) $apparatus->id,
                'designation' => $this->designation($apparatus),
                'vehicle_number' => $apparatus->vehicle_number,
                'type' => $apparatus->type,
            ] + $own;
        }
        usort($groups, static fn (array $a, array $b): int => strnatcasecmp((string) $a['designation'], (string) $b['designation']));

        return $groups;
    }

    /** @return Collection<int, Apparatus> */
    private function apparatusesOf(Station $station): Collection
    {
        $apparatuses = [];
        foreach ($station->apparatuses as $apparatus) {
            if ($apparatus instanceof Apparatus) {
                $apparatuses[] = $apparatus;
            }
        }

        return collect($apparatuses);
    }

    private function designation(Apparatus $apparatus): string
    {
        return (string) ($apparatus->designation ?: $apparatus->name ?: $apparatus->getAttribute('unit_id') ?: 'Unit');
    }

    private function iso(mixed $value): ?string
    {
        return $value instanceof DateTimeInterface ? CarbonImmutable::instance($value)->toIso8601String() : null;
    }
}
