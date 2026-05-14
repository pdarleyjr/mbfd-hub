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
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use App\Filament\Pages\Auth\Login;
use App\Filament\Pages\NotificationSettings;
use App\Filament\Pages\Settings;
use App\Http\Middleware\RedirectTrainingUsers;
use App\Filament\Admin\Pages\EquipmentIntake;
use App\Filament\Admin\Pages\TrtTrailerInventory;
use App\Filament\Widgets\FleetStatsWidget;
use App\Filament\Widgets\InventoryOverviewWidget;
use App\Filament\Widgets\StationOperationsHubWidget;
use App\Filament\Widgets\SmartUpdatesWidget;
use BezhanSalleh\FilamentShield\FilamentShieldPlugin;
use pxlrbt\FilamentSpotlight\SpotlightPlugin;
use ShuvroRoy\FilamentSpatieLaravelHealth\FilamentSpatieLaravelHealthPlugin;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login(Login::class)
            ->brandName('MBFD Support Hub')
            ->brandLogo(secure_asset('images/mbfd_no_bg_new.png'))
            ->brandLogoHeight('3rem')
            ->favicon(secure_asset('favicon.ico'))
            ->darkMode(false)
            ->colors([
                'primary' => Color::Red,
                'danger' => Color::Rose,
                'gray' => Color::Slate,
                'info' => Color::Blue,
                'success' => Color::Green,
                'warning' => Color::Amber,
            ])
            ->font('Plus Jakarta Sans')
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->plugin(FilamentShieldPlugin::make())
            ->plugin(
                FilamentSpatieLaravelHealthPlugin::make()
                    ->usingPage(\App\Filament\Pages\HealthCheckResults::class)
            )
            ->globalSearchKeyBindings(['command+k', 'ctrl+k']);

        // Spotlight is conditional so the admin panel still boots when the
        // package is not yet vendored (e.g. fresh checkout before
        // `composer install`). When present, Cmd+K opens a richer palette.
        if (class_exists(SpotlightPlugin::class)) {
            $panel->plugin(SpotlightPlugin::make());
        }

        return $panel
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                Pages\Dashboard::class,
                EquipmentIntake::class,
                TrtTrailerInventory::class,
            ])
            ->widgets([
                FleetStatsWidget::class,
                InventoryOverviewWidget::class,
                StationOperationsHubWidget::class,
                SmartUpdatesWidget::class,
            ])
            ->navigationGroups([
                NavigationGroup::make()
                    ->label('Dashboard')
                    ->icon('heroicon-o-rectangle-group')
                    ->collapsed(false),
                NavigationGroup::make()
                    ->label('Active Operations')
                    ->icon('heroicon-o-clipboard-document-list')
                    ->collapsed(false),
                NavigationGroup::make()
                    ->label('Fleet Management')
                    ->icon('heroicon-o-truck'),
                NavigationGroup::make()
                    ->label('Inventory & Logistics')
                    ->icon('heroicon-o-cube'),
                NavigationGroup::make()
                    ->label('Workgroup Management')
                    ->icon('heroicon-o-user-group'),
                NavigationGroup::make()
                    ->label('Station Management')
                    ->icon('heroicon-o-building-office-2'),
                NavigationGroup::make()
                    ->label('External Tools')
                    ->icon('heroicon-o-arrow-top-right-on-square'),
            ])
            ->userMenuItems([
                MenuItem::make()
                    ->label('Settings')
                    ->url(fn (): string => Settings::getUrl())
                    ->icon('heroicon-o-cog-6-tooth'),
                MenuItem::make()
                    ->label('Notification Settings')
                    ->url(fn (): string => NotificationSettings::getUrl(panel: 'admin'))
                    ->icon('heroicon-o-bell')
                    ->visible(fn (): bool => auth()->user()?->canManageNotificationSettings() ?? false),
                MenuItem::make()
                    ->label('Users')
                    ->url(fn (): string => url('/admin/users'))
                    ->icon('heroicon-o-users')
                    ->sort(10)
                    ->visible(fn (): bool => auth()->user()?->hasAnyRole(['super_admin', 'admin']) ?? false),
                MenuItem::make()
                    ->label('Roles')
                    ->url(fn (): string => url('/admin/shield/roles'))
                    ->icon('heroicon-o-shield-check')
                    ->sort(11)
                    ->visible(fn (): bool => auth()->user()?->hasRole('super_admin') ?? false),
                MenuItem::make()
                    ->label('Replacement Recommendations')
                    ->url(fn (): string => \App\Filament\Resources\RecommendationResource::getUrl('index'))
                    ->icon('heroicon-o-light-bulb')
                    ->sort(12)
                    ->visible(fn (): bool => auth()->user()?->hasAnyRole(['super_admin', 'admin']) ?? false),
                MenuItem::make()
                    ->label('Application Health')
                    ->url(fn (): string => url('/admin/health'))
                    ->icon('heroicon-o-heart')
                    ->sort(20)
                    ->visible(fn (): bool => auth()->user()?->hasRole('super_admin') ?? false),
                MenuItem::make()
                    ->label('Laravel Pulse')
                    ->url(fn (): string => url('/admin/pulse'))
                    ->icon('heroicon-o-bolt')
                    ->sort(21)
                    ->visible(fn (): bool => auth()->user()?->hasRole('super_admin') ?? false),
                MenuItem::make()
                    ->label('Return to Home')
                    ->url('/')
                    ->icon('heroicon-o-home')
                    ->sort(99),
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
                RedirectTrainingUsers::class,
            ])
            ->databaseNotifications()
            ->databaseNotificationsPolling('30s')
            ->sidebarCollapsibleOnDesktop()
            ->navigationItems([
                NavigationItem::make('Baserow')
                    ->url('https://baserow.mbfdhub.com', shouldOpenInNewTab: true)
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->group('External Tools')
                    ->sort(99),
                NavigationItem::make('Snipe-IT Inventory')
                    ->url('https://inventory.mbfdhub.com/', shouldOpenInNewTab: true)
                    ->icon('heroicon-o-cube')
                    ->group('Inventory & Logistics')
                    ->sort(10),
            ])
            ->renderHook(
                PanelsRenderHook::GLOBAL_SEARCH_BEFORE,
                fn (): string => '<a href="/" class="flex items-center justify-center w-10 h-10 rounded-lg text-gray-500 hover:text-primary-500 hover:bg-gray-100 transition" title="Return to Home" aria-label="Return to Home"><svg class="w-5 h-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m2.25 12 8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25" /></svg></a>'
            )
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                fn (): string => self::safeRender('filament.admin.partials.head-pwa', '<meta name="mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-status-bar-style" content="black-translucent"><meta name="apple-mobile-web-app-title" content="MBFD Hub">')
            )
            ->renderHook(
                PanelsRenderHook::BODY_END,
                fn (): string => self::safeRender('filament.admin.partials.keyboard-shortcuts')
                    . self::safeRender('filament.admin.partials.status-bar')
                    . self::safeRender('filament.admin.partials.install-prompt')
                    . self::safeRender('filament.admin.partials.context-menu')
            );
    }

    /**
     * Render a Blade partial defensively.
     *
     * The desktop modernization partials live in resources/views/filament/admin/partials/.
     * If any one of them fails to render — missing file, Blade error, view-cache
     * stale — we silently fall back to the optional placeholder rather than
     * crashing the entire admin page. Errors are reported to Sentry so we
     * still hear about it.
     */
    private static function safeRender(string $view, string $fallback = ''): string
    {
        try {
            if (! view()->exists($view)) {
                return $fallback;
            }
            return view($view)->render();
        } catch (\Throwable $e) {
            if (app()->bound('sentry')) {
                try { app('sentry')->captureException($e); } catch (\Throwable $ignored) {}
            }
            return $fallback;
        }
    }
}
