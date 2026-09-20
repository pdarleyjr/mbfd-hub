<?php

namespace App\Filament\Pages;

use Filament\Pages\Dashboard as BaseDashboard;
use App\Filament\Widgets\FleetStatsWidget;
use App\Filament\Widgets\InventoryOverviewWidget;
use App\Filament\Widgets\StationOperationsHubWidget;

class Dashboard extends BaseDashboard
{
    protected static ?string $navigationIcon = 'heroicon-o-rectangle-group';

    protected static ?string $title = 'Dashboard';

    public function getSubheading(): ?string
    {
        return 'Operational exceptions and the next place to act.';
    }

    public function getColumns(): int|string|array
    {
        return [
            'sm' => 1,
            'md' => 2,
            'xl' => 3,
        ];
    }

    public function getWidgets(): array
    {
        return [
            FleetStatsWidget::class,
            InventoryOverviewWidget::class,
            StationOperationsHubWidget::class,
        ];
    }
}
