<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Pages\Concerns\RendersOperationsDashboard;
use App\Services\Dashboards\DashboardAccess;
use App\Support\Dashboards\DashboardView;
use Filament\Pages\Page;
use Filament\Support\Enums\MaxWidth;
use Illuminate\Contracts\View\View;

/** Dashboard 3 of 3: facility issues, station requests and work-order status across stations. */
class StationOperationsDashboard extends Page
{
    use RendersOperationsDashboard;

    protected static string $view = 'filament.dashboards.pages.board';

    protected static ?string $slug = 'dashboards/station-operations';

    protected static ?string $navigationLabel = 'Station Operations';

    protected static ?int $navigationSort = 12;

    protected static ?string $navigationGroup = 'Operations';

    protected static ?string $navigationIcon = 'heroicon-o-building-office-2';

    protected static ?string $title = 'Station Operations Dashboard';

    protected ?string $maxContentWidth = MaxWidth::Full->value;

    public static function dashboardView(): DashboardView
    {
        return DashboardView::StationOperations;
    }

    public static function canAccess(): bool
    {
        return DashboardAccess::canViewOperationsBoards(auth()->user());
    }

    public function getSubheading(): ?string
    {
        return 'Facility issues, station requests and work-order status across MBFD stations.';
    }

    public function getHeader(): ?View
    {
        return $this->dashboardHeader();
    }

    /** @return array<string, string> */
    public function getExtraBodyAttributes(): array
    {
        return $this->dashboardBodyAttributes();
    }
}
