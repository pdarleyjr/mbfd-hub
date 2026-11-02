<?php

declare(strict_types=1);

namespace App\Services\Ems\Exports;

use App\Filament\Support\EmsLabels;
use App\Models\Ems\InventorySnapshot;
use App\Models\Ems\InventorySnapshotLine;
use App\Models\Ems\StorageLocation;
use App\Services\Ems\CountMath;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The Monday count as Captain 5 submitted it: the list of submitted counts and one count as a report
 * grouped by shelf. Every value comes from the final snapshot and its stamped lines, never from the
 * master inventory, so a report cannot change when an item is renamed, moved or given a new order
 * level later. The screen and both files are built from this one class, so they always agree.
 */
final class SupplyCountReport
{
    public const NOT_A_PURCHASE_ORDER = 'These are recommended quantities worked out from the count and the order levels in force when it was counted. They are not purchase orders. Nothing was ordered.';

    public const NO_SHELF = 'No shelf assigned';

    /** Shown where an older count has no value, never a zero. */
    public const DASH = '-';

    public const ORDER = 'order';

    public const NONE = 'none';

    public const NOT_COUNTED = 'not_counted';

    public const PROBLEM = 'problem';

    public const UNKNOWN = 'unknown';

    /**
     * Final physical counts made on the Monday count form. Counts from before origins were recorded
     * (null) are kept; single-item direct edits, baselines and calculated reconciliations are not counts of the room.
     *
     * @return Builder<InventorySnapshot>
     */
    public static function listedQuery(): Builder
    {
        return InventorySnapshot::query()
            ->where('kind', InventorySnapshot::KIND_COUNT)
            ->where('status', InventorySnapshot::FINAL)
            ->where(function (Builder $origin): void {
                $origin->whereNull('origin')->orWhere('origin', InventorySnapshot::ORIGIN_COUNT_FORM);
            });
    }

    public static function find(string $uuid): ?InventorySnapshot
    {
        return $uuid === '' ? null : self::listedQuery()->where('uuid', $uuid)->first();
    }

    /**
     * Newest first, with the newest marked.
     *
     * @return array{rows: list<array<string, mixed>>, has_more: bool}
     */
    public function listing(int $limit): array
    {
        $limit = min(max($limit, 1), 500);
        $snapshots = self::listedQuery()
            ->select(['id', 'uuid', 'version', 'finalized_at', 'started_at', 'finalized_name', 'counted_items', 'uncounted_items', 'order_items'])
            ->orderByDesc('finalized_at')->orderByDesc('id')
            ->limit($limit + 1)->get();

        $rows = $snapshots->take($limit)->values()->map(fn (InventorySnapshot $snapshot, int $index): array => [
            'id' => (string) $snapshot->uuid,
            'version' => $snapshot->version,
            'when' => EmsLabels::et($snapshot->finalized_at ?? $snapshot->started_at),
            'counted_by' => filled($snapshot->finalized_name) ? (string) $snapshot->finalized_name : self::DASH,
            'counted' => $snapshot->counted_items,
            'uncounted' => $snapshot->uncounted_items,
            'partial' => (int) $snapshot->uncounted_items > 0,
            'to_order' => $snapshot->order_items,
            'latest' => $index === 0,
        ])->all();

        return ['rows' => $rows, 'has_more' => $snapshots->count() > $limit];
    }

    /**
     * One count as a report. $search narrows the rows on screen; $onlyToOrder keeps lines with something to order.
     *
     * @return array<string, mixed>
     */
    public function report(InventorySnapshot $snapshot, bool $onlyToOrder = false, string $search = ''): array
    {
        $lines = InventorySnapshotLine::query()->where('snapshot_id', $snapshot->getKey())->orderBy('id')->get();
        $shelves = StorageLocation::query()->get(['code', 'label', 'sort'])->keyBy('code');

        $rows = $lines->map(fn (InventorySnapshotLine $line): array => $this->row($line))->all();
        $toOrder = count(array_filter($rows, static fn (array $row): bool => $row['order_kind'] === self::ORDER));
        $needle = mb_strtolower(trim($search));

        $shown = array_values(array_filter($rows, static fn (array $row): bool => (! $onlyToOrder || $row['order_kind'] === self::ORDER)
            && ($needle === '' || str_contains(mb_strtolower($row['item']), $needle))));

        return [
            'id' => (string) $snapshot->uuid,
            'version' => $snapshot->version,
            'counted_by' => filled($snapshot->finalized_name) ? (string) $snapshot->finalized_name : self::DASH,
            'counted_at' => EmsLabels::et($snapshot->finalized_at ?? $snapshot->started_at),
            'counted_date' => EmsLabels::et($snapshot->finalized_at ?? $snapshot->started_at, 'Y-m-d'),
            'counted_items' => $snapshot->counted_items,
            'uncounted_items' => $snapshot->uncounted_items,
            'partial' => (int) $snapshot->uncounted_items > 0,
            'coverage' => $this->coverage($snapshot),
            'to_order' => $snapshot->order_items ?? $toOrder,
            'total_rows' => count($rows),
            'shown_rows' => count($shown),
            'only_to_order' => $onlyToOrder,
            'shelves' => $this->grouped($shown, $shelves),
        ];
    }

