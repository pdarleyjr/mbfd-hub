<?php

namespace App\Filament\Widgets;

use App\Models\CapitalProject;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class ProjectStatsOverviewWidget extends BaseWidget
{
    protected static ?int $sort = 1;

    protected int | string | array $columnSpan = [
        'sm' => 1,
        'md' => 2,
        'xl' => 2,
    ];

    public function getPollingInterval(): ?string
    {
        return '60s';
    }

    protected function getStats(): array
    {
        // High Priority Projects Count
        $highPriorityCount = CapitalProject::query()
            ->whereIn('priority', ['high', 'critical'])
            ->whereNull('actual_completion')
            ->count();

        // Overdue Projects Count
        $overdueCount = CapitalProject::query()
            ->whereNull('actual_completion')
            ->where('target_completion_date', '<', now())
            ->count();

        // Total Active Budget
        $activeBudget = CapitalProject::query()
            ->whereNull('actual_completion')
            ->sum('budget_amount');

        // Completion Rate
        $totalProjects = CapitalProject::count();
        $completedProjects = CapitalProject::whereNotNull('actual_completion')->count();
        $completionRate = $totalProjects > 0 ? round(($completedProjects / $totalProjects) * 100, 1) : 0;

        // Trend data for high priority (last 7 days)
        $highPriorityTrend = $this->getHighPriorityTrend();

        return [
            Stat::make('High Priority Projects', $highPriorityCount)
                ->description('Require immediate attention')
                ->descriptionIcon('heroicon-o-exclamation-triangle')
                ->color('danger')
                ->chart($highPriorityTrend)
                ->url(route('filament.admin.resources.capital-projects.index', [
                    'tableFilters' => ['priority' => ['values' => ['high', 'critical']]]
                ])),

            Stat::make('Overdue Projects', $overdueCount)
                ->description('Past deadline')
                ->descriptionIcon('heroicon-o-clock')
                ->color('warning')
                ->url(route('filament.admin.resources.capital-projects.index')),

            Stat::make('Total Active Budget', '$' . number_format($activeBudget, 0))
                ->description('All active projects')
                ->descriptionIcon('heroicon-o-banknotes')
                ->color('success')
                ->url(route('filament.admin.resources.capital-projects.index')),

            Stat::make('Completion Rate', $completionRate . '%')
                ->description('Overall progress')
                ->descriptionIcon('heroicon-o-chart-bar')
                ->color('info')
                ->url(route('filament.admin.resources.capital-projects.index')),
        ];
    }

    protected function getHighPriorityTrend(): array
    {
        $trend = [];
        
        for ($i = 6; $i >= 0; $i--) {
            $date = now()->subDays($i)->startOfDay();
            
            $count = CapitalProject::query()
                ->whereIn('priority', ['high', 'critical'])
                ->whereNull('actual_completion')
                ->where('created_at', '<=', $date->endOfDay())
                ->count();
                
            $trend[] = $count;
        }
        
        return $trend;
    }
}
