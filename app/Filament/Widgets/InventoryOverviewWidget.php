<?php

namespace App\Filament\Widgets;

use App\Models\EquipmentItem;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class InventoryOverviewWidget extends BaseWidget
{
    protected static ?int $sort = 2;

    protected int | string | array $columnSpan = [
        'sm' => 1,
        'md' => 1,
        'xl' => 1,
    ];

    protected function getStats(): array
    {
        $lowStockCount = EquipmentItem::query()
            ->where('is_active', true)
            ->whereColumn('stock', '<=', 'reorder_min')
            ->count();

        return [
            Stat::make('Low-stock exceptions', $lowStockCount)
                ->description($lowStockCount > 0 ? 'Open inventory review' : 'No stock exceptions')
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->color($lowStockCount > 5 ? 'danger' : ($lowStockCount > 0 ? 'warning' : 'success'))
                ->extraAttributes(['class' => $lowStockCount > 5 ? 'bg-red-50 border border-red-200 rounded-xl' : ($lowStockCount > 0 ? 'bg-amber-50 border border-amber-200 rounded-xl' : 'bg-green-50 border border-green-200 rounded-xl')])
                ->url(route('filament.admin.resources.equipment-items.index')),
        ];
    }
}
