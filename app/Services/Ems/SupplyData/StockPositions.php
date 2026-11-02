<?php

declare(strict_types=1);

namespace App\Services\Ems\SupplyData;

use App\Models\Ems\InventorySnapshot;
use App\Models\Ems\SupplyItem;
use App\Services\Ems\CountMath;
use App\Services\Ems\ReorderAdvisor;
use App\Services\Ems\StockLedger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Where each supply room item stands now: the ledger's quantity and its basis, the last real count, the order level
 * and whether a reorder is recommended. Quantities come only from StockLedger and reorder advice only from
 * ReorderAdvisor; nothing here recomputes either. A balance whose newest number is an estimate is labelled "estimate"
 * and is never used for days of supply.
 */
final class StockPositions
{
    /** A count older than this (or no real count at all) counts as "not counted recently". */
    public const STALE_COUNT_DAYS = 10;

    /** An estimate this many times its order level or more is listed as one that may have been entered too high. */
    public const SUSPECT_ESTIMATE_RATIO = 10;

    /** Days of supply needs at least this many distribution events in the period, so one big week is not a pace. */
    public const MIN_EVENTS_FOR_PACE = 3;

    private const STATUS_ORDER = ['zero' => 0, 'below_minimum' => 1, 'unknown' => 2, 'ok' => 3];

    public function __construct(private readonly StockLedger $ledger, private readonly ReorderAdvisor $advisor) {}

    /**
     * @param  array<int|string, stdClass>  $distributed  this period's rows keyed by item id
     * @return array{rows: list<array<string, mixed>>, attention: array<string, int>, quality: array<string, mixed>, categories: list<string>}
     */
    public function build(SupplyDataFilters $filters, array $distributed): array
    {
        $items = $this->items($filters);
        $ids = $items->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $current = $this->ledger->current($items);
        $origins = $this->origins($current);
        $lastCounts = $ids === [] ? [] : $this->ledger->lastCounts($ids);
        $deductions = $this->ledger->deductionsSinceCount($lastCounts);
        $advice = collect($this->advisor->advise()['advisories'])->keyBy('supply_item_id');
        $staleBefore = $filters->now->subDays(self::STALE_COUNT_DAYS);

        $rows = [];
        $suspect = [];
        $counters = ['below_minimum' => 0, 'zero_stock' => 0, 'estimated' => 0, 'not_counted_recently' => 0, 'needs_reorder' => 0];
        $quality = ['unresolved_conversions' => 0, 'uncounted_items' => 0, 'negative_balances' => 0, 'items_without_minimum' => 0];

        foreach ($items as $item) {
            $id = (int) $item->getKey();
            $row = $this->row($item, $current[$id], $origins, $lastCounts[$id] ?? null, $deductions[$id] ?? null, $advice->get($id), $distributed[$id] ?? null, $filters);
            $rows[] = $row['row'];
            if (! $row['row']['active']) {
                continue; // a retired item is shown when asked for, but it is not part of today's attention or quality counts
            }

            $counters['below_minimum'] += $row['row']['status'] === 'below_minimum' ? 1 : 0;
            $counters['zero_stock'] += $row['row']['status'] === 'zero' ? 1 : 0;
            $counters['estimated'] += $row['row']['basis'] === 'estimate' ? 1 : 0;
            $counters['needs_reorder'] += $row['row']['needs_reorder'] ? 1 : 0;
            $lastCountedAt = $row['row']['last_counted_at'];
            $counters['not_counted_recently'] += ($lastCountedAt === null || CarbonImmutable::parse($lastCountedAt)->lessThan($staleBefore)) ? 1 : 0;
            $quality['unresolved_conversions'] += $row['convertible'] ? 0 : 1;
            $quality['uncounted_items'] += $row['row']['basis'] === 'none' ? 1 : 0;
            $quality['negative_balances'] += ($current[$id]['quantity'] ?? 0) < 0 ? 1 : 0;
            $quality['items_without_minimum'] += $item->threshold_qty_base === null ? 1 : 0;
            if ($row['suspect'] !== null) {
                $suspect[] = $row['suspect'];
            }
        }

        usort($rows, fn (array $a, array $b): int => [self::STATUS_ORDER[$a['status']], mb_strtolower($a['name']), $a['item_id']] <=> [self::STATUS_ORDER[$b['status']], mb_strtolower($b['name']), $b['item_id']]);
        usort($suspect, fn (array $a, array $b): int => $b['times_order_level'] <=> $a['times_order_level']);

        return [
            'rows' => $rows,
            'attention' => $counters,
            'quality' => $quality + ['estimates_far_above_order_level' => $suspect],
            'categories' => $this->categories($items, $filters),
        ];
    }

    /** @return Collection<int, SupplyItem> */
    private function items(SupplyDataFilters $filters): Collection
    {
        return SupplyItem::query()
            ->leftJoin('ems_storage_locations as sl', 'sl.id', '=', 'ems_supply_items.location_id')
            ->leftJoin('ems_catalog_items as ci', 'ci.id', '=', 'ems_supply_items.catalog_item_id')
            ->where('ems_supply_items.controlled_substance', false)
            // The list is the active catalogue; an item asked for by id is shown even when retired, labelled inactive.
            ->when($filters->item === null, fn ($query) => $query->where('ems_supply_items.active', true))
            ->when($filters->item !== null, fn ($query) => $query->where('ems_supply_items.id', $filters->item))
            ->when($filters->category !== null, fn ($query) => $query->where('ci.category', $filters->category))
            ->orderBy('ems_supply_items.name')->orderBy('ems_supply_items.id')
            ->get(['ems_supply_items.id', 'ems_supply_items.name', 'ems_supply_items.count_unit', 'ems_supply_items.package_unit', 'ems_supply_items.units_per_package',
                'ems_supply_items.threshold_qty_base', 'ems_supply_items.active', 'sl.code as location_code', 'ci.category as category']);
    }

