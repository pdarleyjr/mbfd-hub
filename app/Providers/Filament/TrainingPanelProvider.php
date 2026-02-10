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
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use App\Http\Middleware\EnsureTrainingPanelAccess;
use App\Filament\Training\Support\DynamicNavigation;
use App\Filament\Widgets\PushNotificationWidget;
use Filament\Navigation\NavigationItem;
use Monzer\FilamentChatifyIntegration\ChatifyPlugin;
use App\Filament\Pages\Auth\Login;

class TrainingPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('training')
            ->path('training')
            ->login(Login::class)
            ->brandName('MBFD Training Division')
            ->brandLogo(secure_asset('images/mbfd_no_bg_new.png'))
            ->brandLogoHeight('3rem')
            ->darkModeBrandLogo(secure_asset('images/mbfd_no_bg_new.png'))
            ->favicon(secure_asset('favicon.ico'))
            ->colors([
                'primary' => Color::Amber,
                'danger' => Color::Rose,
                'gray' => Color::Zinc,
                'info' => Color::Sky,
                'success' => Color::Emerald,
                'warning' => Color::Orange,
            ])
            ->font('Inter')
            ->plugin(ChatifyPlugin::make())
            ->discoverResources(in: app_path('Filament/Training/Resources'), for: 'App\\Filament\\Training\\Resources')
            ->discoverPages(in: app_path('Filament/Training/Pages'), for: 'App\\Filament\\Training\\Pages')
            ->discoverWidgets(in: app_path('Filament/Training/Widgets'), for: 'App\\Filament\\Training\\Widgets')
            ->widgets([
                PushNotificationWidget::class,  // Push notification subscription management
            ])
            ->pages([
                \App\Filament\Training\Pages\Dashboard::class,
                \App\Filament\Training\Pages\ExternalNavItemViewer::class,
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
                EnsureTrainingPanelAccess::class,
            ])
            ->sidebarCollapsibleOnDesktop()
            ->navigationItems([
                NavigationItem::make('Baserow')
                    ->url('https://baserow.support.darleyplex.com', shouldOpenInNewTab: true)
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->group('External Tools')
                    ->sort(99),
            ]);
    }

    public function boot(): void
    {
        filament()->serving(function () {
            if (filament()->getCurrentPanel()?->getId() === 'training') {
                foreach (DynamicNavigation::getNavigationItems() as $item) {
                    filament()->getCurrentPanel()->navigationItems([$item]);
                }
            }
        });
    }
}
