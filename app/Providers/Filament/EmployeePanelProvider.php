<?php

namespace App\Providers\Filament;

use App\Filament\Employee\Pages\ApparatusServiceRequestPage;
use App\Filament\Employee\Pages\EmployeeDashboard;
use App\Filament\Employee\Pages\MyEquipmentPage;
use App\Filament\Employee\Pages\MyRequestsPage;
use App\Filament\Employee\Pages\OperationalForms;
use App\Filament\Employee\Pages\PersonnelEquipmentRequestPage;
use App\Filament\Employee\Pages\RequestEquipmentPage;
use App\Filament\Employee\Pages\VideoConferencing;
use App\Filament\Pages\SetPasswordPage;
use App\Http\Controllers\Auth\CanonicalPanelLoginRedirectController;
use App\Http\Middleware\AuthenticateCanonicalPanelUser;
use App\Http\Middleware\EnsureCanonicalEmployeeContext;
use App\Http\Middleware\EnsureCanonicalSessionIsCurrent;
use App\Http\Middleware\ForceFilamentPasswordChange;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\MenuItem;
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

class EmployeePanelProvider extends PanelProvider
{
    public function register(): void
    {
        parent::register();
    }

    /**
     * Register any application authentication / authorization services.
     */
    public function boot(): void {}

    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('employee')
            ->path('employee')
            ->login(CanonicalPanelLoginRedirectController::class)
            ->brandName('MBFD Employee Portal')
            ->brandLogo(asset('images/mbfd_logo-256.png'))
            ->brandLogoHeight('2rem')
            ->favicon(asset('favicon.ico'))
            ->darkMode(false)
            ->colors([
                'primary' => Color::Blue,
                'danger' => Color::Rose,
                'gray' => Color::Slate,
                'info' => Color::Blue,
                'success' => Color::Green,
                'warning' => Color::Amber,
            ])
            ->font('Plus Jakarta Sans')
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->pages([
                EmployeeDashboard::class,
                OperationalForms::class,
                VideoConferencing::class,
                MyEquipmentPage::class,
                MyRequestsPage::class,
                RequestEquipmentPage::class,
                ApparatusServiceRequestPage::class,
                PersonnelEquipmentRequestPage::class,
                SetPasswordPage::class,
            ])
            ->widgets([])
            ->userMenuItems([
                MenuItem::make()
                    ->label('My Equipment')
                    ->url(fn (): string => MyEquipmentPage::getUrl(panel: 'employee'))
                    ->icon('heroicon-o-shield-check'),
                MenuItem::make()
                    ->label('My Requests')
                    ->url(fn (): string => MyRequestsPage::getUrl(panel: 'employee'))
                    ->icon('heroicon-o-clipboard-document-list'),
                MenuItem::make()
                    ->label('Request Uniforms')
                    ->url(fn (): string => RequestEquipmentPage::getUrl(panel: 'employee'))
                    ->icon('heroicon-o-shopping-cart'),
                MenuItem::make()
                    ->label('Apparatus Service')
                    ->url(fn (): string => ApparatusServiceRequestPage::getUrl(panel: 'employee'))
                    ->icon('heroicon-o-wrench-screwdriver'),
                MenuItem::make()
                    ->label('Video Conferencing')
                    ->url(fn (): string => VideoConferencing::getUrl(panel: 'employee'))
                    ->icon('heroicon-o-video-camera'),
                MenuItem::make()
                    ->label('Return to Home')
                    ->url('/')
                    ->icon('heroicon-o-home'),
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
                EnsureCanonicalEmployeeContext::class,
                ForceFilamentPasswordChange::class,
            ])
            ->persistentMiddleware([
                EnsureCanonicalSessionIsCurrent::class,
                EnsureCanonicalEmployeeContext::class,
                ForceFilamentPasswordChange::class,
            ])
            ->databaseNotifications()
            ->databaseNotificationsPolling('60s')
            ->renderHook(
                PanelsRenderHook::PAGE_START,
                fn () => auth('web')->check() ? view('filament.employee.partials.back-button') : '',
            )
            ->sidebarCollapsibleOnDesktop();
    }
}