    /**
     * @param  array<int, array{quantity: int|null, basis: string, baseline_snapshot_id: int|null}>  $current
     * @return array<int, string|null> snapshot id => origin
     */
    private function origins(array $current): array
    {
        $ids = array_values(array_unique(array_filter(array_column($current, 'baseline_snapshot_id'))));

        return $ids === [] ? [] : DB::table('ems_inventory_snapshots')->whereIn('id', $ids)->pluck('origin', 'id')->all();
    }

    /**
     * @param  array{quantity: int|null, basis: string, basis_at: string|null, baseline_snapshot_id: int|null}  $now
     * @param  array<int, string|null>  $origins
     * @param  array{counted_at: CarbonImmutable|null, estimated: bool}|null  $lastCount
     * @param  array<string, mixed>|null  $advice
     * @return array{row: array<string, mixed>, convertible: bool, suspect: array<string, mixed>|null}
     */
    private function row(SupplyItem $item, array $now, array $origins, ?array $lastCount, ?int $deducted, ?array $advice, ?stdClass $distributed, SupplyDataFilters $filters): array
    {
        $convertible = $item->count_unit === 'each' || ($item->package_unit === $item->count_unit && (int) $item->units_per_package >= 1);
        $per = $convertible ? CountMath::perCountUnit($item) : 1;
        $quantity = $now['quantity'];
        $origin = $now['baseline_snapshot_id'] === null ? null : ($origins[$now['baseline_snapshot_id']] ?? null);
        $estimated = $quantity !== null && $origin === InventorySnapshot::ORIGIN_ESTIMATE;
        $threshold = $item->threshold_qty_base;
        $status = match (true) {
            $quantity === null => 'unknown',
            $quantity <= 0 => 'zero',
            $threshold !== null && $quantity < $threshold => 'below_minimum',
            default => 'ok',
        };
        $events = (int) ($distributed->events ?? 0);
        $netBase = (int) ($distributed->net_base ?? 0);
        $perDay = $netBase / max(1, $filters->days);
        $minimum = $threshold === null ? null : CountMath::display($threshold, $per);

        $row = [
            'item_id' => (int) $item->getKey(),
            'name' => (string) $item->name,
            'category' => $item->getAttribute('category'),
            'location' => $item->getAttribute('location_code'),
            'count_unit' => $convertible ? (string) $item->count_unit : 'each',
            'on_hand' => $quantity === null ? null : CountMath::display($quantity, $per),
            'basis' => $quantity === null ? 'none' : ($estimated ? 'estimate' : $now['basis']),
            'baseline_origin' => $quantity === null ? null : $origin,
            'last_counted_at' => $lastCount !== null && ! $lastCount['estimated'] ? $lastCount['counted_at']?->setTimezone(StockLedger::TIMEZONE)->toIso8601String() : null,
            'deductions_since_count' => $lastCount === null || $deducted === null ? null : CountMath::display($deducted, $per),
            'minimum' => $minimum,
            'order_level' => $minimum,
            'status' => $status,
            'needs_reorder' => $advice !== null,
            'recommended_order' => $advice['suggested_packages'] ?? null,
            'recommended_order_unit' => $advice['package_unit'] ?? null,
            'recommended_order_basis' => $advice['suggested_basis'] ?? null,
            'avg_daily_distributed' => round($perDay / $per, 2),
            'days_of_supply' => ! $estimated && $quantity !== null && $quantity > 0 && $netBase > 0 && $events >= self::MIN_EVENTS_FOR_PACE
                ? round($quantity / $perDay, 1) : null,
            'estimated' => $estimated,
            'units_resolved' => $convertible,
            'active' => (bool) $item->active,
        ];

        $suspect = $estimated && $threshold !== null && $threshold > 0 && $quantity / $threshold >= self::SUSPECT_ESTIMATE_RATIO
            ? ['item_id' => $row['item_id'], 'name' => $row['name'], 'on_hand' => $row['on_hand'], 'count_unit' => $row['count_unit'], 'times_order_level' => (int) round($quantity / $threshold)]
            : null;

        return ['row' => $row, 'convertible' => $convertible, 'suspect' => $suspect];
    }

    /**
     * Category choices for the filter. Reads the same items; one extra query only when a category filter has narrowed them.
     *
     * @param  Collection<int, SupplyItem>  $items
     * @return list<string>
     */
    private function categories(Collection $items, SupplyDataFilters $filters): array
    {
        if ($filters->category === null && $filters->item === null) {
            return $items->pluck('category')->filter()->unique()->sort()->values()->all();
        }

        return DB::table('ems_supply_items as i')->join('ems_catalog_items as ci', 'ci.id', '=', 'i.catalog_item_id')
            ->where('i.active', true)->where('i.controlled_substance', false)->whereNotNull('ci.category')
            ->distinct()->orderBy('ci.category')->pluck('ci.category')->map(fn ($c): string => (string) $c)->all();
    }
}
