<?php

declare(strict_types=1);

namespace App\Services\Ems;

use App\Models\Ems\InventorySnapshot;
use App\Models\Ems\InventorySnapshotLine;
use App\Models\Ems\StockMovement;
use App\Models\Ems\SupplyItem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The append-only stock ledger and the "current quantity with basis" calculation
 * (docs/ems/ems-inventory-contract.md section 6).
 *
 * current quantity = value in the item's latest FINAL snapshot line that has a number
 *                  + sum of movements recorded after that snapshot.
 * "After" is decided by movement id against the snapshot's through_movement_id, not by clock time,
 * so a movement is counted exactly once even when clocks are equal or a receipt is backdated.
 * Every stock write takes the single stock lock first, so a snapshot can never miss a movement
 * that is committing at the same moment.
 */
final class StockLedger
{
    public const TIMEZONE = 'America/New_York';

    /** Largest stock quantity (in base units) accepted in one entry, so integer columns can never overflow. */
    public const MAX_BASE = 10_000_000;

    /** Take the stock write lock (call inside a transaction, before reading what you will write). */
    public function lock(): void
    {
        DB::table('ems_counters')->insertOrIgnore(['name' => 'stock', 'value' => 0]);
        DB::table('ems_counters')->where('name', 'stock')->lockForUpdate()->first();
        if (DB::table('ems_counters')->where('name', 'stock')->increment('value') < 1) {
            throw new RuntimeException('The EMS stock lock row could not be taken; refusing to change stock without it.');
        }
    }

    /** @throws EmsException when a converted quantity is too large to store safely */
    public static function assertWithinLimit(int $quantityBase): void
    {
        if (abs($quantityBase) > self::MAX_BASE) {
            throw new EmsException('That quantity is too large. Check the number and the package size, then try again.', 'quantity_too_large', 422);
        }
    }

    public function maxMovementId(): int
    {
        return (int) StockMovement::query()->max('id');
    }

    /**
     * Append one movement. Replaying the same idempotency key returns the original row.
     *
     * @param  array{request_id?: int|null, request_line_id?: int|null, supply_order_id?: int|null, corrects_movement_id?: int|null}  $links
     */
    public function record(SupplyItem $item, string $type, int $quantityBase, EmsActor $actor, string $idempotencyKey, ?string $reason = null, array $links = []): StockMovement
    {
        $valid = match ($type) {
            StockMovement::ISSUED => $quantityBase < 0,
            StockMovement::RECEIVED => $quantityBase > 0,
            StockMovement::ADJUSTED => $quantityBase !== 0,
            default => false,
        };
        if (! $valid) {
            throw new EmsException('That stock change has the wrong sign or is zero.', 'invalid_movement');
        }
        if ($item->controlled_substance) {
            throw new EmsException('Controlled substances are restocked through the department controlled-substance procedure and are not tracked here.', 'controlled_not_tracked');
        }

        self::assertWithinLimit($quantityBase);

        $existing = StockMovement::query()->where('idempotency_key', $idempotencyKey)->first();
        if ($existing !== null) {
            // The same key may only ever replay the same stock change.
            if ((int) $existing->supply_item_id !== (int) $item->getKey() || $existing->movement_type !== $type
                || (int) $existing->quantity_base !== $quantityBase || ($existing->request_line_id !== null && $existing->request_line_id !== ($links['request_line_id'] ?? null))) {
                throw EmsException::conflict('That stock change key was already used for a different change. Reload and try again.', 'idempotency_mismatch');
            }

            return $existing;
        }

        return StockMovement::query()->create([
            'uuid' => (string) Str::uuid(),
            'supply_item_id' => $item->getKey(),
            'movement_type' => $type,
            'quantity_base' => $quantityBase,
            'occurred_at' => now(),
            'actor_user_id' => $actor->userId,
            'actor_name' => $actor->name,
            'reason' => $reason,
            'request_id' => $links['request_id'] ?? null,
            'request_line_id' => $links['request_line_id'] ?? null,
            'supply_order_id' => $links['supply_order_id'] ?? null,
            'corrects_movement_id' => $links['corrects_movement_id'] ?? null,
            'idempotency_key' => $idempotencyKey,
        ]);
    }

