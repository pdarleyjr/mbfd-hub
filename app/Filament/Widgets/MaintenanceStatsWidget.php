<?php

namespace App\Filament\Widgets;

use App\Models\ApparatusDefect;
use App\Models\EquipmentItem;
use App\Models\ShopWork;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class MaintenanceStatsWidget extends BaseWidget
{
    protected static ?int $sort = 3;
    
    protected int | string | array $columnSpan = 'full';

    protected function getStats(): array
    {
        $lowStockCount = EquipmentItem::where('is_active', true)
            ->whereColumn('stock', '<=', 'reorder_min')
            ->count();

        $openDefectsCount = ApparatusDefect::where('resolved', false)->count();
        
        $recentShopWorkCount = ShopWork::where('created_at', '>=', now()->subDays(30))->count();
        
        $criticalDefectsCount = ApparatusDefect::where('resolved', false)
            ->where('issue_type', 'critical')
            ->count();

        return [
            Stat::make('Low Stock Items', $lowStockCount)
                ->description('Need reordering')
                ->descriptionIcon('heroicon-o-exclamation-circle')
                ->color($lowStockCount > 0 ? 'danger' : 'success'),
            
            Stat::make('Open Defects', $openDefectsCount)
                ->description('Unresolved issues')
                ->descriptionIcon('heroicon-o-wrench-screwdriver')
                ->color($openDefectsCount > 5 ? 'warning' : 'info'),
            
            Stat::make('Recent Shop Work', $recentShopWorkCount)
                ->description('Last 30 days')
                ->descriptionIcon('heroicon-o-clipboard-document-check')
                ->color('primary'),
            
            Stat::make('Critical Defects', $criticalDefectsCount)
                ->description('Immediate attention')
                ->descriptionIcon('heroicon-o-exclamation-triangle')
                ->color($criticalDefectsCount > 0 ? 'danger' : 'success'),
        ];
    }

    protected function getColumns(): int
    {
        return 4;
    }
}
