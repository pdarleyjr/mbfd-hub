<?php

namespace App\Providers\Filament;

use App\Filament\Pages\NotificationSettings;
use App\Filament\Pages\SetPasswordPage;
use App\Filament\Support\HubPalette;
use App\Filament\Training\Pages\Settings as TrainingSettings;
use App\Http\Controllers\Auth\CanonicalPanelLoginRedirectController;
use App\Http\Middleware\AuthenticateCanonicalPanelUser;
use App\Http\Middleware\EnsureCanonicalSessionIsCurrent;
use App\Http\Middleware\EnsureTrainingPanelAccess;
use App\Http\Middleware\ForceFilamentPasswordChange;
use Filament\FontProviders\LocalFontProvider;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\MenuItem;
use Filament\Navigation\NavigationGroup;
use Filament\Panel;
use Filament\PanelProvider;
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
            ->id('training')
            ->path('training')
            ->homeUrl('/')
            ->login(CanonicalPanelLoginRedirectController::class)
            ->brandName('MBFD Hub')
            ->brandLogo(fn () => view('components.hub.brand', ['link' => false]))
            ->brandLogoHeight('2.75rem')
            ->favicon(asset('favicon.ico'))
            ->darkMode(false)
            ->colors(HubPalette::colors())
            ->font('Plus Jakarta Sans Variable', provider: LocalFontProvider::class)
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->discoverResources(in: app_path('Filament/Training/Resources'), for: 'App\\Filament\\Training\\Resources')
            ->discoverPages(in: app_path('Filament/Training/Pages'), for: 'App\\Filament\\Training\\Pages')
            ->discoverWidgets(in: app_path('Filament/Training/Widgets'), for: 'App\\Filament\\Training\\Widgets')
            ->widgets([
                \App\Filament\Training\Widgets\TrainingStatsWidget::class,
                \App\Filament\Training\Widgets\TrainingTodoWidget::class,
            ])
            ->pages([
                \App\Filament\Training\Pages\Dashboard::class,
                NotificationSettings::class,
                SetPasswordPage::class,
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
            ])
            ->userMenuItems([
                MenuItem::make()->label('My Account')->url(fn (): string => route('account.show'))->icon('heroicon-o-user-circle'),
                MenuItem::make()->label('Change Password')->url(fn (): string => SetPasswordPage::getUrl(panel: 'training'))->icon('heroicon-o-key'),
                MenuItem::make()
                    ->label('City email & verification')
                    ->url(fn (): string => route('city-email.show'))
                    ->icon('heroicon-o-envelope')
                    ->visible(fn (): bool => auth()->user()?->employee_profile_id !== null),
                MenuItem::make()
                    ->label('Return to Home')
                    ->icon('heroicon-o-home')
                    ->url('/'),
                MenuItem::make()
                    ->label('Settings')
                    ->url(fn (): string => TrainingSettings::getUrl())
                    ->icon('heroicon-o-cog-6-tooth'),
                MenuItem::make()
                    ->label('Notification Settings')
                    ->url(fn (): string => NotificationSettings::getUrl(panel: 'training'))
                    ->icon('heroicon-o-bell')
                    ->visible(fn (): bool => auth()->user()?->canManageNotificationSettings() ?? false),
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
                AuthenticateCanonicalPanelUser::class,
                EnsureTrainingPanelAccess::class,
                ForceFilamentPasswordChange::class,
                \App\Http\Middleware\EnsureCityEmailReview::class,
            ])
            ->persistentMiddleware([
                EnsureCanonicalSessionIsCurrent::class,
                EnsureTrainingPanelAccess::class,
                ForceFilamentPasswordChange::class,
                \App\Http\Middleware\EnsureCityEmailReview::class,
            ])
            ->sidebarCollapsibleOnDesktop()
            ->sidebarWidth('18rem')
            ->collapsedSidebarWidth('4rem');
    }
}
