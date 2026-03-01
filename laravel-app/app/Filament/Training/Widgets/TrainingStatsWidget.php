<?php

namespace App\Filament\Training\Widgets;

use App\Models\Todo;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class TrainingStatsWidget extends BaseWidget
{
    protected function getStats(): array
    {
        // For training-specific todos - assuming there's a category or tag
        $openTodos = Todo::where('status', '!=', 'completed')->count();
        
        $overdueTodos = Todo::where('status', '!=', 'completed')
            ->whereNotNull('due_date')
            ->where('due_date', '<', now())
            ->count();
            
        // Upcoming sessions (todos due within 7 days)
        $upcomingTodos = Todo::where('status', '!=', 'completed')
            ->whereNotNull('due_date')
            ->whereBetween('due_date', [now(), now()->addDays(7)])
            ->count();
            
        // External sources count - placeholder for now
        $externalSources = 3; // Would come from config or database
        
        // Recently updated - placeholder
        $recentlyUpdated = 5;

        return [
            Stat::make('Open Training Todos', $openTodos)
                ->description('Active training tasks')
                ->descriptionIcon('heroicon-o-clipboard-document-list')
                ->color('primary'),
                
            Stat::make('Overdue Items', $overdueTodos)
                ->description('Past due date')
                ->descriptionIcon('heroicon-o-clock')
                ->color($overdueTodos > 0 ? 'danger' : 'success'),
                
            Stat::make('Upcoming Sessions', $upcomingTodos)
                ->description('Due within 7 days')
                ->descriptionIcon('heroicon-o-calendar')
                ->color($upcomingTodos > 2 ? 'warning' : 'success'),
                
            Stat::make('External Sources', $externalSources)
                ->description('Linked training tools')
                ->descriptionIcon('heroicon-o-globe-alt')
                ->color('info'),
                
            Stat::make('Recently Updated', $recentlyUpdated)
                ->description('Updated this week')
                ->descriptionIcon('heroicon-o-arrow-path')
                ->color('success'),
        ];
    }
}
