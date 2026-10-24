<?php

declare(strict_types=1);

namespace App\Services\Ems;

use App\Models\Ems\InventorySnapshot;
use App\Models\Ems\StockMovement;
use App\Models\Ems\StorageLocation;
use App\Models\Ems\SupplyItem;
use App\Models\Ems\SupplyOrder;

/** Read side of the Medical Supply Room: items with current quantity and basis, locations, movements, orders. */
final class SupplyRoomQuery
{
    public const SUPPLIER_NOTE = 'Supplier names, prices and price dates are historical (2021 to 2023) and unverified.';

    public function __construct(private readonly StockLedger $ledger) {}

    /**
     * @param  array<string, mixed>  $filters  q, location, below_threshold, needs_review, controlled, active, page, per_page
     * @return array{items: list<array<string, mixed>>, meta: array<string, int>}
     */
    public function items(array $filters): array
    {
        $query = SupplyItem::query()->with('location:id,code,label');
        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], mb_strtolower($q)).'%';
            $query->where(fn ($w) => $w->whereRaw('lower(name) like ?', [$like])->orWhereRaw('lower(coalesce(bag_alias_text, \'\')) like ?', [$like]));
        }
        if (! empty($filters['location'])) {
            $query->whereHas('location', fn ($l) => $l->where('code', (string) $filters['location']));
        }
        if (array_key_exists('needs_review', $filters) && $filters['needs_review'] !== null && $filters['needs_review'] !== '') {
            $query->where('needs_review', filter_var($filters['needs_review'], FILTER_VALIDATE_BOOLEAN));
        }
        if (array_key_exists('controlled', $filters) && $filters['controlled'] !== null && $filters['controlled'] !== '') {
            $query->where('controlled_substance', filter_var($filters['controlled'], FILTER_VALIDATE_BOOLEAN));
        }
        $query->where('active', array_key_exists('active', $filters) && $filters['active'] !== null ? filter_var($filters['active'], FILTER_VALIDATE_BOOLEAN) : true);
        $query->orderBy('name')->orderBy('id');

        $below = filter_var($filters['below_threshold'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $perPage = max(1, min(200, (int) ($filters['per_page'] ?? 100)));

        if ($below) {
            // Needs the calculated quantity, so filter after the calculation (the room has a few hundred rows).
            $all = $query->get();
            $current = $this->ledger->current($all->filter->tracksStock());
            $rows = $all->map(fn (SupplyItem $item): array => $this->present($item, $current[$item->getKey()] ?? null))
                ->filter(fn (array $row): bool => $row['below_threshold'] === true)->values();
            $page = max(1, (int) ($filters['page'] ?? 1));

            return ['items' => $rows->forPage($page, $perPage)->values()->all(), 'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => $rows->count(), 'last_page' => max(1, (int) ceil($rows->count() / $perPage))]];
        }

        $page = $query->paginate($perPage, ['*'], 'page', max(1, (int) ($filters['page'] ?? 1)));
        $current = $this->ledger->current($page->getCollection()->filter->tracksStock());

        return [
            'items' => $page->getCollection()->map(fn (SupplyItem $item): array => $this->present($item, $current[$item->getKey()] ?? null))->values()->all(),
            'meta' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        ];
    }

    /** @return array<string, mixed> */
    public function item(SupplyItem $item): array
    {
        $item->loadMissing('location:id,code,label');
        $current = $item->tracksStock() ? ($this->ledger->current(collect([$item]))[$item->getKey()] ?? null) : null;

        return $this->present($item, $current) + [
            'audit' => $item->events()->limit(50)->get()->map(fn ($e): array => [
                'field' => $e->field, 'before' => $e->before['value'] ?? null, 'after' => $e->after['value'] ?? null,
                'reason' => $e->reason, 'actor_name' => $e->actor_name, 'created_at' => $e->created_at?->toIso8601String(),
            ])->all(),
            'source' => $item->source,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $current
     * @return array<string, mixed>
     */
    public function present(SupplyItem $item, ?array $current): array
    {
        $quantity = $current['quantity'] ?? null;

        return [
            'id' => $item->getKey(),
            'catalog_item_id' => $item->catalog_item_id,
            'name' => $item->name,
            'bag_alias_text' => $item->bag_alias_text,
            'location_code' => $item->location?->code,
            'section_key' => $item->section_key,
            'base_unit' => $item->base_unit,
            'package_unit' => $item->package_unit,
            'units_per_package' => $item->units_per_package,
            'packages_per_case' => $item->packages_per_case,
            'qty_per_package_text' => $item->qty_per_package_text,
            'threshold_qty_base' => $item->threshold_qty_base,
            'threshold_source_text' => $item->threshold_source_text,
            'order_amount_text' => $item->order_amount_text,
            'order_qty_packages' => $item->order_qty_packages,
            'suppliers' => $item->suppliers ?? [],
            'item_numbers' => $item->item_numbers ?? [],
            'suppliers_note' => self::SUPPLIER_NOTE,
            'refrigerated' => $item->refrigerated,
            'controlled_substance' => $item->controlled_substance,
            'has_expiration' => $item->has_expiration,
            'needs_review' => $item->needs_review,
            'review_reasons' => $item->review_reasons ?? [],
            'active' => $item->active,
            'tracks_stock' => $item->tracksStock(),
            'controlled_note' => $item->controlled_substance ? 'Narcotics are restocked through the department controlled-substance procedure and are not tracked here.' : null,
            'current_quantity' => $quantity,
            'current_basis' => $current['basis'] ?? ($item->tracksStock() ? 'none' : null),
            'current_basis_label' => $current['basis_label'] ?? ($item->tracksStock() ? 'Not counted yet' : null),
            'current_basis_at' => $current['basis_at'] ?? null,
            'movements_since_basis' => $current['movements_since'] ?? 0,
            'below_threshold' => $quantity !== null && $item->threshold_qty_base !== null ? $quantity < $item->threshold_qty_base : null,
        ];
    }

    /** @return list<array<string, mixed>> */
    public function locations(): array
    {
        return StorageLocation::query()->withCount(['supplyItems as item_count' => fn ($q) => $q->where('active', true)])
            ->orderBy('sort')->orderBy('code')->get()
            ->map(fn (StorageLocation $l): array => ['id' => $l->getKey(), 'code' => $l->code, 'label' => $l->label, 'active' => $l->active, 'item_count' => (int) $l->item_count])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $filters  supply_item_id, type, page, per_page
     * @return array{movements: list<array<string, mixed>>, meta: array<string, int>}
     */
    public function movements(array $filters): array
    {
        $query = StockMovement::query()->with('supplyItem:id,name')->orderByDesc('id');
        if (! empty($filters['supply_item_id'])) {
            $query->where('supply_item_id', (int) $filters['supply_item_id']);
        }
        if (! empty($filters['type']) && in_array($filters['type'], [StockMovement::ISSUED, StockMovement::RECEIVED, StockMovement::ADJUSTED], true)) {
            $query->where('movement_type', $filters['type']);
        }
        $page = $query->paginate(max(1, min(200, (int) ($filters['per_page'] ?? 50))), ['*'], 'page', max(1, (int) ($filters['page'] ?? 1)));

        return [
            'movements' => $page->getCollection()->map(fn (StockMovement $m): array => [
                'id' => $m->getKey(), 'uuid' => $m->uuid, 'supply_item_id' => $m->supply_item_id, 'item_name' => $m->supplyItem?->name,
                'movement_type' => $m->movement_type, 'quantity_base' => $m->quantity_base, 'occurred_at' => $m->occurred_at->toIso8601String(),
                'actor_name' => $m->actor_name, 'reason' => $m->reason, 'request_id' => $m->request_id, 'supply_order_id' => $m->supply_order_id,
                'corrects_movement_id' => $m->corrects_movement_id,
            ])->values()->all(),
            'meta' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        ];
    }

    /**
     * @param  array<string, mixed>  $filters  status, supply_item_id
     * @return list<array<string, mixed>>
     */
    public function orders(array $filters): array
    {
        return SupplyOrder::query()->with('supplyItem:id,name')
            ->when(! empty($filters['status']), fn ($q) => $q->where('status', (string) $filters['status']))
            ->when(! empty($filters['supply_item_id']), fn ($q) => $q->where('supply_item_id', (int) $filters['supply_item_id']))
            ->orderByDesc('id')->limit(300)->get()
            ->map(fn (SupplyOrder $o): array => $this->order($o))->all();
    }

    /** @return array<string, mixed> */
    public function order(SupplyOrder $order): array
    {
        return [
            'id' => $order->getKey(), 'supply_item_id' => $order->supply_item_id, 'item_name' => $order->supplyItem?->name,
            'status' => $order->status, 'packages' => $order->packages, 'quantity_base' => $order->quantity_base,
            'supplier_code' => $order->supplier_code, 'item_number' => $order->item_number, 'notes' => $order->notes,
            'placed_by_name' => $order->placed_by_name, 'placed_at' => $order->placed_at->toIso8601String(),
            'received_at' => $order->received_at?->toIso8601String(), 'cancelled_at' => $order->cancelled_at?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters  kind
     * @return list<array<string, mixed>>
     */
    public function snapshots(array $filters, SnapshotPresenter $presenter): array
    {
        return InventorySnapshot::query()
            ->when(! empty($filters['kind']), fn ($q) => $q->where('kind', (string) $filters['kind']))
            ->when(! empty($filters['status']), fn ($q) => $q->where('status', (string) $filters['status']))
            ->orderByDesc('id')->limit(200)->get()
            ->map(fn (InventorySnapshot $s): array => $presenter->summary($s))->all();
    }
}
