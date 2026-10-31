<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\StationRequestStatus;
use App\Http\Resources\Public\PublicStationRequestResource;
use App\Models\Apparatus;
use App\Models\ApparatusInspection;
use App\Models\Station;
use App\Models\StationRequest;
use App\Support\StationShiftWindow;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * What a station page's Overview shows: which units have turned in a vehicle inspection for the current 7-to-7 window,
 * the checkouts themselves, and the station requests still worth looking at. A submission counts as soon as it is
 * received, including one still waiting for review; only a rejected one does not.
 */
final class StationOverviewService
{
    public const MAX_REQUESTS = 50;

    /** A request submitted within this many hours shows on the Overview even once it is closed. */
    private const RECENT_REQUEST_HOURS = 24;

    private const CHECKOUT_COLUMNS = ['id', 'apparatus_id', 'operator_name', 'rank', 'shift', 'vehicle_number', 'designation_at_time', 'review_status', 'processing_status', 'completed_at'];

    public function __construct(private readonly DailyCheckoutComplianceService $compliance) {}

    /** @return array<string, mixed> */
    public function forStation(Station $station, Request $request, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $window = StationShiftWindow::containing($now);
        /** @var Collection<int, Apparatus> $apparatuses */
        $apparatuses = $station->apparatuses()->get(['id', 'station_id', 'designation', 'name', 'unit_id', 'vehicle_number', 'type', 'slug', 'status', 'daily_checkout_requirement'])->keyBy('id');
        $inspections = $this->submitted($apparatuses->keys()->all())
            ->where('completed_at', '>=', $window->start->utc())
            ->where('completed_at', '<', $window->end->utc())
            ->orderByDesc('completed_at')
            ->orderByDesc('id')
            ->get(self::CHECKOUT_COLUMNS);
        $units = $this->units($apparatuses, $inspections);
        $missing = array_values(array_filter($units, static fn (array $unit): bool => $unit['frontline'] && $unit['state'] === 'not_submitted'));
        [$requests, $requestsTotal] = $this->requests($station, $now);

        return [
            'station_id' => (int) $station->id,
            'as_of' => $now->toIso8601String(),
            'shift_window' => [
                'starts_at' => $window->start->toIso8601String(),
                'ends_at' => $window->end->toIso8601String(),
                'alert_from' => $window->alertFrom->toIso8601String(),
            ],
            'units' => $units,
            'checkouts' => $inspections->map(fn (ApparatusInspection $inspection): array => $this->checkoutRow($inspection, $apparatuses->get($inspection->apparatus_id)))->values()->all(),
            'requests' => PublicStationRequestResource::collection($requests)->resolve($request),
            'requests_total' => $requestsTotal,
            'alert' => [
                'active' => $window->alertIsDue($now) && $missing !== [],
                'units' => $window->alertIsDue($now) ? $missing : [],
            ],
        ];
    }

    /**
     * One whole checkout of a unit at this station, for the checkouts listed on its Overview. Null for anything else,
     * so this cannot be used to walk through a station's older records.
     *
     * @return array<string, mixed>|null
     */
    public function checkout(Station $station, int $inspectionId, ?CarbonImmutable $now = null): ?array
    {
        $window = StationShiftWindow::containing($now ?? CarbonImmutable::now());
        $apparatusIds = $station->apparatuses()->pluck('id')->all();
        $inspection = $this->submitted($apparatusIds)
            ->whereKey($inspectionId)
            // The previous window too: a row stays on screen until the page next refreshes.
            ->where('completed_at', '>=', $window->start->subDay()->utc())
            ->with('apparatus:id,designation,name,unit_id,vehicle_number,type')
            ->first();
        if (! $inspection instanceof ApparatusInspection) {
            return null;
        }

        return $this->checkoutRow($inspection, $inspection->apparatus) + [
            'engine_hours' => $inspection->engine_hours,
            'miles' => $inspection->miles,
            'compartments' => $this->compartments($inspection),
        ];
    }

    /**
     * @param  array<int, mixed>  $apparatusIds
     * @return Builder<ApparatusInspection>
     */
    private function submitted(array $apparatusIds): Builder
    {
        return ApparatusInspection::query()
            ->operational()
            ->whereIn('apparatus_id', $apparatusIds)
            // Same integrity rule as the compliance signal: only reconciled submissions count.
            ->whereNotNull('client_submission_id')
            ->whereNotNull('completed_at')
            ->where(fn (Builder $query) => $query->whereNull('review_status')->orWhere('review_status', '!=', 'rejected'))
            ->where(fn (Builder $query) => $query->whereNull('processing_status')->orWhere('processing_status', '!=', 'rejected'));
    }

