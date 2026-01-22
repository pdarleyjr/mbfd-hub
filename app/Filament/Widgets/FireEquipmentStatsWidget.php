<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\EquipmentItemResource;
use App\Filament\Resources\RecommendationResource;
use App\Models\ApparatusDefectRecommendation;
use App\Models\EquipmentItem;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class FireEquipmentStatsWidget extends BaseWidget
{
    protected static ?string $pollingInterval = '30s';
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        return [
            Stat::make('Total Equipment Items', EquipmentItem::where('is_active', true)->count())
                ->icon('heroicon-o-cube')
                ->description('Active inventory items')
                ->url(EquipmentItemResource::getUrl('index'))
                ->color('info'),
            
            Stat::make('Low Stock Items', 
                EquipmentItem::where('is_active', true)
                    ->whereColumn('stock', '<=', 'reorder_min')
                    ->count()
            )
                ->icon('heroicon-o-exclamation-triangle')
                ->description('Below reorder threshold')
                ->url(EquipmentItemResource::getUrl('index', ['tableFilters' => ['low_stock' => true]]))
                ->color(function ($state) {
                    return $state > 0 ? 'warning' : 'success';
                }),
            
            Stat::make('Out of Stock', 
                EquipmentItem::where('is_active', true)
                    ->where('stock', 0)
                    ->count()
            )
                ->icon('heroicon-o-x-circle')
                ->description('Zero stock items')
                ->color(function ($state) {
                    return $state > 0 ? 'danger' : 'success';
                }),
            
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
