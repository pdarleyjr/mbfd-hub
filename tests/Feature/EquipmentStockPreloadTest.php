<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\EquipmentItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Inventory dashboards read stock several times for every active item; a
 * preloaded total must replace laravel-stock's per-access SUM query.
 */
final class EquipmentStockPreloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_preloaded_stock_is_read_without_per_item_queries(): void
    {
        foreach ([0, 2, 7] as $index => $amount) {
            $item = EquipmentItem::query()->create(['name' => "Preload item {$index}", 'reorder_min' => 3]);
            if ($amount > 0) {
                $item->increaseStock($amount);
            }
        }

        DB::enableQueryLog();
        $items = EquipmentItem::withStockTotal(EquipmentItem::query())->orderBy('id')->get();
        $stocks = $items->map(fn (EquipmentItem $item): array => [$item->stock, $item->current_stock, $item->isLowStock()])->all();

        $this->assertSame([[0, 0, true], [2, 2, true], [7, 7, false]], $stocks);
        $this->assertCount(1, DB::getQueryLog(), 'One list query carries every stock total.');
    }

    public function test_a_new_stock_mutation_replaces_the_preloaded_total(): void
    {
        EquipmentItem::query()->create(['name' => 'Mutated item', 'reorder_min' => 1])->increaseStock(4);
        $item = EquipmentItem::withStockTotal(EquipmentItem::query())->firstOrFail();

        $this->assertSame(4, $item->stock);
        $item->decreaseStock(3);

        $this->assertSame(1, $item->stock);
        $this->assertSame(1, $item->current_stock);
    }
}
