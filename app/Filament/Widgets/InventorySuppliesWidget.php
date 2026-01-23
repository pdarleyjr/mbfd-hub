<?php

namespace App\Filament\Widgets;

use App\Models\EquipmentItem;
use Filament\Widgets\Widget;

class InventorySuppliesWidget extends Widget
{
    protected static string $view = 'filament.widgets.inventory-supplies-widget';
    protected static ?int $sort = 2;
    protected int | string | array $columnSpan = 'full';

    public function getViewData(): array
    {
        $totalItems = EquipmentItem::where('is_active', true)->count();
        
        // Get all active equipment items
        $allEquipment = EquipmentItem::where('is_active', true)->get();
        
        // Calculate low stock items (stock <= reorder_min)
        $lowStockItems = $allEquipment->filter(fn($item) => $item->stock <= $item->reorder_min);
        $outOfStockItems = $allEquipment->filter(fn($item) => $item->stock == 0);
        
        // Get top 5 low-stock items with color coding
        $topLowStockItems = $lowStockItems
            ->sortBy('stock')
            ->take(5)
            ->map(function($item) {
                return [
                    'id' => $item->id,
                    'name' => $item->name,
                    'stock' => $item->stock,
                    'reorder_min' => $item->reorder_min,
                    'category' => $item->category,
                    'location' => $item->location?->full_location ?? 'N/A',
                    'status' => $item->stock == 0 ? 'out' : 'low',
                ];
            })
            ->values()
            ->toArray();
        
        return [
            'totalItems' => $totalItems,
            'lowStockCount' => $lowStockItems->count(),
            'outOfStockCount' => $outOfStockItems->count(),
            'topLowStockItems' => $topLowStockItems,
        ];
    }
}
