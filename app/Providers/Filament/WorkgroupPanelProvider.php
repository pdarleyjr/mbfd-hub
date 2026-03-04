<?php

namespace App\Providers\Filament;

use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\MenuItem;
use Filament\Navigation\NavigationGroup;
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
use App\Filament\Workgroup\Pages\SessionResultsPage;
use App\Filament\Workgroup\Pages\AdminDashboard;
use App\Filament\Resources\Workgroup\CandidateProductResource;
use App\Filament\Resources\Workgroup\EvaluationCategoryResource;
use App\Filament\Resources\Workgroup\EvaluationSubmissionResource;

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
            ->resources([
                EvaluationCategoryResource::class,
                CandidateProductResource::class,
                EvaluationSubmissionResource::class,
            ])
            ->pages([
                Dashboard::class,
                AdminDashboard::class,
                Files::class,
                Notes::class,
                Evaluations::class,
                SharedUploads::class,
                EvaluationFormPage::class,
                Profile::class,
                SessionResultsPage::class,
            ])
            ->widgets([
                \App\Filament\Workgroup\Widgets\WorkgroupStatsWidget::class,
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
                    ->label('Personal')
                    ->icon('heroicon-o-user')
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
            ->sidebarCollapsibleOnDesktop();
    }
}
