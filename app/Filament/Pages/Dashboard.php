<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Concerns\RendersOperationsDashboard;
use App\Filament\Widgets\StationOperationsHubWidget;
use App\Support\Dashboards\DashboardView;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Support\Enums\MaxWidth;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;

/**
 * Admin home (/admin). Normal mode: the Admin Overview (dashboard 1 of 3). Display mode
 * (/admin?display=1, wall screens): exactly the Station Operations board widget, unchanged.
 * The full logistics widget page that used to live here is LogisticsBoard (/admin/logistics-board).
 */
class Dashboard extends BaseDashboard
{
    use RendersOperationsDashboard;

    protected static string $view = 'filament.dashboards.pages.overview';

    protected static ?string $navigationLabel = 'Admin Overview';

    protected static ?int $navigationSort = 10;

    protected static ?string $navigationGroup = 'Operations';

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-group';

    protected static ?string $title = 'Admin Overview';

    protected ?string $maxContentWidth = MaxWidth::Full->value;

    /** Captured on the first request: later Livewire requests to this page do not carry ?display=1. */
    #[Locked]
    public bool $displayMode = false;

    public static function dashboardView(): DashboardView
    {
        return DashboardView::Overview;
    }

    public function mount(): void
    {
        $this->displayMode = request()->boolean('display');
    }

    public function getTitle(): string|Htmlable
    {
        return $this->isDisplayMode() ? 'Logistics Dashboard' : 'Admin Overview';
    }

    public function getSubheading(): ?string
    {
        return $this->isDisplayMode()
            ? 'Department-wide checkouts, equipment issues and station activity for the active operational day.'
            : 'Today’s open items across daily checkouts, station requests and Support Services.';
    }

    public function getHeader(): ?View
    {
        return $this->isDisplayMode() ? null : $this->dashboardHeader();
    }

    /**
     * Only display mode renders widgets (the wall screen). The Admin Overview is not a widget page;
     * the three logistics widgets are on LogisticsBoard.
     */
    public function getWidgets(): array
    {
        // Employee messages and equipment change requests name members: never on the wall display.
        return $this->isDisplayMode() ? [StationOperationsHubWidget::class] : [];
    }

    /** @return array<string, string> */
    public function getExtraBodyAttributes(): array
    {
        return request()->boolean('display') ? ['class' => 'mbfd-command-display'] : $this->dashboardBodyAttributes();
    }

    public function isDisplayMode(): bool
    {
        return $this->displayMode || request()->boolean('display');
    }
}
