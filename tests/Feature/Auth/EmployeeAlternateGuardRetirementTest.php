<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;
use Tests\TestCase;

class EmployeeAlternateGuardRetirementTest extends TestCase
{
    public function test_only_canonical_user_authentication_is_configured_for_humans(): void
    {
        self::assertSame('web', config('auth.defaults.guard'));
        self::assertSame('users', config('auth.defaults.passwords'));
        self::assertArrayNotHasKey('employee', config('auth.guards'));
        self::assertArrayNotHasKey('employees', config('auth.providers'));
        self::assertSame('users', config('auth.guards.web.provider'));
        self::assertSame('users', config('auth.guards.sanctum.provider'));
        self::assertSame(User::class, config('auth.providers.users.model'));
    }

    public function test_human_panels_and_routes_never_select_an_employee_authentication_guard(): void
    {
        foreach (['admin', 'employee', 'training', 'workgroups'] as $panelId) {
            self::assertSame('web', Filament::getPanel($panelId)->getAuthGuard(), $panelId);
        }

        foreach (Route::getRoutes() as $route) {
            self::assertNotContains('auth:employee', $route->gatherMiddleware(), $route->uri());
        }
    }

    public function test_an_employee_authentication_guard_cannot_be_resolved(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Auth::guard('employee');
    }
}
