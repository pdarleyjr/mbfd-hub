<?php

namespace App\Providers\Filament;

use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\MenuItem;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Filament\Pages;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\MaxWidth;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\Facades\Blade;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use App\Http\Middleware\EnsureTrainingPanelAccess;
use App\Filament\Training\Support\DynamicNavigation;
use App\Filament\Training\Pages\Settings as TrainingSettings;
use Monzer\FilamentChatifyIntegration\ChatifyPlugin;
use App\Filament\Pages\Auth\Login;

class TrainingPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('training')
            ->path('training')
            ->homeUrl('/')
            ->login(Login::class)
            ->brandName('MBFD Training Division')
            ->brandLogo(secure_asset('images/mbfd_no_bg_new.png'))
            ->brandLogoHeight('3rem')
            ->darkModeBrandLogo(secure_asset('images/mbfd_no_bg_new.png'))
            ->favicon(secure_asset('favicon.ico'))
            ->darkMode(false)
            ->colors([
                'primary' => Color::Red,
                'danger' => Color::Rose,
                'gray' => Color::Slate,
                'info' => Color::Sky,
                'success' => Color::Emerald,
                'warning' => Color::Orange,
            ])
            ->font('Plus Jakarta Sans')
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->plugin(ChatifyPlugin::make())
            ->discoverResources(in: app_path('Filament/Training/Resources'), for: 'App\\Filament\\Training\\Resources')
            ->discoverPages(in: app_path('Filament/Training/Pages'), for: 'App\\Filament\\Training\\Pages')
            ->discoverWidgets(in: app_path('Filament/Training/Widgets'), for: 'App\\Filament\\Training\\Widgets')
            ->widgets([
                \App\Filament\Training\Widgets\TrainingStatsWidget::class,
                \App\Filament\Training\Widgets\TrainingTodoWidget::class,
            ])
            ->pages([
                \App\Filament\Training\Pages\Dashboard::class,
                \App\Filament\Training\Pages\ExternalNavItemViewer::class,
            ])
            ->navigationGroups([
                NavigationGroup::make()
                    ->label('Dashboard')
                    ->icon('heroicon-o-academic-cap')
                    ->collapsible(false),
                NavigationGroup::make()
                    ->label('Training Tasks')
                    ->icon('heroicon-o-clipboard-document-list')
                    ->collapsible(false),
                NavigationGroup::make()
                    ->label('External Tools')
                    ->icon('heroicon-o-globe-alt')
                    ->collapsible(false),
                NavigationGroup::make()
                    ->label('Communication')
                    ->icon('heroicon-o-chat-bubble-left-right')
                    ->collapsible(false),
            ])
            ->userMenuItems([
                MenuItem::make()
                    ->label('Return to Home')
                    ->icon('heroicon-o-home')
                    ->url('/'),
                MenuItem::make()
                    ->label('Settings')
                    ->url(fn (): string => TrainingSettings::getUrl())
                    ->icon('heroicon-o-cog-6-tooth'),
            ])
            ->databaseNotifications()
            ->databaseNotificationsPolling('30s')
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
                    ->url('https://baserow.mbfdhub.com', shouldOpenInNewTab: true)
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->group('External Tools')
                    ->sort(99),
            ])
            ->renderHook(
                PanelsRenderHook::GLOBAL_SEARCH_BEFORE,
                fn (): string => '<a href="/" class="flex items-center justify-center w-10 h-10 rounded-lg text-gray-500 hover:text-primary-500 hover:bg-gray-100 transition" title="Return to Home" aria-label="Return to Home"><svg class="w-5 h-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m2.25 12 8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25" /></svg></a>'
            );
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
