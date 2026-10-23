<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ApparatusServiceTicketStatus;
use App\Enums\StationRequestStatus;
use App\Models\ApparatusDefect;
use App\Models\ApparatusInspection;
use App\Models\ApparatusInspectionException;
use App\Models\ApparatusServiceTicket;
use App\Models\Station;
use App\Models\StationRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use WeakMap;

/**
 * Open operational work for one station, as shown on the Admin station profile:
 * station repair/equipment requests, apparatus service tickets, unresolved
 * equipment issues, Daily Checkout exceptions awaiting review, and recent
 * Daily Checkout submissions. One query per record type, memoized per loaded
 * station model.
 */
final class StationOperationsSummaryService
{
    /** Exception states that no longer need a reviewer. */
    public const CLOSED_EXCEPTION_STATUSES = ['resolved', 'dismissed'];

    /**
     * Keyed by the loaded model so every infolist entry for one record shares a
     * single set of queries, and entries are released with the model.
     *
     * @var WeakMap<Station, mixed>|null
     */
    private static ?WeakMap $memo = null;

    /**
     * @return array{open_station_requests: int, open_service_tickets: int, open_equipment_issues: int, open_inspection_exceptions: int, checkouts_last_24h: int, last_checkout_at: ?Carbon}
     */
    public function summaryFor(Station $station): array
    {
        $memo = self::$memo ?? new WeakMap;
        self::$memo = $memo;

        if (isset($memo[$station]) && is_array($memo[$station])) {
            /** @var array{open_station_requests: int, open_service_tickets: int, open_equipment_issues: int, open_inspection_exceptions: int, checkouts_last_24h: int, last_checkout_at: ?Carbon} $cached */
            $cached = $memo[$station];

            return $cached;
        }

        $summary = $this->build($station);
        $memo[$station] = $summary;

        return $summary;
    }

    /**
     * Apparatus ids for the station, reusing an already-loaded apparatus
     * relation (the Admin view page loads it for staffing) so the summary adds
     * no apparatus query of its own.
     *
     * @return list<int>
     */
    private function apparatusIds(Station $station): array
    {
        $apparatuses = $station->relationLoaded('apparatuses')
            ? $station->getRelation('apparatuses')
            : $station->apparatuses()->get(['id']);

        return $apparatuses->map(fn ($apparatus): int => (int) $apparatus->getKey())->values()->all();
    }

    /**
     * @return array{open_station_requests: int, open_service_tickets: int, open_equipment_issues: int, open_inspection_exceptions: int, checkouts_last_24h: int, last_checkout_at: ?Carbon}
     */
    private function build(Station $station): array
    {
        $stationId = (int) $station->getKey();
        $apparatusIds = $this->apparatusIds($station);
        $onStationApparatus = fn (Builder $query): Builder => $query->whereIn('apparatus_id', $apparatusIds);

        $checkouts = $apparatusIds === [] ? null : ApparatusInspection::query()
            ->operational()
            ->tap($onStationApparatus)
            ->toBase()
            ->selectRaw(
                'count(case when coalesce(completed_at, created_at) >= ? then 1 end) as recent, max(coalesce(completed_at, created_at)) as latest',
                [now()->subDay()->toDateTimeString()],
            )
            ->first();

        $latest = $checkouts === null ? null : ($checkouts->latest ?? null);

        return [
            'open_station_requests' => StationRequest::query()
                ->active()
                ->where('station_id', $stationId)
                ->whereIn('status', StationRequestStatus::openValues())
                ->count(),
            'open_service_tickets' => ApparatusServiceTicket::query()
                ->active()
                ->where('station_id', $stationId)
                ->whereIn('status', ApparatusServiceTicketStatus::openValues())
                ->count(),
            'open_equipment_issues' => $apparatusIds === [] ? 0 : ApparatusDefect::query()
                ->tap($onStationApparatus)
                ->where('resolved', false)
                ->count(),
            'open_inspection_exceptions' => $apparatusIds === [] ? 0 : ApparatusInspectionException::query()
                ->tap($onStationApparatus)
                ->whereNotIn('status', self::CLOSED_EXCEPTION_STATUSES)
                ->count(),
            'checkouts_last_24h' => $checkouts === null ? 0 : (int) ($checkouts->recent ?? 0),
            'last_checkout_at' => is_string($latest) && $latest !== '' ? Carbon::parse($latest) : null,
        ];
    }
}
