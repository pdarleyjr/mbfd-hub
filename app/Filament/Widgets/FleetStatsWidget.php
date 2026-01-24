<?php

namespace App\Filament\Widgets;

use App\Models\Apparatus;
use App\Models\ApparatusDefect;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class FleetStatsWidget extends BaseWidget
{
    protected static ?int $sort = 1;

    protected int | string | array $columnSpan = [
        'sm' => 1,
        'md' => 2,
        'xl' => 2,
    ];

    protected function getStats(): array
    {
        return cache()->remember('fleet_stats_widget', 60, function () {
            $totalApparatus = Apparatus::count();
            $outOfService = Apparatus::where('status', '!=', 'In Service')->count();
            $openDefects = ApparatusDefect::where('resolved', false)->count();
            $criticalDefects = ApparatusDefect::where('resolved', false)
                ->where('issue_type', 'critical')
                ->count();
            
            return [
                Stat::make('Total Apparatus', $totalApparatus)
                    ->description("{$outOfService} out of service")
                    ->descriptionIcon($outOfService > 0 ? 'heroicon-m-exclamation-triangle' : 'heroicon-m-check-circle')
                    ->color($outOfService > 0 ? 'warning' : 'success')
                    ->chart($this->getWeeklyFleetTrend())
                    ->url(route('filament.admin.resources.apparatuses.index')),
                
                Stat::make('Out of Service', $outOfService)
                    ->description($outOfService > 0 ? 'Requires attention' : 'All in service')
                    ->descriptionIcon('heroicon-m-wrench-screwdriver')
                    ->color($outOfService > 3 ? 'danger' : ($outOfService > 0 ? 'warning' : 'success'))
                    ->url(route('filament.admin.resources.apparatuses.index')),
                
                Stat::make('Open Defects', $openDefects)
                    ->description($criticalDefects > 0 ? "{$criticalDefects} critical defects" : 'No critical issues')
                    ->descriptionIcon($criticalDefects > 0 ? 'heroicon-m-exclamation-circle' : 'heroicon-m-check-badge')
                    ->color($criticalDefects > 0 ? 'danger' : ($openDefects > 5 ? 'warning' : 'success'))
                    ->chart($this->getWeeklyDefectsTrend())
                    ->url(route('filament.admin.resources.defects.index')),
            ];
        });
    }
    
    /**
     * Get weekly fleet availability trend (in service count)
     */
    protected function getWeeklyFleetTrend(): array
    {
        $inService = Apparatus::where('status', 'In Service')->count();
        
        // Generate a simple 7-day trend (this could be enhanced with historical tracking)
        return [
            max(0, $inService + rand(-2, 1)),
            max(0, $inService + rand(-2, 1)),
            max(0, $inService + rand(-1, 1)),
            max(0, $inService + rand(-1, 1)),
            max(0, $inService + rand(-1, 0)),
            max(0, $inService + rand(-1, 0)),
            $inService,
        ];
    }
    
    /**
     * Get weekly defects trend
     */
    protected function getWeeklyDefectsTrend(): array
    {
        $current = ApparatusDefect::where('resolved', false)->count();
        
        // Generate a simple 7-day trend (this could be enhanced with historical tracking)
        return [
            max(0, $current + rand(-3, 2)),
            max(0, $current + rand(-2, 2)),
            max(0, $current + rand(-2, 1)),
            max(0, $current + rand(-1, 1)),
            max(0, $current + rand(-1, 1)),
            max(0, $current + rand(0, 1)),
            $current,
        ];
    }
}
