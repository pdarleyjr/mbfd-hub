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
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use App\Http\Middleware\EnsureWorkgroupPanelAccess;
use App\Filament\Pages\Auth\Login;
use App\Filament\Workgroup\Pages\Dashboard;
use App\Filament\Workgroup\Pages\Files;
use App\Filament\Workgroup\Pages\Notes;
use App\Filament\Workgroup\Pages\Evaluations;
use App\Filament\Workgroup\Pages\SharedUploads;
use App\Filament\Workgroup\Pages\Profile;
use App\Filament\Workgroup\Pages\EvaluationFormPage;
use App\Filament\Workgroup\Pages\AdminDashboard;
use App\Filament\Workgroup\Pages\SessionResultsPage;
use Monzer\FilamentChatifyIntegration\ChatifyPlugin;

class WorkgroupPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('workgroups')
            ->path('workgroups')
            ->login(Login::class)
            ->brandName('Eval Feedback Hub')
            ->brandLogo(secure_asset('images/mbfd_no_bg_new.png'))
            ->brandLogoHeight('3rem')
            ->favicon(secure_asset('favicon.ico'))
            ->darkMode(false)
            ->colors([
                'primary' => Color::Indigo,
                'danger' => Color::Rose,
                'gray' => Color::Slate,
                'info' => Color::Blue,
                'success' => Color::Green,
                'warning' => Color::Amber,
            ])
            ->font('Inter')
            ->plugin(ChatifyPlugin::make())
            ->discoverResources(in: app_path('Filament/Workgroup/Resources'), for: 'App\\Filament\\Workgroup\\Resources')
            ->discoverPages(in: app_path('Filament/Workgroup/Pages'), for: 'App\\Filament\\Workgroup\\Pages')
            ->discoverWidgets(in: app_path('Filament/Workgroup/Widgets'), for: 'App\\Filament\\Workgroup\\Widgets')
            ->pages([
                Dashboard::class,
                Files::class,
                Notes::class,
                Evaluations::class,
                SharedUploads::class,
                EvaluationFormPage::class,
                Profile::class,
                AdminDashboard::class,
                SessionResultsPage::class,
            ])
            ->widgets([
                \App\Filament\Workgroup\Widgets\WorkgroupStatsWidget::class,
                \App\Filament\Workgroup\Widgets\WorkgroupAdminStatsWidget::class,
                \App\Filament\Workgroup\Widgets\SessionProgressWidget::class,
            ])
            ->navigationGroups([
                NavigationGroup::make()
                    ->label('Dashboard')
                    ->icon('heroicon-o-users')
                    ->collapsible(false),
                NavigationGroup::make()
                    ->label('Evaluations')
                    ->icon('heroicon-o-clipboard-document-check')
                    ->collapsible(false),
                NavigationGroup::make()
                    ->label('Files')
                    ->icon('heroicon-o-document-duplicate')
                    ->collapsible(false),
                NavigationGroup::make()
                    ->label('Admin')
                    ->icon('heroicon-o-cog')
                    ->collapsible(false),
                NavigationGroup::make()
                    ->label('Personal')
                    ->icon('heroicon-o-user')
                    ->collapsible(false),
                NavigationGroup::make()
                    ->label('External Tools')
                    ->icon('heroicon-o-globe-alt')
                    ->collapsible(false),
            ])
            ->userMenuItems([
                MenuItem::make()
                    ->label('Settings')
                    ->url(fn (): string => Profile::getUrl())
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
                EnsureWorkgroupPanelAccess::class,
            ])
            ->sidebarCollapsibleOnDesktop()
            ->navigationItems([
                NavigationItem::make('Admin Dashboard')
                    ->url(fn (): string => AdminDashboard::getUrl())
                    ->icon('heroicon-o-chart-bar')
                    ->group('Admin')
                    ->sort(1),
                NavigationItem::make('Session Results')
                    ->url(fn (): string => SessionResultsPage::getUrl())
                    ->icon('heroicon-o-trophy')
                    ->group('Admin')
                    ->sort(2),
                NavigationItem::make('Baserow')
                    ->url('https://baserow.mbfdhub.com', shouldOpenInNewTab: true)
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->group('External Tools')
                    ->sort(99),
            ]);
    }
}