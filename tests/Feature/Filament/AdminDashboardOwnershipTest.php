<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Pages\Dashboard;
use Tests\TestCase;

final class AdminDashboardOwnershipTest extends TestCase
{
    public function test_the_admin_root_route_is_owned_by_the_application_dashboard(): void
    {
        $route = app('router')->getRoutes()->getByName('filament.admin.pages.dashboard');

        self::assertNotNull($route);
        self::assertSame(Dashboard::class, $route->getActionName());
    }
}
