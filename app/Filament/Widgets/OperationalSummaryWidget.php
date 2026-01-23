<?php

namespace App\Filament\Widgets;

use App\Models\Apparatus;
use App\Models\EquipmentItem;
use App\Models\CapitalProject;
use App\Models\Todo;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class OperationalSummaryWidget extends BaseWidget
{
    protected static ?int $sort = 1;
    
    protected int | string | array $columnSpan = 'full';

    protected function getStats(): array
    {
        return [
            Stat::make('Total Apparatus', Apparatus::count())
                ->description('Active fleet vehicles')
                ->descriptionIcon('heroicon-o-truck')
                ->color('primary')
                ->chart([7, 6, 8, 7, 9, 8, 10]),
            
            Stat::make('Equipment Items', EquipmentItem::where('is_active', true)->count())
                ->description('Items in inventory')
                ->descriptionIcon('heroicon-o-cube')
                ->color('info')
                ->chart([42, 40, 45, 43, 47, 45, 48]),
            
            Stat::make('Active Capital Projects', CapitalProject::active()->count())
                ->description('In progress')
                ->descriptionIcon('heroicon-o-briefcase')
                ->color('success')
                ->chart([3, 4, 4, 5, 5, 6, 5]),
            
            Stat::make('Pending Todos', Todo::where('is_completed', false)->count())
                ->description('Action items')
                ->descriptionIcon('heroicon-o-clipboard-document-list')
                ->color('warning')
                ->chart([12, 15, 10, 13, 11, 9, 8]),
        ];
    }

    protected function getColumns(): int
    {
        return 4;
    }
}
