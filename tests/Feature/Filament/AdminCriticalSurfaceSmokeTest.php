<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AdminCriticalSurfaceSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_requested_admin_surfaces_and_create_links_render_without_server_errors(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $role = Role::create(['name' => 'super_admin', 'guard_name' => 'web']);
        $admin = User::factory()->create();
        $admin->assignRole($role);
        Gate::before(fn (User $user): ?bool => $user->hasRole('super_admin') ? true : null);
        $this->actingAs($admin);

        $paths = [
            '/admin',
            '/admin/employees',
            '/admin/employees/create',
            '/admin/uniforms',
            '/admin/uniforms/create',
            '/admin/employee-equipment-requests',
            '/admin/apparatuses',
            '/admin/apparatuses/create',
            '/admin/inspections',
            '/admin/fire-equipment-requests',
            '/admin/station-inspections',
            '/admin/stations',
            '/admin/stations/create',
            '/admin/trt-trailer-inventory',
        ];

        foreach ($paths as $path) {
            $response = $this->get($path);
            $this->assertSame(200, $response->status(), "Admin surface failed: {$path}");
        }
    }
}
