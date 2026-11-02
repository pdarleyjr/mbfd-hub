<?php

declare(strict_types=1);

namespace App\Services\Ems\SupplyData;

use App\Models\Ems\StockMovement;
use App\Models\Ems\SupplyItem;
use App\Support\HubTimezone;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/** One supply item: balance, distribution over the period, the units that received it, counts and movements. */
final class ItemReport
{
    public const MOVEMENT_LIMIT = 50;

    public const COUNT_LIMIT = 20;

    public function __construct(
        private readonly DistributionQuery $distribution,
        private readonly CountVarianceQuery $counts,
        private readonly StockPositions $stock,
    ) {}

    /** @return array<string, mixed> */
    public function build(SupplyItem $item, SupplyDataFilters $filters): array
    {
        $item->loadMissing(['location:id,code', 'catalogItem:id,category']);
        $f = $filters->forItem((int) $item->getKey());
        $buckets = $f->buckets();
        $units = Support::units($item->count_unit, $item->package_unit, $item->units_per_package);

        $current = $this->distribution->byItem($f->fromUtc(), $f->toUtc(), $f)->first();
        $previous = $this->distribution->byItem($f->previousFromUtc(), $f->previousToUtc(), $f)->first();
        $byUnit = $this->distribution->byUnit($f->fromUtc(), $f->toUtc(), $f);
        $byBucket = $this->distribution->byBucket($f->fromUtc(), $f->toUtc(), $f, $buckets);
        $position = $this->stock->build($f, $current === null ? [] : [(int) $item->getKey() => $current])['rows'][0] ?? null;

        $netBase = (int) ($current->net_base ?? 0);
        $events = (int) ($current->events ?? 0);
        $weekly = $f->isMonthly() ? [] : array_map(fn (int $index): float => (float) (Support::amount($byBucket[$index]['net_base'] ?? 0, $units['per']) ?? 0), array_keys($buckets));
        $movements = $this->movements($item, $f, $units['per']);

        return [
            'label' => SupplyDataReport::LABEL,
            'generated_at' => $f->now->toIso8601String(),
            'timezone' => HubTimezone::NAME,
            'item' => [
                'item_id' => (int) $item->getKey(), 'name' => (string) $item->name, 'category' => $item->catalogItem?->category,
                'location' => $item->location?->code, 'count_unit' => $units['unit'], 'package_unit' => $item->package_unit,
                'package_size' => $item->units_per_package, 'controlled' => (bool) $item->controlled_substance, 'active' => (bool) $item->active,
                'units_resolved' => $units['resolved'],
            ],
            'balance' => $position === null ? $this->noBalance() : array_intersect_key($position, array_flip([
                'on_hand', 'basis', 'baseline_origin', 'estimated', 'last_counted_at', 'deductions_since_count', 'minimum', 'order_level', 'status',
                'needs_reorder', 'recommended_order', 'recommended_order_unit', 'recommended_order_basis', 'days_of_supply', 'avg_daily_distributed',
            ])),
            'period' => $f->periodSummary(),
            'filters' => $f->filterSummary(),
            'distribution' => [
                'distributed' => Support::amount($netBase, $units['per']) ?? 0,
                'distribution_events' => $events,
                'previous_distribution_events' => (int) ($previous->events ?? 0),
                'change_pct' => Support::changePct($events, (int) ($previous->events ?? 0)),
                'avg_weekly' => round($netBase / $units['per'] / ($f->days / 7), 1),
                'median_weekly' => $f->isMonthly() ? null : Support::median($weekly),
                'last_distributed_at' => Support::at($current->last_at ?? null),
            ],
            'trend' => $this->trend($f, $buckets, $byBucket, $units['per']),
            'units' => $this->unitShares($byUnit, $netBase, $units['per']),
            'replenishment' => $this->replenishment($item, $f, $units['per']),
            'counts' => array_map(fn (array $row): array => [
                'counted_at' => $row['counted_at']->toIso8601String(), 'verified' => $row['estimate'] ? null : $row['counted'],
                'estimated' => $row['estimate'] ? $row['counted'] : null, 'expected' => $row['expected'], 'variance' => $row['variance'],
                'variance_pct' => $row['variance_pct'], 'basis_note' => $row['basis_note'],
            ], $this->counts->history((int) $item->getKey(), self::COUNT_LIMIT)),
            'movements' => array_slice($movements, 0, self::MOVEMENT_LIMIT),
            'movements_truncated' => count($movements) > self::MOVEMENT_LIMIT,
        ];
    }

    /** @return array<string, mixed> */
    private function noBalance(): array
    {
        return [
            'on_hand' => null, 'basis' => 'none', 'baseline_origin' => null, 'estimated' => false, 'last_counted_at' => null,
            'deductions_since_count' => null, 'minimum' => null, 'order_level' => null, 'status' => 'unknown', 'needs_reorder' => false,
            'recommended_order' => null, 'recommended_order_unit' => null, 'recommended_order_basis' => null, 'days_of_supply' => null,
            'avg_daily_distributed' => null,
        ];
    }

