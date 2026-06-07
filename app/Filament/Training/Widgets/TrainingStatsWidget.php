<?php

namespace App\Filament\Training\Widgets;

use App\Models\Training\TrainingTodo;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class TrainingStatsWidget extends BaseWidget
{
    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 'full';

    protected function getStats(): array
    {
        $openTodos = TrainingTodo::where('is_completed', false)->count();
        $staleCount = TrainingTodo::where('is_completed', false)
            ->where('created_at', '<', now()->subDays(30))
            ->count();
        $recentlyUpdated = TrainingTodo::where('updated_at', '>=', now()->subDays(7))->count();

        return [
            Stat::make('Open Training Todos', $openTodos)
                ->description($openTodos > 0 ? "{$openTodos} tasks pending" : 'All clear')
                ->descriptionIcon('heroicon-m-clipboard-document-list')
                ->color($openTodos > 10 ? 'warning' : 'primary'),

            Stat::make('Stale Items', $staleCount)
                ->description($staleCount > 0 ? 'Open > 30 days' : 'On track')
                ->descriptionIcon($staleCount > 0 ? 'heroicon-m-exclamation-triangle' : 'heroicon-m-check-circle')
                ->color($staleCount > 0 ? 'danger' : 'success'),

            Stat::make('Recently Updated', $recentlyUpdated)
                ->description('Last 7 days')
                ->descriptionIcon('heroicon-m-arrow-path')
                ->color('info'),
        ];
    }
}
