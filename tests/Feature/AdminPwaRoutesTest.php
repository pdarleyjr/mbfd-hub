<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            ->assertHeader('Content-Type', 'application/javascript; charset=utf-8');

        $this->assertStringContainsString(
            "addEventListener('install'",
            (string) file_get_contents(public_path('admin-pwa/service-worker.js')),
        );
    }

    public function test_queue_status_is_available_only_to_admin_users(): void
    {
        Role::create(['name' => 'super_admin', 'guard_name' => 'web']);
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');

        $this->getJson('/admin/pulse/queues.json')->assertUnauthorized();

        $this->actingAs(User::factory()->create())
            ->getJson('/admin/pulse/queues.json')
            ->assertForbidden();

        $this->actingAs($admin)
            ->getJson('/admin/pulse/queues.json')
            ->assertOk()
            ->assertExactJson(['pending' => 0]);
    }
}
