<?php

namespace App\Providers\Filament;

use App\Filament\Employee\Pages\Auth\EmployeeLogin;
use App\Filament\Employee\Pages\ChangePasswordPage;
use App\Filament\Employee\Pages\EmployeeDashboard;
use App\Filament\Employee\Pages\MyBidCertificationsPage;
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
                MyBidCertificationsPage::class,
                MyEquipmentPage::class,
                RequestEquipmentPage::class,
                ChangePasswordPage::class,
            ])
            ->widgets([])
            ->userMenuItems([
                MenuItem::make()
                    ->label('Open Bid Console')
                    ->url(fn (): ?string => config('services.bid.console_url'))
                    ->visible(fn (): bool => filled(config('services.bid.console_url')))
                    ->icon('heroicon-o-bolt')
                    ->openUrlInNewTab(),
                MenuItem::make()
                    ->label('My Bid Certifications')
                    ->url(fn (): string => MyBidCertificationsPage::getUrl(panel: 'employee'))
                    ->icon('heroicon-o-check-badge'),
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
            ->sidebarCollapsibleOnDesktop();
    }
}
