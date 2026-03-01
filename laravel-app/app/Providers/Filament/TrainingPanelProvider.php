<?php

namespace App\Providers\Filament;

use App\Filament\Training\Pages\Dashboard as TrainingDashboard;
use App\Filament\Training\Widgets\TrainingStatsWidget;
use App\Filament\Training\Widgets\TrainingTodoWidget;
use App\Filament\Pages\Auth\CustomLogin;
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

class TrainingPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('training')
            ->path('training')
            ->login(CustomLogin::class)
            ->brandName('MBFD Training Hub')
            ->brandLogo('/images/mbfd-logo.png')
            ->brandLogoHeight('2.5rem')
            ->favicon('/favicon.ico')
            ->colors([
                'primary' => Color::Amber,
                'danger' => Color::Rose,
                'gray' => Color::Slate,
                'info' => Color::Blue,
                'success' => Color::Green,
                'warning' => Color::Orange,
            ])
            ->font('Inter')
            ->darkMode(false)
            ->sidebarCollapsibleOnDesktop()
            ->discoverResources(in: app_path('Filament/Training/Resources'), for: 'App\\Filament\\Training\\Resources')
            ->discoverPages(in: app_path('Filament/Training/Pages'), for: 'App\\Filament\\Training\\Pages')
            ->pages([
                TrainingDashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Training/Widgets'), for: 'App\\Filament\\Training\\Widgets')
            ->widgets([
                // Header KPI row
                TrainingStatsWidget::class,
                
                // Main task queue
                TrainingTodoWidget::class,
                
                // Account widget in header
                Widgets\AccountWidget::class,
            ])
            ->navigationGroups([
                NavigationGroup::make('Dashboard')
                    ->label('Dashboard')
                    ->collapsible(false),
                NavigationGroup::make('Training Tasks')
                    ->label('Training Tasks')
                    ->collapsible(false),
                NavigationGroup::make('External Tools')
                    ->label('External Tools')
                    ->collapsible(true),
                NavigationGroup::make('Communication')
                    ->label('Communication')
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
