<?php

namespace App\Providers\Filament;

use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Illuminate\Support\Facades\Blade;
use App\Filament\Widgets\FleetStatsWidget;
use App\Filament\Widgets\InventoryOverviewWidget;
use App\Filament\Widgets\TodoOverviewWidget;
use App\Filament\Widgets\SmartUpdatesWidget;
// use BezhanSalleh\FilamentShield\FilamentShieldPlugin;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->brandName('MBFD Support Hub')
            ->brandLogo(asset('images/large_mbfd_logo_no_bg.png'))
            ->brandLogoHeight('3rem')
            ->darkModeBrandLogo(asset('images/large_mbfd_logo_no_bg.png'))
            ->favicon(asset('images/small_mbfd_logo_no_bg.png'))
            ->colors([
                'primary' => Color::Red,
                'danger' => Color::Rose,
                'gray' => Color::Slate,
                'info' => Color::Blue,
                'success' => Color::Green,
                'warning' => Color::Amber,
            ])
            ->font('Inter')
            // ->plugin(FilamentShieldPlugin::make())
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                Pages\Dashboard::class,
            ])
            ->widgets([
                // Consolidated dashboard: Fleet + Inventory stats, Todo overview, AI Smart Updates
                FleetStatsWidget::class,              // Fleet metrics: total apparatus, out of service, open defects
                InventoryOverviewWidget::class,       // Inventory metrics: low stock items, total items, stock health
                TodoOverviewWidget::class,            // Active todo items table
                SmartUpdatesWidget::class,            // AI assistant with instant bullet summary
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ])
            ->databaseNotifications()
            ->databaseNotificationsPolling('30s')
            ->sidebarCollapsibleOnDesktop()
            ->renderHook(
                PanelsRenderHook::BODY_END,
                fn (): string => Blade::render('<script src="{{ asset(\'js/filament-shortcuts.js\') }}" defer></script>')
            );
    }
}
