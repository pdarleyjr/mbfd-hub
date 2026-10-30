<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Pages\Concerns\RendersOperationsDashboard;
use App\Services\Dashboards\DashboardAccess;
use App\Support\Dashboards\DashboardView;
use Filament\Pages\Page;
use Filament\Support\Enums\MaxWidth;
use Illuminate\Contracts\View\View;

/** Dashboard 2 of 3: daily apparatus checkout exceptions, repairs and readiness updates. */
class VehicleCheckoutDashboard extends Page
{
    use RendersOperationsDashboard;

    protected static string $view = 'filament.dashboards.pages.board';

    protected static ?string $slug = 'dashboards/vehicle-checkout';

    protected static ?string $navigationLabel = 'Vehicle Checkout';

    protected static ?int $navigationSort = 11;

    protected static ?string $navigationGroup = 'Operations';

    protected static ?string $navigationIcon = 'heroicon-o-truck';

    protected static ?string $title = 'Vehicle Checkout Dashboard';

    protected ?string $maxContentWidth = MaxWidth::Full->value;

    public static function dashboardView(): DashboardView
    {
        return DashboardView::VehicleCheckout;
    }

    public static function canAccess(): bool
    {
        return DashboardAccess::canViewOperationsBoards(auth()->user());
    }

    public function getSubheading(): ?string
    {
        return 'Daily apparatus exceptions, missing items, repairs and readiness updates.';
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
