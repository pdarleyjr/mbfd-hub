<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Apparatus;
use App\Models\Station;
use App\Models\ApparatusInspection;
use App\Models\ApparatusDefect;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminMetricsApiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test that admin metrics endpoint returns proper JSON structure
     */
    public function test_admin_metrics_returns_proper_json_structure(): void
    {
        // Create an authenticated user
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        // Create test data
        $station = Station::create([
            'station_number' => 1,
            'name' => 'Station 1',
            'address' => '123 Main St',
            'is_active' => true,
        ]);

        $apparatus = Apparatus::create([
            'station_id' => $station->id,
            'type' => 'Engine',
            'identifier' => 'E1',
            'name' => 'Engine 1',
            'slug' => 'engine-1',
            'year' => 2020,
            'make' => 'Pierce',
            'model' => 'Enforcer',
            'vin' => 'TEST123VIN',
            'status' => 'in_service',
            'is_reserve' => false,
        ]);

        // Create some inspections
        ApparatusInspection::create([
            'apparatus_id' => $apparatus->id,
            'operator_name' => 'John Doe',
            'rank' => 'Lieutenant',
            'completed_at' => now(),
        ]);

        // Create some defects
        ApparatusDefect::create([
            'apparatus_id' => $apparatus->id,
            'reported_by' => 'Jane Smith',
            'description' => 'Test defect',
            'severity' => 'minor',
            'status' => 'open',
        ]);

        // Make GET request to admin metrics endpoint
        $response = $this->getJson('/api/admin/metrics');

        // Assert successful response with proper structure
        $response->assertStatus(200)
            ->assertJsonStructure([
                'total_apparatuses',
                'total_inspections',
                'total_defects',
                'recent_inspections',
                'open_defects',
            ]);
    }

    /**
     * Test that unauthenticated requests are rejected
     */
    public function test_requires_authentication(): void
    {
        $response = $this->getJson('/api/admin/metrics');

        $response->assertStatus(401);
    }

    /**
     * Test that metrics contain correct data types
     */
    public function test_metrics_contain_correct_data_types(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/admin/metrics');

        $response->assertStatus(200);
        
        $data = $response->json();
        
        // Verify data types
        $this->assertIsInt($data['total_apparatuses'] ?? null);
        $this->assertIsInt($data['total_inspections'] ?? null);
        $this->assertIsInt($data['total_defects'] ?? null);
    }

    /**
     * Test that metrics accurately count records
     */
    public function test_metrics_accurately_count_records(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        // Create multiple stations and apparatuses
        $station1 = Station::create([
            'station_number' => 1,
            'name' => 'Station 1',
            'address' => '123 Main St',
            'is_active' => true,
        ]);

        $station2 = Station::create([
            'station_number' => 2,
            'name' => 'Station 2',
            'address' => '456 Oak Ave',
            'is_active' => true,
        ]);

        $apparatus1 = Apparatus::create([
            'station_id' => $station1->id,
            'type' => 'Engine',
            'identifier' => 'E1',
            'name' => 'Engine 1',
            'slug' => 'engine-1',
            'year' => 2020,
            'make' => 'Pierce',
            'model' => 'Enforcer',
            'vin' => 'TEST123VIN1',
            'status' => 'in_service',
            'is_reserve' => false,
        ]);

        $apparatus2 = Apparatus::create([
            'station_id' => $station2->id,
            'type' => 'Ladder',
            'identifier' => 'L1',
            'name' => 'Ladder 1',
            'slug' => 'ladder-1',
            'year' => 2019,
            'make' => 'Pierce',
            'model' => 'Ascendant',
            'vin' => 'TEST123VIN2',
            'status' => 'in_service',
            'is_reserve' => false,
        ]);

        $response = $this->getJson('/api/admin/metrics');

        $response->assertStatus(200);
        
        $data = $response->json();
        
        // Verify accurate counts
        $this->assertEquals(2, $data['total_apparatuses']);
    }
}
