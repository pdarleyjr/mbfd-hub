<?php

declare(strict_types=1);

namespace App\Services\Ems\SupplyData;

use App\Models\Ems\InventoryRequest;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * The requests submitted in a period (the "cohort") and what was asked for on them.
 *
 * A request is COUNTED unless it was cancelled, sent back or replaced by its correction. Those three states are
 * exactly the ones where the same supplies would otherwise be asked for twice (a revision is a new row and the row it
 * replaces becomes `superseded`), so a chain of corrections contributes one request. Every figure except
 * "submitted" and "rejected" uses counted requests only. Yes/No answers and controlled items are not quantities.
 */
final class RequestCohortQuery
{
    /** @var list<string> */
    public const NOT_COUNTED = [InventoryRequest::CANCELLED, InventoryRequest::RETURNED, InventoryRequest::SUPERSEDED];

    private const UTC_FORMAT = 'Y-m-d H:i:s';

    /** Requests submitted in [from, to), for the unit filter. */
    private function cohort(CarbonImmutable $from, CarbonImmutable $to, SupplyDataFilters $filters): Builder
    {
        $query = DB::table('ems_inventory_requests as r')
            ->where('r.submitted_at', '>=', $from->utc()->format(self::UTC_FORMAT))
            ->where('r.submitted_at', '<', $to->utc()->format(self::UTC_FORMAT));
        if ($filters->unit !== null) {
            UnitKey::restrict($query, $filters->unit, 'r');
        }

        return $query;
    }

    private function counted(): string
    {
        return "r.status NOT IN ('".implode("', '", self::NOT_COUNTED)."')";
    }

    /**
     * Request counts per unit (and the label each request carried when it was submitted).
     *
     * @return Collection<int, stdClass>
     */
    public function byUnit(SupplyDataFilters $filters): Collection
    {
        $counted = $this->counted();
        $active = "'".implode("', '", [InventoryRequest::SUBMITTED, InventoryRequest::REVIEWED, InventoryRequest::PREPARED])."'";

        return $this->cohort($filters->fromUtc(), $filters->toUtc(), $filters)
            ->groupBy('r.apparatus_id', 'r.target_kind', 'r.target_label')
            ->selectRaw("r.apparatus_id AS apparatus_id, r.target_kind AS target_kind, r.target_label AS target_label,
                COUNT(*) AS submitted,
                SUM(CASE WHEN {$counted} THEN 1 ELSE 0 END) AS counted,
                SUM(CASE WHEN {$counted} AND r.reviewed_at IS NOT NULL THEN 1 ELSE 0 END) AS acknowledged,
                SUM(CASE WHEN r.status = 'reconciled' THEN 1 ELSE 0 END) AS reconciled,
                SUM(CASE WHEN r.status IN ({$active}) THEN 1 ELSE 0 END) AS open_count,
                SUM(CASE WHEN r.returned_at IS NOT NULL THEN 1 ELSE 0 END) AS rejected,
                SUM(CASE WHEN r.revision_number > 1 THEN 1 ELSE 0 END) AS corrected,
                MAX(CASE WHEN {$counted} THEN r.submitted_at END) AS last_submitted_at")
            ->get();
    }

    /**
     * Quantity asked for and quantity recorded as issued, on counted requests, per unit.
     *
     * @return Collection<int, stdClass>
     */
    public function quantitiesByUnit(SupplyDataFilters $filters): Collection
    {
        return $this->quantityLines($filters)
            ->groupBy('r.apparatus_id', 'r.target_kind')
            ->selectRaw('r.apparatus_id AS apparatus_id, r.target_kind AS target_kind, COUNT(*) AS lines, SUM(l.requested_qty) AS qty_requested, SUM(CASE WHEN l.issued_qty > 0 THEN l.issued_qty ELSE 0 END) AS qty_distributed')
            ->get();
    }

    /** @return array<int, int> bucket index => counted requests */
    public function byBucket(SupplyDataFilters $filters, array $buckets): array
    {
        [$case, $bindings] = DistributionQuery::bucketCase($buckets, 'r.submitted_at');
        $rows = $this->cohort($filters->fromUtc(), $filters->toUtc(), $filters)
            ->whereRaw($this->counted())
            ->selectRaw("{$case} AS bucket, COUNT(*) AS requests", $bindings)
            ->groupBy('bucket')
            ->get();

        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row->bucket] = (int) $row->requests;
        }

