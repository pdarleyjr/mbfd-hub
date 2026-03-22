<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Admin\Dashboard;
use App\Filament\Pages\Auth\CustomLogin;
use App\Filament\Widgets\Admin\AdminStatsWidget;
use App\Filament\Widgets\Admin\OperationalAlertsWidget;
use App\Filament\Widgets\Admin\FleetSnapshotWidget;
use App\Filament\Widgets\Admin\LowStockWatchlistWidget;
use App\Filament\Widgets\Admin\AiAssistantWidget;
use App\Filament\Widgets\SmartUpdatesWidget;
use App\Filament\Widgets\TodoOverviewWidget;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Navigation\NavigationGroup;
use Filament\Widgets;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login(CustomLogin::class)
            ->brandName('MBFD Support Hub')
            ->brandLogo('/images/mbfd-logo.png')
            ->brandLogoHeight('2.5rem')
            ->favicon('/favicon.ico')
            ->colors([
                'primary' => Color::Slate,
                'danger' => Color::Rose,
                'gray' => Color::Slate,
                'info' => Color::Blue,
                'success' => Color::Green,
                'warning' => Color::Amber,
            ])
            ->font('Inter')
            ->darkMode(false)
            ->sidebarCollapsibleOnDesktop()
            ->sidebarFullyCollapsibleOnDesktop()
            ->sidebarWidth('18rem')
            ->maxContentWidth('7xl')
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->widgets([
                // Header KPI row - stats overview
                AdminStatsWidget::class,
                
                // Main todo queue
                TodoOverviewWidget::class,
                
                // Secondary zone widgets
                OperationalAlertsWidget::class,
                FleetSnapshotWidget::class,
                LowStockWatchlistWidget::class,
                AiAssistantWidget::class,
                
                // Keep SmartUpdatesWidget for detailed view
                SmartUpdatesWidget::class,
                
                // Account widget in header
                Widgets\AccountWidget::class,
            ])
            ->navigationGroups([
                NavigationGroup::make('Dashboard')
                    ->label('Dashboard')
                    ->collapsible(false),
                NavigationGroup::make('Active Operations')
                    ->label('Active Operations')
                    ->collapsible(true),
                NavigationGroup::make('Fleet Management')
                    ->label('Fleet Management')
                    ->collapsible(true),
                NavigationGroup::make('Inventory & Logistics')
                    ->label('Inventory & Logistics')
                    ->collapsible(true),
                NavigationGroup::make('Administration')
                    ->label('Administration')
                    ->collapsible(true),
                NavigationGroup::make('Communication')
                    ->label('Communication & AI')
                    ->collapsible(true),
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
            ->spa();
    }
}
