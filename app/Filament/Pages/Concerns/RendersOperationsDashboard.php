<?php

declare(strict_types=1);

namespace App\Filament\Pages\Concerns;

use App\Support\Dashboards\DashboardView;
use App\Support\OperationalDisplayWindow;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;

/**
 * Shared shell for the three linked admin dashboards: page-scoped body class for the fit-to-viewport
 * layout (resources/css/filament/admin/dashboards.css) and the heading with today's date + greeting.
 */
trait RendersOperationsDashboard
{
    abstract public static function dashboardView(): DashboardView;

    /** @return array<string, string> */
    protected function dashboardBodyAttributes(): array
    {
        return ['class' => 'mbfd-dash-fit'];
    }

    protected function dashboardHeader(): View
    {
        $now = CarbonImmutable::now(OperationalDisplayWindow::TIMEZONE);
        $first = trim((string) strtok((string) (auth()->user()->name ?? ''), ' '));

        return view('filament.dashboards.header', [
            'heading' => $this->getHeading(),
            'subheading' => $this->getSubheading(),
            'date' => $now->format('l, F j, Y'),
            'greeting' => match (true) {
                $now->hour < 12 => 'Good morning',
                $now->hour < 17 => 'Good afternoon',
                default => 'Good evening',
            }.($first !== '' ? ', '.$first : ''),
            'actions' => $this->getCachedHeaderActions(),
        ]);
    }
}
