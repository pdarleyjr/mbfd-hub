<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Widgets\EmployeeMessagesWidget;
use App\Filament\Widgets\EquipmentChangeRequestsWidget;
use App\Filament\Widgets\StationOperationsHubWidget;
use App\Services\Dashboards\DashboardAccess;
use Filament\Actions\Action;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Support\Enums\MaxWidth;

/**
 * The logistics widget board that was the admin home before the three linked dashboards:
 * Station Operations board, Support Services messages and equipment change requests, with the
 * same Display Mode entry to the wall screen (/admin?display=1).
 */
class LogisticsBoard extends BaseDashboard
{
    protected static string $routePath = '/logistics-board';

    protected static ?string $slug = 'logistics-board';

    protected static ?string $navigationLabel = 'Logistics Board';

    protected static ?int $navigationSort = 13;

    protected static ?string $navigationGroup = 'Operations';

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $title = 'Logistics Board';

    protected ?string $maxContentWidth = MaxWidth::Full->value;

    public static function canAccess(): bool
    {
        return DashboardAccess::canViewOperationsBoards(auth()->user());
    }

    public function getSubheading(): ?string
    {
        return 'Department-wide checkouts, equipment issues and station activity for the active operational day.';
    }

    public function getWidgets(): array
    {
        return [
            StationOperationsHubWidget::class,
            EmployeeMessagesWidget::class,
            EquipmentChangeRequestsWidget::class,
        ];
    }

    /** @return array<Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('displayMode')
                ->label('Display Mode')
                ->icon('heroicon-o-tv')
                ->url(Dashboard::getUrl(['display' => 1], panel: 'admin')),
        ];
    }
}
