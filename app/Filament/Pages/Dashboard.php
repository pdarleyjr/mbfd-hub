<?php

namespace App\Filament\Pages;

use Filament\Pages\Dashboard as BaseDashboard;

class Dashboard extends BaseDashboard
{
    // Force 2-column grid layout
    public function getColumns(): int | string | array
    {
        return 2;
    }

    // Explicitly define which widgets to show
    public function getVisibleWidgets(): array
    {
        return [
            \App\Filament\Widgets\OperationalSummaryWidget::class,
            \App\Filament\Widgets\InventorySuppliesWidget::class,
            \App\Filament\Widgets\MaintenanceStatsWidget::class,
            \App\Filament\Widgets\SmartUpdatesWidget::class,
        ];
    }
}
