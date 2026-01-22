<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\EquipmentItemResource;
use App\Filament\Resources\RecommendationResource;
use App\Models\ApparatusDefectRecommendation;
use App\Models\EquipmentItem;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

class FireEquipmentStatsWidget extends BaseWidget
{
    protected static ?string $pollingInterval = '30s';
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        // Stock tracking disabled - stock_mutations table does not exist
        $lowStockCount = 0;
        $outOfStockCount = 0;
        
        return [
            Stat::make('Total Equipment Items', EquipmentItem::where('is_active', true)->count())
                ->icon('heroicon-o-cube')
                ->description('Active inventory items')
                ->url(EquipmentItemResource::getUrl('index'))
                ->color('info'),
            
            Stat::make('Low Stock Items', $lowStockCount)
                ->icon('heroicon-o-exclamation-triangle')
                ->description('Stock tracking disabled')
                ->color($lowStockCount > 0 ? 'warning' : 'success'),
            
            Stat::make('Out of Stock', $outOfStockCount)
                ->icon('heroicon-o-x-circle')
                ->description('Stock tracking disabled')
                ->color($outOfStockCount > 0 ? 'danger' : 'success'),
            
            Stat::make('Pending Recommendations', 
                ApparatusDefectRecommendation::where('status', 'pending')->count()
            )
                ->icon('heroicon-o-light-bulb')
                ->description('Awaiting allocation')
                ->url(RecommendationResource::getUrl('index'))
                ->color('warning'),
        ];
    }
}
