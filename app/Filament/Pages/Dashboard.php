<?php

namespace App\Filament\Pages;

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

    public function getWidgets(): array
    {
        return [
            StationOperationsHubWidget::class,
        ];
    }
}
