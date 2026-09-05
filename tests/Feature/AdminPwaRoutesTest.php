<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

        $this->assertStringContainsString(
            "addEventListener('install'",
            (string) file_get_contents(public_path('admin-pwa/service-worker.js')),
        );
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
