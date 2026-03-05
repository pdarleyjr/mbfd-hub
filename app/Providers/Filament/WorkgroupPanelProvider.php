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
use Filament\View\PanelsRenderHook;
use App\Filament\Pages\Auth\Login;
use App\Filament\Workgroup\Pages\Dashboard;
use App\Filament\Workgroup\Pages\Files;
use App\Filament\Workgroup\Pages\Notes;
use App\Filament\Workgroup\Pages\Evaluations;
use App\Filament\Workgroup\Pages\SharedUploads;
use App\Filament\Workgroup\Pages\Profile;
use App\Filament\Workgroup\Pages\EvaluationFormPage;
use App\Filament\Workgroup\Pages\SessionResultsPage;
use App\Filament\Resources\Workgroup\CandidateProductResource;
use App\Filament\Resources\Workgroup\EvaluationCategoryResource;

class WorkgroupPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('workgroups')
            ->path('workgroups')
            ->homeUrl('/')
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
            // Explicitly register only the needed resources
            // This hides the deprecated EvaluationTemplateResource and EvaluationCriterionResource
            ->resources([
                EvaluationCategoryResource::class,
                CandidateProductResource::class,
            ])
            ->pages([
                Dashboard::class,
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
                \App\Filament\Workgroup\Widgets\FinalistsWidget::class,
                \App\Filament\Workgroup\Widgets\CategoryRankingsWidget::class,
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
                MenuItem::make()
                    ->label('Return to Home')
                    ->url('/')
                    ->icon('heroicon-o-home'),
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
            ->renderHook(
                PanelsRenderHook::GLOBAL_SEARCH_BEFORE,
                fn (): string => '<a href="/" class="flex items-center justify-center w-10 h-10 rounded-lg text-gray-500 hover:text-primary-500 hover:bg-gray-100 transition" title="Return to Home" aria-label="Return to Home"><svg class="w-5 h-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m2.25 12 8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25" /></svg></a>'
            );
    }
}