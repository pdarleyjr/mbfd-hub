<?php

namespace App\Filament\Pages;

use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Actions\Action;
use Filament\Support\Enums\MaxWidth;
use App\Filament\Widgets\FleetStatsWidget;
use App\Filament\Widgets\InventoryOverviewWidget;
use App\Filament\Widgets\TodoOverviewWidget;
use App\Filament\Widgets\SmartUpdatesWidget;

class Dashboard extends BaseDashboard
{
    protected static ?string $navigationIcon = 'heroicon-o-rectangle-group';

    protected static ?string $title = 'Dashboard';

    protected ?string $subheading = 'Operational overview for fleet, logistics, inventory, and active support-service tasks.';

    public function getMaxContentWidth(): MaxWidth|string|null
    {
        return MaxWidth::ScreenTwoExtraLarge;
    }

    public function getColumns(): int|string|array
    {
        return [
            'sm' => 1,
            'md' => 2,
            'xl' => 3,
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('newTodo')
                ->label('New Todo')
                ->icon('heroicon-o-plus-circle')
                ->color('primary')
                ->url(route('filament.admin.resources.todos.create')),
            Action::make('askAI')
                ->label('Ask AI Assistant')
                ->icon('heroicon-o-sparkles')
                ->color('gray')
                ->action(function () {
                    $this->dispatch('open-ai-chat');
                }),
            Action::make('filterDashboard')
                ->label('Filter Dashboard')
                ->icon('heroicon-o-funnel')
                ->color('gray')
                ->url(route('filament.admin.resources.todos.index')),
        ];
    }

    public function getHeaderWidgets(): array
    {
        return [
            FleetStatsWidget::class,
            InventoryOverviewWidget::class,
        ];
    }

    public function getFooterWidgets(): array
    {
        return [
            SmartUpdatesWidget::class,
        ];
    }

    public function getWidgets(): array
    {
        return [
            TodoOverviewWidget::class,
        ];
    }

    public function getHeaderWidgetsColumns(): int|string|array
    {
        return [
            'sm' => 1,
            'md' => 2,
            'xl' => 2,
        ];
    }

    public function getFooterWidgetsColumns(): int|string|array
    {
        return 1;
    }
}
