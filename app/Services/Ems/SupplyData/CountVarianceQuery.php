<?php

declare(strict_types=1);

namespace App\Services\Ems\SupplyData;

use App\Models\Ems\InventorySnapshot;
use App\Services\Ems\CountMath;
use App\Support\HubTimezone;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Physical counts read against what the books expected at the moment each was typed.
 *
 *   expected = previous baseline quantity + movements with  previous_through < id <= this count's through
 *   variance = counted quantity - expected
 *
 * Both cut-offs are movement ids (a count line carries the newest movement that existed when it was typed, its
 * snapshot's value otherwise), never clock times, exactly as StockLedger decides what comes "after" a baseline.
 * A variance is produced ONLY when this is a finished physical count and the previous baseline was itself a counted
 * number that is not an estimate and not a calculation; otherwise the row carries a plain-language reason and no
 * variance. Everything is computed in the database; reads only.
 */
final class CountVarianceQuery
{
    public const NOTE_FIRST = 'This is the first number on record for the item, so there is nothing to compare it with.';

    public const NOTE_ESTIMATE = 'The balance before this count was an estimate, not a physical count, so no variance is shown.';

    public const NOTE_CALCULATED = 'The balance before this count was calculated, not counted, so no variance is shown.';

    public const NOTE_ESTIMATE_ROW = 'Estimate, not a physical count.';

    public const NOTE_BASELINE_ROW = 'Starting balance loaded from the supply room workbook.';

    /** Count units in one SQL expression: the size frozen on the count line, else the item's current size. */
    private const PER_SQL = 'COALESCE(NULLIF(x.line_per, 0), CASE WHEN x.count_unit = \'each\' OR x.units_per_package IS NULL OR x.units_per_package < 1 THEN 1 ELSE x.units_per_package END)';

    /**
     * Totals for finished physical counts in the period.
     *
     * @return array{lines: int, comparable: int, exact: int, items: int}
     */
    public function summary(CarbonImmutable $from, CarbonImmutable $to, ?int $itemId, ?string $category): array
    {
        [$with, $bindings] = $this->base(physicalOnly: true, from: $from, to: $to, itemId: $itemId, category: $category);
        $row = DB::selectOne($with.' SELECT COUNT(*) AS lines, COALESCE(SUM(CASE WHEN y.variance_base IS NOT NULL THEN 1 ELSE 0 END), 0) AS comparable,
            COALESCE(SUM(CASE WHEN y.variance_base = 0 THEN 1 ELSE 0 END), 0) AS exact,
            COUNT(DISTINCT CASE WHEN y.variance_base IS NOT NULL THEN y.item_id END) AS items FROM y', $bindings);

        return ['lines' => (int) ($row->lines ?? 0), 'comparable' => (int) ($row->comparable ?? 0), 'exact' => (int) ($row->exact ?? 0), 'items' => (int) ($row->items ?? 0)];
    }

    /**
     * The comparable counts with the largest differences, biggest first.
     *
     * @return list<array<string, mixed>>
     */
    public function largest(CarbonImmutable $from, CarbonImmutable $to, ?int $itemId, ?string $category, int $limit): array
    {
        [$with, $bindings] = $this->base(physicalOnly: true, from: $from, to: $to, itemId: $itemId, category: $category);
        $rows = DB::select($with.' SELECT y.* FROM y WHERE y.variance_base IS NOT NULL ORDER BY ABS(y.variance_base) * 1.0 / y.per DESC, y.version DESC, y.item_id ASC LIMIT ?', [...$bindings, $limit]);

        return array_map($this->present(...), $rows);
    }

    /**
     * One item's numbered history, newest first, estimates and workbook baselines included and labelled.
     *
     * @return list<array<string, mixed>>
     */
    public function history(int $itemId, int $limit): array
    {
        [$with, $bindings] = $this->base(physicalOnly: false, from: null, to: null, itemId: $itemId, category: null);
        $rows = DB::select($with.' SELECT y.* FROM y ORDER BY y.version DESC LIMIT ?', [...$bindings, $limit]);

        return array_map($this->present(...), $rows);
    }

    /**
     * The shared query: every counted, numbered line of a final snapshot with the baseline before it and the movements
     * between the two cut-offs.
     *
     * @return array{0: string, 1: list<mixed>}
     */
    private function base(bool $physicalOnly, ?CarbonImmutable $from, ?CarbonImmutable $to, ?int $itemId, ?string $category): array
    {
        $where = ["cs.status = 'final'", 'cl.quantity_base IS NOT NULL', "cl.value_source = 'counted'", 'i.controlled_substance = ?'];
        $bindings = [false];
        if ($physicalOnly) {
            $where[] = "cs.kind = 'physical_count'";
            $where[] = "(cs.origin IS NULL OR cs.origin <> 'estimate')";
        } else {
            $where[] = "cs.kind IN ('physical_count', 'baseline')";
        }
        if ($from !== null && $to !== null) {
            $where[] = 'cs.finalized_at >= '.Instant::placeholder().' AND cs.finalized_at < '.Instant::placeholder();
            array_push($bindings, Instant::bind($from), Instant::bind($to));
        }
        if ($itemId !== null) {
            $where[] = 'cl.supply_item_id = ?';
            $bindings[] = $itemId;
        }
        if ($category !== null) {
            $where[] = 'ci.category = ?';
            $bindings[] = $category;
        }

        $with = 'WITH x AS (
            SELECT cl.supply_item_id AS item_id, i.name AS name, i.count_unit AS count_unit, i.units_per_package AS units_per_package,
                cl.quantity_base AS counted_base, cl.units_per_count_unit AS line_per,
                cs.version AS version, cs.finalized_at AS counted_at, cs.origin AS origin, cs.kind AS kind,
                pl.quantity_base AS prev_base, pl.value_source AS prev_source, ps.origin AS prev_origin,
                (SELECT COALESCE(SUM(m.quantity_base), 0) FROM ems_stock_movements m
                  WHERE m.supply_item_id = cl.supply_item_id
                    AND m.id > COALESCE(pl.through_movement_id, ps.through_movement_id, 0)
                    AND m.id <= COALESCE(cl.through_movement_id, cs.through_movement_id, 0)) AS moved_base
            FROM ems_inventory_snapshot_lines cl
            JOIN ems_inventory_snapshots cs ON cs.id = cl.snapshot_id
            JOIN ems_supply_items i ON i.id = cl.supply_item_id
            LEFT JOIN ems_catalog_items ci ON ci.id = i.catalog_item_id
            LEFT JOIN ems_inventory_snapshot_lines pl ON pl.id = (
                SELECT pl2.id FROM ems_inventory_snapshot_lines pl2
                JOIN ems_inventory_snapshots ps2 ON ps2.id = pl2.snapshot_id
                WHERE pl2.supply_item_id = cl.supply_item_id AND ps2.status = \'final\' AND pl2.quantity_base IS NOT NULL AND ps2.version < cs.version
                ORDER BY ps2.version DESC LIMIT 1)
            LEFT JOIN ems_inventory_snapshots ps ON ps.id = pl.snapshot_id
            WHERE '.implode(' AND ', $where).'
        ), y AS (
            SELECT x.*, '.self::PER_SQL.' AS per,
                CASE WHEN x.kind = \'physical_count\' AND (x.origin IS NULL OR x.origin <> \'estimate\') AND x.prev_base IS NOT NULL
                          AND x.prev_source = \'counted\' AND (x.prev_origin IS NULL OR x.prev_origin <> \'estimate\')
                     THEN x.prev_base + x.moved_base END AS expected_base,
                CASE WHEN x.kind = \'physical_count\' AND (x.origin IS NULL OR x.origin <> \'estimate\') AND x.prev_base IS NOT NULL
                          AND x.prev_source = \'counted\' AND (x.prev_origin IS NULL OR x.prev_origin <> \'estimate\')
                     THEN x.counted_base - (x.prev_base + x.moved_base) END AS variance_base
            FROM x
        )';

        return [$with, $bindings];
    }

    /** @return array<string, mixed> */
    private function present(object $row): array
    {
        $per = max(1, (int) $row->per);
        $estimate = $row->origin === InventorySnapshot::ORIGIN_ESTIMATE;
        $comparable = $row->variance_base !== null;
        $expected = $comparable ? CountMath::display((int) $row->expected_base, $per) : null;
        $variance = $comparable ? CountMath::display((int) $row->variance_base, $per) : null;

        return [
            'item_id' => (int) $row->item_id,
            'name' => (string) $row->name,
            'counted_at' => CarbonImmutable::parse((string) $row->counted_at, 'UTC')->setTimezone(HubTimezone::NAME),
            'counted' => CountMath::display((int) $row->counted_base, $per),
            'expected' => $expected,
            'variance' => $variance,
            'variance_pct' => $comparable && (int) $row->expected_base !== 0 ? round((int) $row->variance_base / (int) $row->expected_base * 100, 1) : null,
            'estimate' => $estimate,
            'origin' => $row->origin,
            'basis_note' => $comparable ? null : $this->reason($row, $estimate),
        ];
    }

    private function reason(object $row, bool $estimate): string
    {
        return match (true) {
            $estimate => self::NOTE_ESTIMATE_ROW,
            $row->kind === InventorySnapshot::KIND_BASELINE => self::NOTE_BASELINE_ROW,
            $row->prev_base === null => self::NOTE_FIRST,
            $row->prev_origin === InventorySnapshot::ORIGIN_ESTIMATE => self::NOTE_ESTIMATE,
            default => self::NOTE_CALCULATED,
        };
    }
}
