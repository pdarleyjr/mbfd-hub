<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Models\Apparatus;
use App\Models\Room;
use App\Models\Station;
use App\Models\StationRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

#[RunTestsInSeparateProcesses]
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
        $this->withoutExceptionHandling();
        $this->withoutVite();

        Apparatus::query()->create([
            'unit_id' => 'AUDIT-E2',
            'designation' => 'Audit Engine 2',
            'slug' => 'audit-engine-2',
            'name' => null,
            'make' => 'Audit',
            'model' => 'Fixture',
        ]);
        $station = Station::query()->create([
            'station_number' => 1,
            'name' => 'Station 1',
            'address' => '1 Test Street',
            'is_active' => true,
        ]);
        $room = Room::query()->create([
            'station_id' => $station->id,
            'name' => 'Kitchen',
            'room_type' => 'kitchen',
        ]);
        $stationRequest = StationRequest::query()->create([
            'station_id' => $station->id,
            'room_id' => $room->id,
            'room_name_snapshot' => $room->name,
            'requester_name_snapshot' => 'Smoke Test Requester',
            'request_type' => 'repair_service',
            'subject_type' => 'existing_asset',
            'title' => 'Refrigerator repair',
            'description' => 'The refrigerator is not cooling.',
            'priority' => 'high',
            'status' => 'acknowledged',
            'acknowledged_by' => $admin->id,
            'acknowledged_at' => now(),
            'legacy_source' => 'smoke_fixture',
            'legacy_id' => 1,
            'metadata' => [
                'signatures' => ['member' => 'station-requests/signatures/member.png'],
                'legacy' => ['source' => 'smoke_fixture'],
            ],
        ]);
        $stationRequest->items()->create([
            'item_name' => 'Refrigerator',
            'quantity' => 1,
            'photo_path' => 'station-requests/photos/refrigerator.png',
        ]);
        $stationRequest->updates()->create([
            'status' => 'acknowledged',
            'public_note' => 'Request received.',
            'changed_by_user_id' => $admin->id,
        ]);

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
            '/admin/station-requests',
            "/admin/station-requests/{$stationRequest->id}",
            '/admin/room-assets',
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
