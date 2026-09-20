<?php

namespace Tests\Feature\Filament;

use App\Filament\Widgets\InventoryOverviewWidget;
use App\Models\EquipmentItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use Tests\TestCase;

final class InventoryOverviewWidgetTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_counts_low_stock_from_mutations_without_a_stock_column(): void
    {
        self::assertFalse(Schema::hasColumn('equipment_items', 'stock'));

        $lowStockItem = EquipmentItem::query()->create([
            'name' => 'Low stock item',
            'normalized_name' => 'low stock item',
            'reorder_min' => 2,
            'is_active' => true,
        ]);
        $lowStockItem->increaseStock(1);

        $wellStockedItem = EquipmentItem::query()->create([
            'name' => 'Well stocked item',
            'normalized_name' => 'well stocked item',
            'reorder_min' => 2,
            'is_active' => true,
        ]);
        $wellStockedItem->increaseStock(3);

        $inactiveItem = EquipmentItem::query()->create([
            'name' => 'Inactive item',
            'normalized_name' => 'inactive item',
            'reorder_min' => 2,
            'is_active' => false,
        ]);
        $inactiveItem->increaseStock(0);

        $method = new ReflectionMethod(InventoryOverviewWidget::class, 'getStats');
        $stats = $method->invoke(app(InventoryOverviewWidget::class));

        self::assertSame(1, $stats[0]->getValue());
    }
}