    private function coverage(InventorySnapshot $snapshot): string
    {
        if ($snapshot->counted_items === null && $snapshot->uncounted_items === null) {
            return 'Coverage was not recorded for this count.';
        }
        $counted = number_format((int) $snapshot->counted_items);
        if ((int) $snapshot->uncounted_items > 0) {
            return "Partial: {$counted} counted, ".number_format((int) $snapshot->uncounted_items).' not counted.';
        }

        return "Complete: all {$counted} items counted.";
    }

    /**
     * Every shown value is a stamp on the line. A line from an older count may carry none of them.
     *
     * @return array<string, mixed>
     */
    private function row(InventorySnapshotLine $line): array
    {
        $unit = filled($line->count_unit) ? (string) $line->count_unit : null;
        $per = max(1, (int) ($line->units_per_count_unit ?? 1));
        $counted = $line->counted_value;
        $uncounted = $line->value_source === InventorySnapshotLine::NOT_COUNTED || ($line->quantity_base === null && $counted === null);

        [$countedText, $countedValue] = $this->counted($line, $unit, $uncounted);
        [$orderKind, $orderText, $orderValue] = $this->order($line, $unit, $uncounted);

        $note = null;
        if ($line->expected_quantity_base !== null && $line->quantity_base !== null && (int) $line->expected_quantity_base !== (int) $line->quantity_base) {
            $note = 'Books said '.self::number(CountMath::display((int) $line->expected_quantity_base, $per));
        }

        $required = $line->required_qty_base === null ? null : CountMath::display((int) $line->required_qty_base, $per);

        return [
            'shelf' => filled($line->location_code) ? (string) $line->location_code : null,
            'item' => (string) $line->item_name,
            'counted_text' => $countedText,
            'counted_value' => $countedValue,
            'unit' => $unit !== null ? (CountMath::LABELS[$unit][0] ?? $unit) : '',
            'required' => $required,
            'order_kind' => $orderKind,
            'order_text' => $orderText,
            'order_value' => $orderValue,
            'note' => $note,
        ];
    }

    /** @return array{0: string, 1: int|null} text and the number for a spreadsheet */
    private function counted(InventorySnapshotLine $line, ?string $unit, bool $uncounted): array
    {
        if ($uncounted) {
            return [self::DASH, null];
        }
        if ($line->counted_value !== null && $unit !== null) {
            return [number_format((int) $line->counted_value).' '.CountMath::label($unit, (int) $line->counted_value), (int) $line->counted_value];
        }
        if ($line->quantity_base !== null) {
            return [number_format((int) $line->quantity_base).' '.CountMath::label('each', (int) $line->quantity_base), (int) $line->quantity_base];
        }

        return [self::DASH, null];
    }

    /** @return array{0: string, 1: string, 2: int|null} kind, text, and the number when it is an order */
    private function order(InventorySnapshotLine $line, ?string $unit, bool $uncounted): array
    {
        if ($uncounted) {
            return [self::NOT_COUNTED, 'Not counted', null];
        }
        $quantity = $line->order_qty_count_unit;
        if ($quantity !== null && $quantity > 0) {
            return [self::ORDER, number_format($quantity).' '.CountMath::label($unit ?? 'each', $quantity), $quantity];
        }
        if (filled($line->config_problem)) {
            return [self::PROBLEM, (string) $line->config_problem, null];
        }
        if ($quantity === 0) {
            return [self::NONE, 'No order needed', null];
        }

        return [self::UNKNOWN, self::DASH, null];
    }

    /**
     * Shelves in the order of ems_storage_locations.sort, using the code stamped on the line. A code that
     * no longer exists in the table still gets its own group, after the known shelves.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  Collection<string, StorageLocation>  $shelves
     * @return list<array{code: string|null, title: string, label: string|null, rows: list<array<string, mixed>>, to_order: int}>
     */
    private function grouped(array $rows, Collection $shelves): array
    {
        // Keyed with a prefix so a numeric shelf code is never turned into an integer key.
        $groups = [];
        foreach ($rows as $row) {
            $code = (string) $row['shelf'];
            $groups['shelf:'.$code] ??= ['code' => $code, 'rows' => []];
            $groups['shelf:'.$code]['rows'][] = $row;
        }
        $order = static function (string $code) use ($shelves): array {
            if ($code === '') {
                return [2, 0, ''];
            }
            $shelf = $shelves->get($code);

            return $shelf === null ? [1, 0, $code] : [0, (int) $shelf->sort, $code];
        };
        $groups = array_values($groups);
        usort($groups, static fn (array $a, array $b): int => $order($a['code']) <=> $order($b['code']));

        return array_map(static function (array $group) use ($shelves): array {
            $code = $group['code'];
            $shelfRows = $group['rows'];
            usort($shelfRows, static fn (array $a, array $b): int => strcasecmp($a['item'], $b['item']));

            return [
                'code' => $code === '' ? null : $code,
                'title' => $code === '' ? self::NO_SHELF : 'Shelf '.$code,
                'label' => $code === '' ? null : $shelves->get($code)?->label,
                'rows' => $shelfRows,
                'to_order' => count(array_filter($shelfRows, static fn (array $row): bool => $row['order_kind'] === self::ORDER)),
            ];
        }, $groups);
    }

    /** Whole numbers without a decimal point, one decimal otherwise. */
    public static function number(int|float|null $value): string
    {
        if ($value === null) {
            return self::DASH;
        }

        return is_int($value) || floor((float) $value) === (float) $value ? number_format((int) $value) : number_format((float) $value, 1);
    }
}
