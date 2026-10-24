<?php

declare(strict_types=1);

namespace App\Services\Ems;

use App\Models\Ems\SupplyItem;
use App\Models\Ems\SupplyOrder;

/**
 * Reorder advisories (docs/ems/ems-inventory-contract.md section 6). "Reorder recommended" is
 * computed here and never creates an order: an order exists only when a person records that it was placed.
 *
 * An item is advised when it tracks stock, has a known threshold, has a known current quantity and
 * that quantity is BELOW the threshold. Items with an unknown threshold are listed separately for
 * review, and items with a threshold but no count yet are listed as "needs a count".
 */
final class ReorderAdvisor
{
    public function __construct(private readonly StockLedger $ledger) {}

    /** @return array{advisories: list<array<string, mixed>>, unknown_threshold: list<array<string, mixed>>, needs_count: list<array<string, mixed>>, note: string} */
    public function advise(): array
    {
        $items = SupplyItem::query()->with('location:id,code')->where('active', true)->where('controlled_substance', false)->orderBy('name')->get();
        $current = $this->ledger->current($items);
        $openOrders = SupplyOrder::query()->where('status', SupplyOrder::PLACED)->get()->groupBy('supply_item_id');

        $advisories = [];
        $unknown = [];
        $needsCount = [];
        foreach ($items as $item) {
            $now = $current[$item->getKey()];
            $base = [
                'supply_item_id' => $item->getKey(), 'name' => $item->name, 'location_code' => $item->location?->code,
                'threshold_qty_base' => $item->threshold_qty_base, 'threshold_source_text' => $item->threshold_source_text,
                'needs_review' => $item->needs_review, 'review_reasons' => $item->review_reasons ?? [],
            ];

            if ($item->threshold_qty_base === null) {
                if ($item->threshold_source_text !== null || $item->needs_review) {
                    $unknown[] = $base;
                }

                continue;
            }
            if ($now['quantity'] === null) {
                $needsCount[] = $base;

                continue;
            }
            if ($now['quantity'] >= $item->threshold_qty_base) {
                continue;
            }

            [$packages, $how] = $this->suggestedPackages($item, $now['quantity']);
            $first = (array) (($item->suppliers ?? [])[0] ?? []);
            $advisories[] = $base + [
                'current_quantity' => $now['quantity'],
                'current_basis' => $now['basis'],
                'current_basis_label' => $now['basis_label'],
                'shortfall_base' => $item->threshold_qty_base - $now['quantity'],
                'suggested_packages' => $packages,
                'suggested_basis' => $how,
                'package_unit' => $item->package_unit,
                'units_per_package' => $item->units_per_package,
                'order_amount_text' => $item->order_amount_text,
                'supplier_code' => $first['code'] ?? null,
                'item_number' => ($item->item_numbers ?? [])[0] ?? null,
                'details_unverified' => true,
                'open_orders' => ($openOrders->get($item->getKey()) ?? collect())->map(fn (SupplyOrder $o): array => ['id' => $o->getKey(), 'packages' => $o->packages, 'placed_at' => $o->placed_at?->toIso8601String()])->values()->all(),
            ];
        }

        return [
            'advisories' => $advisories,
            'unknown_threshold' => $unknown,
            'needs_count' => $needsCount,
            'note' => 'A recommendation is not an order. Supplier details are historical and unverified. Record an order only after it has been placed.',
        ];
    }

    /**
     * The source's order amount when it states one; otherwise the shortfall rounded up to whole
     * packages when the package size is explicit; otherwise unknown.
     *
     * @return array{0: int|null, 1: string}
     */
    private function suggestedPackages(SupplyItem $item, int $current): array
    {
        if ($item->order_qty_packages !== null) {
            return [$item->order_qty_packages, 'source_order_amount'];
        }
        if ($item->units_per_package !== null && $item->units_per_package > 0) {
            return [(int) ceil(($item->threshold_qty_base - $current) / $item->units_per_package), 'rounded_up_to_packages'];
        }

        return [null, 'unknown_package_size'];
    }
}
