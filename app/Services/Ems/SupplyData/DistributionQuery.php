<?php

declare(strict_types=1);

namespace App\Services\Ems\SupplyData;

use App\Models\Ems\StockMovement;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * What left the supply room for a unit, netted per (request, supply item) and read with GROUP BY in the database.
 *
 * A "distribution event" is one request-and-item pair whose request-linked movements add up to units taken off the
 * shelf. The movements are the issuances (`issued`, negative) plus the corrections written against the same request
 * (`adjusted` with a request id; `corrects_movement_id` points at the row they offset). A reversed issuance therefore
 * nets to zero and is not an event. A correction is dated by the issuance it corrects, so reversing last week's
 * issuance this week does not make this week negative. Manual adjustments and receipts have no request and are
 * never part of "distributed".
 */
final class DistributionQuery
{
    private const EFFECTIVE_AT = "CASE WHEN m.movement_type = 'adjusted' THEN COALESCE(c.occurred_at, m.occurred_at) ELSE m.occurred_at END";

    /** The (request, item) pairs with a positive net distribution whose effective time is in [from, to). */
    public function pairs(CarbonImmutable $from, CarbonImmutable $to, SupplyDataFilters $filters): Builder
    {
        $query = DB::table('ems_stock_movements as m')
            ->join('ems_inventory_requests as r', 'r.id', '=', 'm.request_id')
            ->join('ems_supply_items as i', 'i.id', '=', 'm.supply_item_id')
            ->leftJoin('ems_stock_movements as c', 'c.id', '=', 'm.corrects_movement_id')
            ->where('i.controlled_substance', false)
            ->whereIn('m.movement_type', [StockMovement::ISSUED, StockMovement::ADJUSTED])
            ->whereRaw(self::EFFECTIVE_AT.' >= '.Instant::placeholder(), [Instant::bind($from)])
            ->whereRaw(self::EFFECTIVE_AT.' < '.Instant::placeholder(), [Instant::bind($to)])
            ->groupBy('m.request_id', 'm.supply_item_id', 'r.apparatus_id', 'r.target_kind', 'r.target_label')
            ->havingRaw('SUM(m.quantity_base) < 0')
            ->selectRaw('m.request_id AS request_id, m.supply_item_id AS supply_item_id, r.apparatus_id AS apparatus_id, r.target_kind AS target_kind, r.target_label AS target_label, -SUM(m.quantity_base) AS net_base, MIN('.self::EFFECTIVE_AT.') AS eff_at');

        if ($filters->item !== null) {
            $query->where('m.supply_item_id', $filters->item);
        }
        if ($filters->unit !== null) {
            UnitKey::restrict($query, $filters->unit, 'r');
        }
        if ($filters->category !== null) {
            $query->join('ems_catalog_items as ci', 'ci.id', '=', 'i.catalog_item_id')->where('ci.category', $filters->category);
        }

        return $query;
    }

    /**
     * One row per supply item with events.
     *
     * @return Collection<int, stdClass>
     */
    public function byItem(CarbonImmutable $from, CarbonImmutable $to, SupplyDataFilters $filters): Collection
    {
        return DB::query()->fromSub($this->pairs($from, $to, $filters), 'p')
            ->join('ems_supply_items as si', 'si.id', '=', 'p.supply_item_id')
            ->leftJoin('ems_catalog_items as sci', 'sci.id', '=', 'si.catalog_item_id')
            ->groupBy('si.id', 'sci.id')
            ->selectRaw('si.id AS item_id, si.name AS name, si.count_unit AS count_unit, si.package_unit AS package_unit, si.units_per_package AS units_per_package, sci.category AS category, COUNT(*) AS events, SUM(p.net_base) AS net_base, MAX(p.eff_at) AS last_at, COUNT(DISTINCT '.UnitKey::ordinalSql('p.apparatus_id', 'p.target_kind').') AS units')
            ->get();
    }

    /**
     * One row per unit KEY with events: each truck row, Detail, and a single `unassigned` row however many target kinds
     * (rescue and other unit) lost their truck. Items are counted distinctly within each unit key.
     *
     * @return Collection<int, stdClass>
     */
    public function byUnit(CarbonImmutable $from, CarbonImmutable $to, SupplyDataFilters $filters): Collection
    {
        return DB::query()->fromSub($this->pairs($from, $to, $filters), 'p')
            ->groupByRaw(UnitKey::ordinalSql('p.apparatus_id', 'p.target_kind'))
            ->selectRaw('MAX(p.apparatus_id) AS apparatus_id, MAX(p.target_kind) AS target_kind, COUNT(*) AS events, COUNT(DISTINCT p.supply_item_id) AS items, SUM(p.net_base) AS net_base, MAX(p.eff_at) AS last_at, MAX(p.target_label) AS label')
            ->get();
    }

    /**
     * Events and net base units per trend bucket index (zero buckets are absent; the caller fills them).
     *
     * @param  list<array{label: string, start: CarbonImmutable}>  $buckets
     * @return array<int, array{events: int, net_base: int}>
     */
    public function byBucket(CarbonImmutable $from, CarbonImmutable $to, SupplyDataFilters $filters, array $buckets): array
    {
        [$case, $bindings] = self::bucketCase($buckets, 'p.eff_at');
        $rows = DB::query()->fromSub($this->pairs($from, $to, $filters), 'p')
            ->selectRaw("{$case} AS bucket, COUNT(*) AS events, SUM(p.net_base) AS net_base", $bindings)
            ->groupBy('bucket')
            ->get();

        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row->bucket] = ['events' => (int) $row->events, 'net_base' => (int) $row->net_base];
        }

        return $result;
    }

    /**
     * SQL that turns a timestamp column into the index of the trend bucket it falls in. Boundaries are Eastern
     * midnights, so the answer is right on both sides of a daylight-saving change in any database.
     *
     * @param  list<array{label: string, start: CarbonImmutable}>  $buckets
     * @return array{0: string, 1: list<string>}
     */
    public static function bucketCase(array $buckets, string $column): array
    {
        if (count($buckets) <= 1) {
            return ['0', []];
        }
        $sql = 'CASE';
        $bindings = [];
        foreach (array_slice($buckets, 1) as $index => $bucket) {
            $sql .= " WHEN {$column} < ".Instant::placeholder()." THEN {$index}";
            $bindings[] = Instant::bind($bucket['start']);
        }

        return [$sql.' ELSE '.(count($buckets) - 1).' END', $bindings];
    }

    /** Issued movements of non-controlled items in the period that have no request, and those on requests that are not counted (cancelled, returned, superseded). */
    public function integrity(CarbonImmutable $from, CarbonImmutable $to): stdClass
    {
        $query = DB::table('ems_stock_movements as m')
            ->join('ems_supply_items as i', 'i.id', '=', 'm.supply_item_id')
            ->leftJoin('ems_inventory_requests as r', 'r.id', '=', 'm.request_id')
            ->where('i.controlled_substance', false)
            ->where('m.movement_type', StockMovement::ISSUED);
        Instant::where($query, 'm.occurred_at', '>=', $from);
        Instant::where($query, 'm.occurred_at', '<', $to);
        $row = $query
            ->selectRaw('COALESCE(SUM(CASE WHEN m.request_id IS NULL THEN 1 ELSE 0 END), 0) AS unattributed,
                COALESCE(SUM(CASE WHEN r.status IN (\'cancelled\', \'returned\', \'superseded\') THEN 1 ELSE 0 END), 0) AS on_uncounted_requests')
            ->first();

        return $row ?? (object) ['unattributed' => 0, 'on_uncounted_requests' => 0];
    }
}
