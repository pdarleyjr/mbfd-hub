<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\PreventPreviousUrlStorage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminPwaRoutesTest extends TestCase
{
    use RefreshDatabase;

    public function test_service_worker_is_served_from_within_its_admin_scope(): void
    {
        $response = $this->get('/admin/service-worker.js');

        $response
            ->assertOk()
            ->assertHeader('Content-Type', 'application/javascript; charset=utf-8')
            ->assertHeader('Service-Worker-Allowed', '/admin/');

        $worker = (string) file_get_contents(public_path('admin-pwa/service-worker.js'));

        $this->assertStringContainsString(
            "addEventListener('install'",
            $worker,
        );
        $this->assertStringContainsString("const VERSION = 'mbfd-admin-v3'", $worker);
        $this->assertStringNotContainsString('networkOnlyAdminNavigation', $worker);
    }

    public function test_background_pwa_asset_requests_do_not_replace_the_previous_admin_url(): void
    {
        $previousAdminUrl = url('/admin/department-updates/create');

        foreach ([
            '/admin-pwa/manifest.webmanifest',
            '/admin/service-worker.js',
            '/admin-pwa/service-worker.js',
        ] as $assetPath) {
            $response = $this
                ->withSession(['_previous.url' => $previousAdminUrl])
                ->get($assetPath);

            $response
                ->assertOk()
                ->assertSessionHas('_previous.url', $previousAdminUrl);
        }
    }

    public function test_all_admin_background_routes_are_excluded_from_navigation_history(): void
    {
        foreach ([
            '/admin-pwa/manifest.webmanifest',
            '/admin/service-worker.js',
            '/admin-pwa/service-worker.js',
        ] as $assetPath) {
            $route = app('router')->getRoutes()->match(Request::create($assetPath));

            $this->assertContains(PreventPreviousUrlStorage::class, app('router')->gatherRouteMiddleware($route));
        }

        $route = app('router')->getRoutes()->match(Request::create('/admin/pulse/queues.json'));
        $this->assertContains(PreventPreviousUrlStorage::class, app('router')->gatherRouteMiddleware($route));

        foreach (['/__version', '/api/admin/lookups/stations'] as $pollingPath) {
            $route = app('router')->getRoutes()->match(Request::create($pollingPath));

            $this->assertContains(PreventPreviousUrlStorage::class, app('router')->gatherRouteMiddleware($route));
        }
    }

    public function test_authenticated_admin_background_requests_preserve_the_last_page_url(): void
    {
        $role = Role::findOrCreate('super_admin', 'web');
        $admin = User::factory()->create();
        $admin->assignRole($role);
        $admin->givePermissionTo(Permission::findOrCreate('view_queue_status', 'web'));
        $previousAdminUrl = url('/admin/workgroup-members');

        foreach ([
            '/__version',
            '/admin/pulse/queues.json',
            '/api/admin/lookups/stations',
            '/api/admin/lookups/apparatus',
            '/api/admin/lookups/personnel',
        ] as $backgroundPath) {
            $response = $this
                ->actingAs($admin)
                ->withSession(['_previous.url' => $previousAdminUrl])
                ->get($backgroundPath);

            $response
                ->assertOk()
                ->assertSessionHas('_previous.url', $previousAdminUrl);
        }
    }

    public function test_expired_admin_background_requests_preserve_the_last_page_url_before_redirecting(): void
    {
        $previousAdminUrl = url('/admin/users');

        foreach ([
            '/__version',
            '/admin/pulse/queues.json',
            '/api/admin/lookups/stations',
        ] as $backgroundPath) {
            $response = $this
                ->withSession(['_previous.url' => $previousAdminUrl])
                ->get($backgroundPath);

            $response
                ->assertRedirect()
                ->assertSessionHas('_previous.url', $previousAdminUrl);
        }
    }

    public function test_navigation_history_guard_runs_before_the_session_middleware(): void
    {
        $route = app('router')->getRoutes()->match(Request::create('/__version'));
        $middleware = app('router')->gatherRouteMiddleware($route);

        self::assertLessThan(
            array_search(\Illuminate\Session\Middleware\StartSession::class, $middleware, true),
            array_search(PreventPreviousUrlStorage::class, $middleware, true),
        );
    }

    public function test_queue_status_requires_permission_except_for_the_central_super_admin_bypass(): void
    {
        $roles = collect(['super_admin', 'admin', 'logistics_admin', 'training_admin', 'training_viewer'])
            ->mapWithKeys(fn (string $name) => [$name => Role::create(['name' => $name, 'guard_name' => 'web'])]);
        $superAdmin = User::factory()->create();
        $superAdmin->assignRole($roles['super_admin']);
        $roles['super_admin']->givePermissionTo(Permission::findOrCreate('view_queue_status', 'web'));

        $this->getJson('/admin/pulse/queues.json')->assertUnauthorized();

        $this->actingAs(User::factory()->create())
            ->getJson('/admin/pulse/queues.json')
            ->assertForbidden();

        foreach (['admin', 'logistics_admin', 'training_admin', 'training_viewer'] as $roleName) {
            $user = User::factory()->create();
            $user->assignRole($roles[$roleName]);

            $this->actingAs($user)
                ->getJson('/admin/pulse/queues.json')
                ->assertForbidden();
        }

        $this->actingAs($superAdmin)
            ->getJson('/admin/pulse/queues.json')
            ->assertOk()
            ->assertExactJson(['pending' => 0]);

        $roles['super_admin']->syncPermissions([]);

        $this->actingAs($superAdmin->fresh())
            ->getJson('/admin/pulse/queues.json')
            ->assertOk();
    }
}
