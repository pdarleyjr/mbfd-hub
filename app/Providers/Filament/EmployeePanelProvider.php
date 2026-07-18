<?php

namespace App\Providers\Filament;

use App\Filament\Employee\Pages\Auth\EmployeeLogin;
use App\Filament\Employee\Pages\ChangePasswordPage;
use App\Filament\Employee\Pages\EmployeeDashboard;
use App\Filament\Employee\Pages\MyEquipmentPage;
use App\Filament\Employee\Pages\OperationalForms;
use App\Filament\Employee\Pages\RequestEquipmentPage;
use App\Http\Middleware\ForcePasswordChangeMiddleware;
use App\Http\Middleware\RememberEmployeeIntendedPath;
use Filament\Http\Middleware\Authenticate;
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
            ->login(EmployeeLogin::class)
            ->authGuard('employee')
            ->brandName('MBFD Employee Portal')
            ->brandLogo(secure_asset('images/mbfd_logo.png'))
            ->brandLogoHeight('2rem')
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
            ->pages([
                EmployeeDashboard::class,
                OperationalForms::class,
                MyEquipmentPage::class,
                RequestEquipmentPage::class,
                ChangePasswordPage::class,
            ])
            ->widgets([])
            ->userMenuItems([
                MenuItem::make()
                    ->label('My Equipment')
                    ->url(fn (): string => MyEquipmentPage::getUrl(panel: 'employee'))
                    ->icon('heroicon-o-shield-check'),
                MenuItem::make()
                    ->label('Request Equipment')
                    ->url(fn (): string => RequestEquipmentPage::getUrl(panel: 'employee'))
                    ->icon('heroicon-o-shopping-cart'),
                MenuItem::make()
                    ->label('Change Password')
                    ->url(fn (): string => ChangePasswordPage::getUrl(panel: 'employee'))
                    ->icon('heroicon-o-lock-closed'),
                MenuItem::make()
                    ->label('Return to Home')
                    ->url('/')
                    ->icon('heroicon-o-home'),
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                RememberEmployeeIntendedPath::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
                ForcePasswordChangeMiddleware::class,
            ])
            ->databaseNotifications()
            ->databaseNotificationsPolling('60s')
            ->sidebarCollapsibleOnDesktop()
            ->renderHook(
                PanelsRenderHook::GLOBAL_SEARCH_BEFORE,
                fn (): string => '<a href="/" class="employee-header-home flex items-center justify-center rounded-lg text-gray-500 hover:text-primary-500 hover:bg-gray-100 transition" style="min-width:44px;min-height:44px" title="Return to MBFD Hub home" aria-label="Return to MBFD Hub home"><svg class="w-5 h-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m2.25 12 8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25" /></svg></a>'
            );
    }
}
