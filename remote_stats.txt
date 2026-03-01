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
            ->extraAttributes(['class' => 'stat-card-total']),

            Stat::make('Open Defects', ApparatusDefect::where('resolved', false)->count())
            ->description('Requires attention')
            ->descriptionIcon('heroicon-o-exclamation-triangle')
            ->extraAttributes(['class' => 'stat-card-danger']),

            Stat::make('Inspections Today', ApparatusInspection::whereBetween('completed_at', [$today, $todayEnd])->count())
            ->description('Completed today')
            ->descriptionIcon('heroicon-o-clipboard-document-check')
            ->extraAttributes(['class' => 'stat-card-success']),

            Stat::make('Overdue Inspections', $this->getOverdueInspectionsCount())
            ->description('Need inspection')
            ->descriptionIcon('heroicon-o-clock')
            ->extraAttributes(['class' => 'stat-card-warning']),
        ];
    }

    protected function getOverdueInspectionsCount(): int
    {
        $apparatuses = Apparatus::all();
        $overdueCount = 0;

        foreach ($apparatuses as $apparatus) {
            $latestInspection = ApparatusInspection::where('apparatus_id', $apparatus->id)
                ->orderBy('completed_at', 'desc')
                ->first();

            if (!$latestInspection || $latestInspection->completed_at < now()->subDay()) {
                $overdueCount++;
            }
        }

        return $overdueCount;
    }
}