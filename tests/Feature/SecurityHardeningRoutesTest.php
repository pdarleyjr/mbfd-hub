<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class SecurityHardeningRoutesTest extends TestCase
{
    public function test_workgroup_export_and_report_routes_require_their_intended_workgroup_scope(): void
    {
        $memberScopedRoutes = [
            'workgroup.file.download',
            'workgroup.file.preview',
            'workgroup.shared-upload.download',
            'workgroup.saver-report',
            'workgroup.export.csv',
            'reports.executive.pdf',
            'reports.saver.pdf',
        ];
        $globalScopedRoutes = [
            'workgroup.analysis-report',
            'workgroup.data-dashboard',
            'workgroup.l1-inventory',
            'workgroup.final-presentation',
            'workgroup.evaluation-report',
            'workgroup.final-recommendations',
            'workgroup.workgroup-summary',
        ];

        foreach ($memberScopedRoutes as $routeName) {
            $route = Route::getRoutes()->getByName($routeName);

            $this->assertNotNull($route, "Route [{$routeName}] should exist.");
            $this->assertContains('auth', $route->gatherMiddleware(), "Route [{$routeName}] should require auth.");
            $this->assertContains('workgroup.access', $route->gatherMiddleware(), "Route [{$routeName}] should require workgroup access.");
        }

        foreach ($globalScopedRoutes as $routeName) {
            $route = Route::getRoutes()->getByName($routeName);

            $this->assertNotNull($route, "Route [{$routeName}] should exist.");
            $this->assertContains('auth', $route->gatherMiddleware(), "Route [{$routeName}] should require auth.");
            $this->assertContains('workgroup.global', $route->gatherMiddleware(), "Route [{$routeName}] should require explicit global workgroup access.");
        }
    }

    public function test_workgroup_ai_routes_require_workgroup_access_and_throttle(): void
    {
        $routes = collect(Route::getRoutes())->filter(
            fn ($route) => str_starts_with($route->uri(), 'api/workgroup/ai/')
        );

        $this->assertGreaterThan(0, $routes->count(), 'Workgroup AI routes should exist.');

        foreach ($routes as $route) {
            $middleware = $route->gatherMiddleware();

            $this->assertContains('auth', $middleware, "Route [{$route->uri()}] should require auth.");
            $this->assertContains('workgroup.access', $middleware, "Route [{$route->uri()}] should require workgroup access.");
            $this->assertContains('throttle:30,1', $middleware, "Route [{$route->uri()}] should be throttled.");
        }
    }

    public function test_admin_audit_routes_have_route_level_admin_role_and_throttle(): void
    {
        $routes = collect(Route::getRoutes())->filter(
            fn ($route) => str_starts_with($route->uri(), 'api/admin/audit/')
        );

        $this->assertGreaterThan(0, $routes->count(), 'Admin audit routes should exist.');

        foreach ($routes as $route) {
            $middleware = $route->gatherMiddleware();

            $this->assertContains('auth', $middleware, "Route [{$route->uri()}] should require auth.");
            $this->assertContains('admin.role:super_admin,admin', $middleware, "Route [{$route->uri()}] should require an admin role.");
            $this->assertContains('throttle:30,1', $middleware, "Route [{$route->uri()}] should be throttled.");
        }
    }

    public function test_csp_report_sink_is_throttled(): void
    {
        $route = Route::getRoutes()->getByName('csp.report');

        $this->assertNotNull($route, 'CSP report route should exist.');
        $this->assertContains('throttle:30,1', $route->gatherMiddleware());
    }
}