    /**
     * The newest FINAL snapshot line that carries a number, per supply item.
     *
     * @param  list<int>|null  $itemIds
     * @return array<int, array{quantity: int, snapshot_id: int, version: int, through: int, value_source: string, kind: string, count_at: CarbonImmutable|null, count_snapshot_id: int|null}>
     */
    public function baselines(?array $itemIds = null): array
    {
        $latest = DB::table('ems_inventory_snapshot_lines as l')
            ->join('ems_inventory_snapshots as s', 's.id', '=', 'l.snapshot_id')
            ->where('s.status', InventorySnapshot::FINAL)
            ->whereNotNull('l.quantity_base')
            ->when($itemIds !== null, fn ($query) => $query->whereIn('l.supply_item_id', $itemIds))
            ->groupBy('l.supply_item_id')
            ->selectRaw('l.supply_item_id as supply_item_id, MAX(s.version) as v');

        $rows = DB::table('ems_inventory_snapshot_lines as l')
            ->join('ems_inventory_snapshots as s', 's.id', '=', 'l.snapshot_id')
            ->joinSub($latest, 'm', fn ($join) => $join->on('m.supply_item_id', '=', 'l.supply_item_id')->on('m.v', '=', 's.version'))
            ->where('s.status', InventorySnapshot::FINAL)
            ->whereNotNull('l.quantity_base')
            ->get(['l.supply_item_id', 'l.quantity_base', 'l.value_source', 'l.entry', 'l.through_movement_id as line_through', 's.id as snapshot_id', 's.version', 's.kind', 's.through_movement_id', 's.finalized_at']);

        $map = [];
        foreach ($rows as $row) {
            $entry = is_string($row->entry) ? (array) json_decode($row->entry, true) : [];
            $countAt = $row->value_source === InventorySnapshotLine::COUNTED
                ? $row->finalized_at
                : ($entry['count_at'] ?? null);
            $map[(int) $row->supply_item_id] = [
                'quantity' => (int) $row->quantity_base,
                'snapshot_id' => (int) $row->snapshot_id,
                'version' => (int) $row->version,
                // A count line carries the movement it was typed against; other lines use their snapshot's.
                'through' => (int) ($row->line_through ?? $row->through_movement_id),
                'value_source' => (string) $row->value_source,
                'kind' => (string) $row->kind,
                'count_at' => $countAt !== null ? CarbonImmutable::parse($countAt) : null,
                'count_snapshot_id' => $row->value_source === InventorySnapshotLine::COUNTED ? (int) $row->snapshot_id : (isset($entry['count_snapshot_id']) ? (int) $entry['count_snapshot_id'] : null),
            ];
        }

        return $map;
    }

    /**
     * Movement totals after each item's own baseline (items with no baseline: after movement 0).
     *
     * @param  array<int, int>  $throughByItem  supply_item_id => through movement id
     * @return array<int, array{issued: int, received: int, adjusted: int, count: int, last_id: int}>
     */
    public function movementSums(array $throughByItem): array
    {
        $sums = [];
        foreach (collect($throughByItem)->groupBy(fn (int $through): int => $through, preserveKeys: true) as $through => $group) {
            $rows = StockMovement::query()
                ->whereIn('supply_item_id', $group->keys()->all())
                ->where('id', '>', (int) $through)
                ->groupBy('supply_item_id')
                ->selectRaw("supply_item_id, COUNT(*) as n, MAX(id) as last_id,
                    SUM(CASE WHEN movement_type = 'issued' THEN quantity_base ELSE 0 END) as issued,
                    SUM(CASE WHEN movement_type = 'received' THEN quantity_base ELSE 0 END) as received,
                    SUM(CASE WHEN movement_type = 'adjusted' THEN quantity_base ELSE 0 END) as adjusted")
                ->get();
            foreach ($rows as $row) {
                $sums[(int) $row->supply_item_id] = [
                    'issued' => (int) $row->issued, 'received' => (int) $row->received, 'adjusted' => (int) $row->adjusted,
                    'count' => (int) $row->n, 'last_id' => (int) $row->last_id,
                ];
            }
        }

        return $sums;
    }

    /**
     * @param  Collection<int, SupplyItem>|null  $items  null = every item that tracks stock
     * @return array<int, array{quantity: int|null, basis: string, basis_at: string|null, basis_label: string, movements_since: int, baseline_snapshot_id: int|null}>
     */
    public function current(?Collection $items = null): array
    {
        $items ??= SupplyItem::query()->where('active', true)->where('controlled_substance', false)->get();
        $ids = $items->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $baselines = $this->baselines($ids);
        $sums = $this->movementSums(collect($baselines)->map(fn (array $b): int => $b['through'])->all());

        $result = [];
        foreach ($ids as $id) {
            $baseline = $baselines[$id] ?? null;
            if ($baseline === null) {
                $result[$id] = [
                    'quantity' => null, 'basis' => 'none', 'basis_at' => null, 'basis_label' => 'Not counted yet',
                    'movements_since' => 0, 'baseline_snapshot_id' => null,
                ];

                continue;
            }
            $since = $sums[$id] ?? ['issued' => 0, 'received' => 0, 'adjusted' => 0, 'count' => 0];
            $quantity = $baseline['quantity'] + $since['issued'] + $since['received'] + $since['adjusted'];
            $date = $baseline['count_at'] !== null ? $baseline['count_at']->setTimezone(self::TIMEZONE)->format('M j, Y') : 'an earlier date';
            $counted = $baseline['value_source'] === InventorySnapshotLine::COUNTED && $since['count'] === 0;
            $result[$id] = [
                'quantity' => $quantity,
                'basis' => $counted ? 'counted' : 'calculated',
                'basis_at' => $baseline['count_at']?->toIso8601String(),
                'basis_label' => $counted ? "Counted {$date}" : "Calculated since count {$date}",
                'movements_since' => $since['count'],
                'baseline_snapshot_id' => $baseline['snapshot_id'],
            ];
        }

        return $result;
    }
}