    /**
     * Every unit that owes a daily inspection, plus any other unit that turned one in this window.
     *
     * @param  Collection<int, Apparatus>  $apparatuses
     * @param  Collection<int, ApparatusInspection>  $inspections  newest first
     * @return list<array<string, mixed>>
     */
    private function units(Collection $apparatuses, Collection $inspections): array
    {
        $latest = $inspections->unique('apparatus_id')->keyBy('apparatus_id');
        $units = [];
        foreach ($apparatuses as $apparatus) {
            $frontline = strtolower(trim((string) $apparatus->getRawOriginal('daily_checkout_requirement'))) === 'required';
            $inspection = $latest->get($apparatus->id);
            if (! $frontline && $inspection === null) {
                continue;
            }
            $outOfService = $this->compliance->isOutOfService((string) $apparatus->getAttribute('status'));
            $units[] = [
                'apparatus_id' => (int) $apparatus->id,
                'designation' => $this->designation($apparatus),
                'vehicle_number' => $apparatus->vehicle_number,
                'type' => $apparatus->type,
                'slug' => $apparatus->slug,
                'frontline' => $frontline,
                'state' => match (true) {
                    $inspection !== null => 'submitted',
                    $outOfService => 'out_of_service',
                    default => 'not_submitted',
                },
                'inspection_id' => $inspection?->id,
                'submitted_at' => $this->iso($inspection?->completed_at),
            ];
        }
        usort($units, static fn (array $a, array $b): int => strnatcasecmp((string) $a['designation'], (string) $b['designation']));

        return $units;
    }

    /** @return array<string, mixed> */
    private function checkoutRow(ApparatusInspection $inspection, ?Apparatus $apparatus): array
    {
        return [
            'id' => (int) $inspection->id,
            'apparatus_id' => (int) $inspection->apparatus_id,
            'designation' => $inspection->designation_at_time ?: ($apparatus !== null ? $this->designation($apparatus) : 'Unit'),
            'vehicle_number' => $inspection->vehicle_number ?: $apparatus?->vehicle_number,
            'type' => $apparatus?->type,
            'submitted_by' => trim($inspection->rank.' '.$inspection->operator_name) ?: null,
            'shift' => $inspection->shift,
            'submitted_at' => $this->iso($inspection->completed_at),
            'status' => $inspection->displayStatus(),
            'needs_attention' => ! in_array($inspection->processing_status, [null, 'accepted'], true),
        ];
    }

    /** @return list<array{name: string, items: list<array<string, mixed>>}> */
    private function compartments(ApparatusInspection $inspection): array
    {
        $compartments = [];
        foreach ((array) ($inspection->results ?? []) as $compartment) {
            if (! is_array($compartment)) {
                continue;
            }
            $items = [];
            foreach ((array) ($compartment['items'] ?? []) as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $items[] = [
                    'name' => (string) ($item['name'] ?? ''),
                    'status' => (string) ($item['status'] ?? ''),
                    'value' => is_scalar($item['value'] ?? null) ? (string) $item['value'] : null,
                    'notes' => isset($item['notes']) && is_string($item['notes']) ? mb_substr($item['notes'], 0, 2000) : null,
                    // Photos stay with Fleet and the member's own history; the storage path is never sent.
                    'has_photo' => filled($item['photo_path'] ?? null),
                ];
            }
            if ($items !== []) {
                $compartments[] = ['name' => (string) ($compartment['name'] ?? $compartment['title'] ?? ''), 'items' => $items];
            }
        }

        return $compartments;
    }

    /** @return array{0: Collection<int, StationRequest>, 1: int} */
    private function requests(Station $station, CarbonImmutable $now): array
    {
        $since = $now->subHours(self::RECENT_REQUEST_HOURS);
        $query = $station->stationRequests()
            ->where(fn ($outer) => $outer
                ->where(fn ($open) => $open->open())
                ->orWhere(fn ($recent) => $recent->active()->where('created_at', '>=', $since->utc())));
        $total = (clone $query)->count();
        $openStatuses = StationRequestStatus::openValues();
        $requests = $query
            ->with([
                'room:id,station_id,name',
                'items:id,station_request_id,room_asset_id,item_name,category,quantity,reason,requested_action,condition',
                'updates' => fn ($updates) => $updates->select('id', 'station_request_id', 'status', 'public_note', 'created_at')
                    ->where(fn ($nested) => $nested->whereNotNull('public_note')->orWhereNotNull('status')),
            ])
            // Open requests first, so the cap can only ever drop closed ones before an open one.
            ->orderByRaw('CASE WHEN status IN ('.implode(',', array_fill(0, count($openStatuses), '?')).') THEN 0 ELSE 1 END', $openStatuses)
            ->latest('created_at')
            ->orderByDesc('id')
            ->limit(self::MAX_REQUESTS)
            ->get();

        return [$requests, $total];
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
