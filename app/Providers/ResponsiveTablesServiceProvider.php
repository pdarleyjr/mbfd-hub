<?php

declare(strict_types=1);

namespace App\Providers;

use App\Filament\Pages\MasterPersonnelDirectory;
use App\Filament\Resources\UnitMasterVehicleResource\Pages\ListUnitMasterVehicles;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\ServiceProvider;

/**
 * Responsive tables for every Filament panel. A shared script (resources/js/hub-tables.js) keeps tables
 * from scrolling sideways by moving lower-priority columns into a row details panel and stacking rows as
 * cards on phones. The Master Personnel Directory and Master Vehicle Inventory also get tighter cell spacing.
 */
final class ResponsiveTablesServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        FilamentView::registerRenderHook(
            PanelsRenderHook::BODY_END,
            static fn (): \Illuminate\Contracts\View\View => view('filament.hooks.hub-tables-script'),
        );

        FilamentView::registerRenderHook(
            PanelsRenderHook::CONTENT_START,
            static fn (): \Illuminate\Contracts\View\View => view('filament.hooks.responsive-tables-styles'),
            scopes: [MasterPersonnelDirectory::class, ListUnitMasterVehicles::class],
        );
    }
}
