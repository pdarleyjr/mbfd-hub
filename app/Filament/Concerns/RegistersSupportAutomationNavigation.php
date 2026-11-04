<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Services\AiAutomation\Admin\NavigationAccess;
use Filament\Facades\Filament;

/**
 * The five Support Services Automation menu entries share one access check per request instead of one each:
 * for an administrator who holds the permission through a role or directly, every check is a database lookup,
 * and the menu is built on every admin page. Opening a screen is still authorized by the page or resource itself.
 */
trait RegistersSupportAutomationNavigation
{
    public static function registerNavigationItems(): void
    {
        if (filled(static::getCluster()) || ! static::shouldRegisterNavigation() || ! NavigationAccess::allowed()) {
            return;
        }

        Filament::getCurrentPanel()->navigationItems(static::getNavigationItems());
    }
}
