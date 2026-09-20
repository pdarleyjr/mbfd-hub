<?php

namespace App\Providers\Filament;

use App\Filament\Admin\Pages\BidAccessPin;
use App\Filament\Admin\Pages\EquipmentIntake;
use App\Filament\Admin\Pages\KnowledgeBase;
use App\Filament\Admin\Pages\TrtTrailerInventory;
use App\Filament\Pages\HealthCheckResults;
use App\Filament\Pages\PulseDashboard;
use App\Filament\Widgets\FleetStatsWidget;
use App\Filament\Widgets\InventoryOverviewWidget;
use App\Filament\Widgets\StationOperationsHubWidget;
use App\Http\Controllers\Auth\CanonicalPanelLoginRedirectController;
use App\Http\Middleware\AuthenticateCanonicalPanelUser;
use App\Http\Middleware\EnsureCanonicalSessionIsCurrent;
use App\Http\Middleware\ForceFilamentPasswordChange;
use App\Http\Middleware\RedirectTrainingUsers;
use BezhanSalleh\FilamentShield\FilamentShieldPlugin;
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
use pxlrbt\FilamentSpotlight\SpotlightPlugin;
use ShuvroRoy\FilamentSpatieLaravelHealth\FilamentSpatieLaravelHealthPlugin;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        // HOTFIX 2026-05-14: SpotlightPlugin + globalSearchKeyBindings + new
        // renderHook composition introduced a boot-time 500 on /admin/login
        // in commit a01b1eba. Temporarily restored to last-known-good
        // chained-call structure while we diagnose the failure offline.
        // The PWA infrastructure (manifest, SW, install prompt, prefetch),
        // theme.css additions, EnterpriseTable trait, and Tailwind variants
        // all remain active — they proved themselves green (200) in smoke.
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login(CanonicalPanelLoginRedirectController::class)
            ->brandName('MBFD Support Hub')
            ->brandLogo(asset('images/mbfd_logo-256.png'))
            ->brandLogoHeight('2rem')
            ->favicon(asset('favicon.ico'))
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
            // Bisect step 1 (re-introduce): globalSearchKeyBindings — Filament 3
            // claims to support this with mod+k / cmd+k / ctrl+k strings. If
            // /admin/login stays 200 after this commit deploys, this item is
            // proven safe and we can move on to bisect step 2.
            ->globalSearchKeyBindings(['command+k', 'ctrl+k'])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverClusters(in: app_path('Filament/Clusters'), for: 'App\\Filament\\Clusters')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                Pages\Dashboard::class,
                EquipmentIntake::class,
                TrtTrailerInventory::class,
                BidAccessPin::class,
                KnowledgeBase::class,
            ])
            ->widgets([
                FleetStatsWidget::class,
                InventoryOverviewWidget::class,
                StationOperationsHubWidget::class,
            ])
            ->navigationGroups([
                NavigationGroup::make()
                    ->label('Dashboard')
                    ->icon('heroicon-o-rectangle-group')
                    ->collapsed(),
                NavigationGroup::make()
                    ->label('Active Operations')
                    ->icon('heroicon-o-clipboard-document-list')
                    ->collapsed(),
                NavigationGroup::make()
                    ->label('Fleet Management')
                    ->icon('heroicon-o-truck')
                    ->collapsed(),
                NavigationGroup::make()
                    ->label('Inventory & Logistics')
                    ->icon('heroicon-o-cube')
                    ->collapsed(),
                NavigationGroup::make()
                    ->label('Workgroup Management')
                    ->icon('heroicon-o-user-group')
                    ->collapsed(),
                NavigationGroup::make()
                    ->label('Station Management')
                    ->icon('heroicon-o-building-office-2')
                    ->collapsed(),
                NavigationGroup::make()
                    ->label('Bid Administration')
                    ->icon('heroicon-o-key')
                    ->collapsed(),
                NavigationGroup::make()
                    ->label('Communications')
                    ->icon('heroicon-o-envelope')
                    ->collapsed(),
                NavigationGroup::make()
                    ->label('Administration')
                    ->icon('heroicon-o-cog-6-tooth')
                    ->collapsed(),
                NavigationGroup::make()
                    ->label('Monitoring')
                    ->icon('heroicon-o-signal')
                    ->collapsed(),
            ])
            ->userMenuItems([
                MenuItem::make()->label('My Account')->url(fn (): string => route('account.show'))->icon('heroicon-o-user-circle'),
                MenuItem::make()->label('Change Password')->url(fn (): string => \App\Filament\Pages\SetPasswordPage::getUrl(panel: 'admin'))->icon('heroicon-o-key'),
                \Filament\Navigation\MenuItem::make()
                    ->label('City email & verification')
                    ->url(fn (): string => route('city-email.show'))
                    ->icon('heroicon-o-envelope')
                    ->visible(fn (): bool => auth()->user()?->employee_profile_id !== null),
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
                AuthenticateCanonicalPanelUser::class,
                RedirectTrainingUsers::class,
                ForceFilamentPasswordChange::class,
                \App\Http\Middleware\EnsureCityEmailReview::class,
            ])
            ->persistentMiddleware([
                EnsureCanonicalSessionIsCurrent::class,
                RedirectTrainingUsers::class,
                ForceFilamentPasswordChange::class,
                \App\Http\Middleware\EnsureCityEmailReview::class,
            ])
            ->databaseNotifications()
            ->databaseNotificationsPolling('30s')
            ->sidebarCollapsibleOnDesktop()
            ->navigationItems([
                NavigationItem::make('Snipe-IT Inventory')
                    ->url('https://inventory.mbfdhub.com/', shouldOpenInNewTab: true)
                    ->icon('heroicon-o-cube')
                    ->group('Inventory & Logistics')
                    ->sort(10),
                NavigationItem::make('Laravel Pulse')
                    ->url(fn (): string => PulseDashboard::getUrl(panel: 'admin'))
                    ->icon('heroicon-o-bolt')
                    ->group('Monitoring')
                    ->sort(1)
                    ->visible(fn (): bool => auth()->user()?->can('admin.system.view') ?? false),
                NavigationItem::make('Application Health')
                    ->url(fn (): string => HealthCheckResults::getUrl(panel: 'admin'))
                    ->icon('heroicon-o-heart')
                    ->group('Monitoring')
                    ->sort(2)
                    ->visible(fn (): bool => auth()->user()?->can('admin.system.view') ?? false),
            ])
            ->renderHook(
                PanelsRenderHook::GLOBAL_SEARCH_BEFORE,
                fn (): string => '<a href="/" class="flex items-center justify-center w-10 h-10 rounded-lg text-gray-500 hover:text-primary-500 hover:bg-gray-100 transition" title="Return to Home" aria-label="Return to Home"><svg class="w-5 h-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m2.25 12 8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25" /></svg></a>'
            )
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                fn (): string => self::safeRender(
                    'filament.admin.partials.head-pwa',
                    '<meta name="mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-status-bar-style" content="black-translucent"><meta name="apple-mobile-web-app-title" content="MBFD Hub">'
                )
            )
            // Bisect step 2 (re-introduce): BODY_END composes 4 desktop-modernization
            // partials via safeRender. Each partial is wrapped in try/catch/Throwable
            // and a missing view falls back to ''. Any partial that throws is
            // reported to Sentry and silently degrades. If /admin/login stays 200
            // after this commit deploys, the renderHook composition is proven safe.
            ->renderHook(
                PanelsRenderHook::BODY_END,
                fn (): string => self::safeRender('filament.admin.partials.keyboard-shortcuts')
                    .self::safeRender('filament.admin.partials.status-bar')
                    .self::safeRender('filament.admin.partials.install-prompt')
                    .self::safeRender('filament.admin.partials.context-menu')
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
                try {
                    app('sentry')->captureException($e);
                } catch (\Throwable $ignored) {
                }
            }

            return $fallback;
        }
    }
}
