<?php

declare(strict_types=1);

namespace App\Services\Ems;

use App\Models\Ems\InventorySnapshot;
use App\Models\Ems\InventorySnapshotLine;
use App\Models\Ems\SupplyItem;
use App\Models\User;

/** JSON shapes for counts, reconciliations and baselines. */
final class SnapshotPresenter
{
    private const KIND_LABELS = [
        InventorySnapshot::KIND_COUNT => 'Physical count',
        InventorySnapshot::KIND_RECONCILIATION => 'Reconciliation (calculated)',
        InventorySnapshot::KIND_BASELINE => 'Baseline',
    ];

    /** @return array<string, mixed> */
    public function summary(InventorySnapshot $snapshot): array
    {
        $names = User::query()->whereIn('id', array_filter([$snapshot->started_by_user_id]))->pluck('name', 'id');

        return [
            'id' => $snapshot->uuid,
            'kind' => $snapshot->kind,
            'kind_label' => self::KIND_LABELS[$snapshot->kind] ?? $snapshot->kind,
            'status' => $snapshot->status,
            'version' => $snapshot->version,
            'scope_location_code' => $snapshot->scope_location_code,
            'started_at' => $snapshot->started_at?->toIso8601String(),
            'started_by' => $names[$snapshot->started_by_user_id] ?? null,
            'finalized_at' => $snapshot->finalized_at?->toIso8601String(),
            'finalized_name' => $snapshot->finalized_name,
            'cutoff_at' => $snapshot->cutoff_at?->toIso8601String(),
            'through_movement_id' => $snapshot->through_movement_id,
            'previous_snapshot_id' => $snapshot->previous_snapshot_id !== null
                ? InventorySnapshot::query()->whereKey($snapshot->previous_snapshot_id)->value('uuid')
                : null,
            'notes' => $snapshot->notes,
            'line_count' => $snapshot->lines()->count(),
            'counted_count' => $snapshot->lines()->whereNotNull('quantity_base')->count(),
        ];
    }

    /** @return array<string, mixed> */
    public function detail(InventorySnapshot $snapshot, bool $withItems = false): array
    {
        $lines = $snapshot->lines()->get();
        $data = $this->summary($snapshot) + [
            'lines' => $lines->map(fn (InventorySnapshotLine $line): array => [
                'supply_item_id' => $line->supply_item_id,
                'item_name' => $line->item_name,
                'location_code' => $line->location_code,
                'quantity_base' => $line->quantity_base,
                'entry' => $line->entry ?? [],
                'value_source' => $line->value_source,
                'warnings' => $line->warnings ?? [],
            ])->values()->all(),
        ];

        if ($withItems && $snapshot->status === InventorySnapshot::DRAFT) {
            $entries = $lines->keyBy('supply_item_id');
            $data['items'] = SupplyItem::query()->with('location:id,code')
                ->where('active', true)->where('controlled_substance', false)
                ->when($snapshot->scope_location_code !== null, fn ($query) => $query->whereHas('location', fn ($l) => $l->where('code', $snapshot->scope_location_code)))
                ->orderBy('name')->get()
                ->map(fn (SupplyItem $item): array => [
                    'id' => $item->getKey(), 'name' => $item->name, 'location_code' => $item->location?->code,
                    'package_unit' => $item->package_unit, 'units_per_package' => $item->units_per_package, 'packages_per_case' => $item->packages_per_case,
                    'entry' => $entries->get($item->getKey())?->entry ?? null,
                    'quantity_base' => $entries->get($item->getKey())?->quantity_base,
                    'warnings' => $entries->get($item->getKey())?->warnings ?? [],
                ])->values()->all();
        }

        return $data;
    }
}
