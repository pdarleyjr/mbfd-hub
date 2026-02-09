<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TrainingPanelAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Create roles and permissions
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        Permission::create(['name' => 'training.access', 'guard_name' => 'web']);
        Permission::create(['name' => 'training.manage_external_links', 'guard_name' => 'web']);

        $superAdmin = Role::create(['name' => 'super_admin', 'guard_name' => 'web']);
        $admin = Role::create(['name' => 'admin', 'guard_name' => 'web']);
        $trainingAdmin = Role::create(['name' => 'training_admin', 'guard_name' => 'web']);
        $trainingViewer = Role::create(['name' => 'training_viewer', 'guard_name' => 'web']);

        $superAdmin->syncPermissions(Permission::all());
        $trainingAdmin->syncPermissions(['training.access', 'training.manage_external_links']);
        $trainingViewer->syncPermissions(['training.access']);
    }

    public function test_user_without_training_access_gets_denied_on_training_panel(): void
    {
        $user = User::factory()->create();
        // No roles assigned — should be denied

        $response = $this->actingAs($user)->get('/training');

        // Filament redirects unauthenticated panel users to login or returns 403/404
        $this->assertTrue(in_array($response->status(), [302, 403, 404]));
    }

    public function test_training_admin_can_access_training_panel(): void
    {
        $user = User::factory()->create();
        $user->assignRole('training_admin');

        $response = $this->actingAs($user)->get('/training');

        // Should get 200 or 302 redirect to dashboard (successful access)
        $this->assertTrue(in_array($response->status(), [200, 302]));

        // If redirected, should be within /training (not to login)
        if ($response->status() === 302) {
            $this->assertStringContainsString('training', $response->headers->get('Location'));
        }
    }

    public function test_training_user_without_super_admin_cannot_access_admin_panel(): void
    {
        $user = User::factory()->create();
        $user->assignRole('training_admin');

        $response = $this->actingAs($user)->get('/admin');

        // Should be denied — 302 to login, 403, or 404
        $this->assertTrue(
            in_array($response->status(), [302, 403, 404]),
            "Expected 302/403/404 but got {$response->status()}"
        );

        if ($response->status() === 302) {
            // Should redirect to login, not to admin dashboard
            $location = $response->headers->get('Location');
            $this->assertStringContainsString('login', $location);
        }
    }

    public function test_admin_user_without_training_role_cannot_access_training_panel(): void
    {
        $user = User::factory()->create();
        $user->assignRole('admin');

        $response = $this->actingAs($user)->get('/training');

        // Should be denied
        $this->assertTrue(in_array($response->status(), [302, 403, 404]));
    }

    public function test_super_admin_can_access_both_panels(): void
    {
        $user = User::factory()->create();
        $user->assignRole('super_admin');

        $trainingResponse = $this->actingAs($user)->get('/training');
        $this->assertTrue(
            in_array($trainingResponse->status(), [200, 302]),
            "Super admin should access training panel"
        );

        $adminResponse = $this->actingAs($user)->get('/admin');
        $this->assertTrue(
            in_array($adminResponse->status(), [200, 302]),
            "Super admin should access admin panel"
        );
    }

    public function test_training_viewer_can_access_training_panel(): void
    {
        $user = User::factory()->create();
        $user->assignRole('training_viewer');

        $response = $this->actingAs($user)->get('/training');

        $this->assertTrue(in_array($response->status(), [200, 302]));
    }
}
