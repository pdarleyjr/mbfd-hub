<?php

declare(strict_types=1);

namespace Mbfd\PolicyLibrary\Filament;

use App\Http\Middleware\EnforceMemberBootstrapBoundary;
use App\Http\Middleware\EnsureCanonicalSessionIsCurrent;
use App\Http\Middleware\EnsureCityEmailReview;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\MenuItem;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Mbfd\PolicyLibrary\Http\Middleware\EnsureLibraryAccountReady;
use Mbfd\PolicyLibrary\Http\Middleware\EnsureLibraryAdmin;
use Mbfd\PolicyLibrary\Http\Middleware\LibrarySecurity;

final class LibraryPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        $canonical = array_values(array_filter([
            EnforceMemberBootstrapBoundary::class,
            EnsureCanonicalSessionIsCurrent::class,
        ], 'class_exists'));
        $cityEmail = class_exists(EnsureCityEmailReview::class) ? [EnsureCityEmailReview::class] : [];

        return $panel->id('policy-library')->domain(config('policy-library.domain'))->path('manage')
            ->authGuard('web')->brandName('MBFD Policy Library')->darkMode(false)->colors(['primary' => Color::Blue])
            ->brandLogo(asset('vendor/policy-library/images/mbfd-logo.png'))->brandLogoHeight('3rem')
            ->defaultAvatarProvider(LibraryAvatarProvider::class)
            ->userMenuItems(['logout' => MenuItem::make()->label('Sign out')->postAction('/logout')])
            ->pages([Pages\ManualBuilder::class])
            ->resources([Resources\ManualResource::class, Resources\NodeResource::class, Resources\EditionResource::class, Resources\ImportResource::class])
            ->middleware(['web', LibrarySecurity::class, DisableBladeIconComponents::class, DispatchServingFilamentEvent::class])
            ->authMiddleware([...$canonical, EnsureLibraryAccountReady::class, ...$cityEmail, EnsureLibraryAdmin::class], isPersistent: true)
            ->homeUrl(fn () => Pages\ManualBuilder::getUrl(panel: 'policy-library'));
    }
}