        return $result;
    }

    /**
     * Hours from submission to reconciliation for reconciled requests in the cohort (two small columns per request).
     *
     * @return list<float>
     */
    public function hoursToReconcile(SupplyDataFilters $filters): array
    {
        return $this->cohort($filters->fromUtc(), $filters->toUtc(), $filters)
            ->where('r.status', InventoryRequest::RECONCILED)
            ->whereNotNull('r.reconciled_at')
            ->get(['r.submitted_at', 'r.reconciled_at'])
            ->map(fn (stdClass $row): float => max(0.0, (CarbonImmutable::parse((string) $row->reconciled_at, 'UTC')->getTimestamp() - CarbonImmutable::parse((string) $row->submitted_at, 'UTC')->getTimestamp()) / 3600))
            ->values()->all();
    }

    /**
     * The newest counted requests in the cohort, with line totals.
     *
     * @return Collection<int, stdClass>
     */
    public function recent(SupplyDataFilters $filters, int $limit): Collection
    {
        $requests = $this->cohort($filters->fromUtc(), $filters->toUtc(), $filters)
            ->whereRaw($this->counted())
            ->orderByDesc('r.submitted_at')->orderByDesc('r.id')->limit($limit)
            ->get(['r.id', 'r.number', 'r.uuid', 'r.status', 'r.submitted_at', 'r.reconciled_at']);
        if ($requests->isEmpty()) {
            return $requests;
        }
        $totals = DB::table('ems_inventory_request_lines as l')
            ->leftJoin('ems_catalog_items as ci', 'ci.id', '=', 'l.catalog_item_id')
            ->whereIn('l.request_id', $requests->pluck('id')->all())
            ->where('l.response_type', 'quantity')->where('l.requested_qty', '>', 0)
            ->where(fn ($q) => $q->whereNull('ci.controlled_substance')->orWhere('ci.controlled_substance', false))
            ->groupBy('l.request_id')
            ->selectRaw('l.request_id AS request_id, COUNT(*) AS lines, SUM(l.requested_qty) AS qty_requested')
            ->get()->keyBy('request_id');

        return $requests->map(function (stdClass $request) use ($totals): stdClass {
            $request->lines = (int) ($totals->get($request->id)->lines ?? 0);
            $request->qty_requested = (int) ($totals->get($request->id)->qty_requested ?? 0);

            return $request;
        });
    }

    /**
     * The most requested items on counted requests, per catalog identity and unit of measure. Lines without a
     * catalog identity stay separate per form item; names are never merged.
     *
     * @return Collection<int, stdClass>
     */
    public function topRequested(SupplyDataFilters $filters, int $limit): Collection
    {
        return $this->quantityLines($filters)
            ->groupBy('l.catalog_item_id', 'l.unit', DB::raw('CASE WHEN l.catalog_item_id IS NULL THEN l.bag_key END'), DB::raw('CASE WHEN l.catalog_item_id IS NULL THEN l.item_key END'))
            ->selectRaw('l.catalog_item_id AS catalog_item_id, l.unit AS unit, CASE WHEN l.catalog_item_id IS NULL THEN l.bag_key END AS bag_key, CASE WHEN l.catalog_item_id IS NULL THEN l.item_key END AS item_key,
                MAX(COALESCE(ci.name, l.label)) AS label, SUM(l.requested_qty) AS qty_requested, COUNT(DISTINCT l.request_id) AS requests')
            ->orderByDesc('requests')->orderByDesc('qty_requested')->orderBy('label')
            ->limit($limit)
            ->get();
    }

    /** Quantity lines (never Yes/No answers or controlled items) of counted requests submitted in the period. */
    private function quantityLines(SupplyDataFilters $filters): Builder
    {
        return DB::table('ems_inventory_request_lines as l')
            ->join('ems_inventory_requests as r', 'r.id', '=', 'l.request_id')
            ->leftJoin('ems_catalog_items as ci', 'ci.id', '=', 'l.catalog_item_id')
            ->where('r.submitted_at', '>=', $filters->fromUtc()->format(self::UTC_FORMAT))
            ->where('r.submitted_at', '<', $filters->toUtc()->format(self::UTC_FORMAT))
            ->whereNotIn('r.status', self::NOT_COUNTED)
            ->where('l.response_type', 'quantity')->where('l.requested_qty', '>', 0)
            ->where(fn ($q) => $q->whereNull('ci.controlled_substance')->orWhere('ci.controlled_substance', false))
            ->when($filters->unit !== null, fn (Builder $q) => UnitKey::restrict($q, (string) $filters->unit, 'r'));
    }
}
