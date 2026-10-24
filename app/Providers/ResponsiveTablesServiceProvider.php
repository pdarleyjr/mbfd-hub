<?php

declare(strict_types=1);

namespace App\Providers;

use App\Filament\Pages\MasterPersonnelDirectory;
use App\Filament\Resources\UnitMasterVehicleResource\Pages\ListUnitMasterVehicles;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\ServiceProvider;

/**
 * Compact, sticky-column layout for the two widest admin tables: the Master Personnel Directory
 * and the Master Vehicle Inventory. The styles load only on those pages (render hook scopes), so
 * every other table keeps the standard Filament look. No admin theme file is touched.
 */
final class ResponsiveTablesServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        FilamentView::registerRenderHook(
            PanelsRenderHook::CONTENT_START,
            static fn (): \Illuminate\Contracts\View\View => view('filament.hooks.responsive-tables-styles'),
            scopes: [MasterPersonnelDirectory::class, ListUnitMasterVehicles::class],
        );
    }
}
