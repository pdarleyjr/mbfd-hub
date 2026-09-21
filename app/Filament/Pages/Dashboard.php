<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\FleetStatsWidget;
use App\Filament\Widgets\InventoryOverviewWidget;
use App\Filament\Widgets\StationOperationsHubWidget;
use Filament\Pages\Dashboard as BaseDashboard;

class Dashboard extends BaseDashboard
{
    protected static ?string $navigationIcon = 'heroicon-o-rectangle-group';

    protected static ?string $title = 'Station Operations';

    public function getSubheading(): ?string
    {
        return 'Department readiness, station work, and the next place to act.';
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
