<?php

namespace App\Filament\Widgets;

use App\Models\Apparatus;
use App\Models\ApparatusDefect;
use App\Models\ApparatusInspection;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class StatsOverviewWidget extends BaseWidget
{
    protected function getStats(): array
    {
        $today = now()->startOfDay();
        $todayEnd = now()->endOfDay();

        return [
            Stat::make('Total Apparatuses', Apparatus::count())
                ->description('Fleet size')
                ->descriptionIcon('heroicon-o-truck')
                ->color('primary'),

            Stat::make('Open Defects', ApparatusDefect::where('status', '!=', 'resolved')->count())
                ->description('Requires attention')
                ->descriptionIcon('heroicon-o-exclamation-triangle')
                ->color('danger'),

            Stat::make('Inspections Today', ApparatusInspection::whereBetween('inspection_date', [$today, $todayEnd])->count())
                ->description('Completed today')
                ->descriptionIcon('heroicon-o-clipboard-document-check')
                ->color('success'),

            Stat::make('Overdue Inspections', $this->getOverdueInspectionsCount())
                ->description('Need inspection')
                ->descriptionIcon('heroicon-o-clock')
                ->color('warning'),
        ];
    }

    protected function getOverdueInspectionsCount(): int
    {
        // Get all apparatuses
        $apparatuses = Apparatus::all();
        $overdueCount = 0;

        foreach ($apparatuses as $apparatus) {
            // Get the latest inspection for this apparatus
            $latestInspection = ApparatusInspection::where('apparatus_id', $apparatus->id)
                ->orderBy('inspection_date', 'desc')
                ->first();

            // If no inspection exists or last inspection was more than 24 hours ago
            if (!$latestInspection || $latestInspection->inspection_date < now()->subDay()) {
                $overdueCount++;
            }
        }

        return $overdueCount;
    }
}