    /**
     * @param  list<array{label: string, start: \Carbon\CarbonImmutable}>  $buckets
     * @param  array<int, array{events: int, net_base: int}>  $byBucket
     * @return list<array<string, int|float|string>>
     */
    private function trend(SupplyDataFilters $f, array $buckets, array $byBucket, int $per): array
    {
        $rows = [];
        foreach ($buckets as $index => $bucket) {
            $rows[] = [
                $f->bucketKey() => $bucket['label'],
                'distributed' => Support::amount($byBucket[$index]['net_base'] ?? 0, $per) ?? 0,
                'distribution_events' => $byBucket[$index]['events'] ?? 0,
            ];
        }

        return $rows;
    }

    /**
     * @param  Collection<int, stdClass>  $byUnit
     * @return list<array<string, mixed>>
     */
    private function unitShares(Collection $byUnit, int $netBase, int $per): array
    {
        return $byUnit->sortBy([['net_base', 'desc'], ['events', 'desc'], ['label', 'asc']])->map(function (stdClass $row) use ($netBase, $per): array {
            $key = UnitKey::of($row->apparatus_id === null ? null : (int) $row->apparatus_id, $row->target_kind);

            return [
                'unit_key' => $key, 'label' => Support::unitLabel($key, $row->label), 'distribution_events' => (int) $row->events,
                'distributed' => Support::amount($row->net_base, $per) ?? 0,
                'share_pct' => $netBase > 0 ? round((int) $row->net_base / $netBase * 100, 1) : null,
            ];
        })->values()->all();
    }

    /** @return list<array<string, mixed>> */
    private function replenishment(SupplyItem $item, SupplyDataFilters $f, int $per): array
    {
        return DB::table('ems_stock_movements as m')
            ->where('m.supply_item_id', $item->getKey())
            ->where('m.occurred_at', '>=', $f->fromUtc()->format('Y-m-d H:i:s'))->where('m.occurred_at', '<', $f->toUtc()->format('Y-m-d H:i:s'))
            ->where(fn ($q) => $q->where('m.movement_type', StockMovement::RECEIVED)->orWhere(fn ($q2) => $q2->where('m.movement_type', StockMovement::ADJUSTED)->whereNull('m.request_id')))
            ->orderByDesc('m.occurred_at')->orderByDesc('m.id')->limit(self::MOVEMENT_LIMIT)
            ->get(['m.occurred_at', 'm.movement_type', 'm.quantity_base', 'm.reason'])
            ->map(fn (stdClass $m): array => [
                'occurred_at' => Support::at($m->occurred_at), 'type' => $m->movement_type, 'quantity' => Support::amount($m->quantity_base, $per), 'reason' => $m->reason,
            ])->all();
    }

    /**
     * Newest movements in the period (one more than the page size, so the caller can say it was cut).
     *
     * @return list<array<string, mixed>>
     */
    private function movements(SupplyItem $item, SupplyDataFilters $f, int $per): array
    {
        return DB::table('ems_stock_movements as m')
            ->leftJoin('ems_inventory_requests as r', 'r.id', '=', 'm.request_id')
            ->where('m.supply_item_id', $item->getKey())
            ->where('m.occurred_at', '>=', $f->fromUtc()->format('Y-m-d H:i:s'))->where('m.occurred_at', '<', $f->toUtc()->format('Y-m-d H:i:s'))
            ->when($f->unit !== null, fn ($q) => UnitKey::restrict($q, (string) $f->unit, 'r'))
            ->orderByDesc('m.occurred_at')->orderByDesc('m.id')->limit(self::MOVEMENT_LIMIT + 1)
            ->selectRaw('m.id AS id, m.occurred_at AS occurred_at, m.movement_type AS movement_type, m.quantity_base AS quantity_base, m.reason AS reason,
                m.corrects_movement_id AS corrects_movement_id, r.number AS request_number, r.target_label AS unit_label,
                (SELECT COALESCE(SUM(c.quantity_base), 0) FROM ems_stock_movements c WHERE c.corrects_movement_id = m.id) AS corrected_base')
            ->get()
            ->map(fn (stdClass $m): array => [
                'id' => (int) $m->id,
                'occurred_at' => Support::at($m->occurred_at),
                'type' => $m->movement_type,
                'quantity' => Support::amount($m->quantity_base, $per),
                'unit_label' => $m->unit_label,
                'request_number' => $m->request_number,
                'reason' => $m->reason,
                'corrects_movement_id' => $m->corrects_movement_id === null ? null : (int) $m->corrects_movement_id,
                // An issuance whose corrections add back everything it took.
                'reversed' => $m->movement_type === StockMovement::ISSUED && (int) $m->corrected_base === -(int) $m->quantity_base,
            ])->all();
    }
}
