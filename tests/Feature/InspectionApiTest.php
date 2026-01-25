<?php

namespace Tests\Feature;

use App\Models\Apparatus;
use App\Models\Station;
use App\Models\ApparatusInspection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InspectionApiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test that POST to /api/public/apparatuses/{id}/inspections creates inspection records
     */
    public function test_can_create_apparatus_inspection(): void
    {
        // Create a station and apparatus
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

        // Inspection data payload
        $inspectionData = [
            'operator_name' => 'John Doe',
            'rank' => 'Lieutenant',
            'completed_at' => now()->toISOString(),
            'shift' => 'A',
            'unit_number' => 'E1',
        ];

        // Make POST request
        $response = $this->postJson("/api/public/apparatuses/{$apparatus->id}/inspections", $inspectionData);

        // Assert successful creation
        $response->assertStatus(201)
            ->assertJsonStructure([
                'id',
                'apparatus_id',
                'operator_name',
                'rank',
                'completed_at',
                'created_at',
                'updated_at',
            ]);

        // Verify record was created in database
        $this->assertDatabaseHas('apparatus_inspections', [
            'apparatus_id' => $apparatus->id,
            'operator_name' => 'John Doe',
            'rank' => 'Lieutenant',
        ]);

        // Verify inspection count
        $this->assertEquals(1, ApparatusInspection::count());
    }

    /**
     * Test that invalid apparatus ID returns 404
     */
    public function test_returns_404_for_invalid_apparatus(): void
    {
        $inspectionData = [
            'operator_name' => 'John Doe',
            'rank' => 'Lieutenant',
            'completed_at' => now()->toISOString(),
        ];

        $response = $this->postJson('/api/public/apparatuses/99999/inspections', $inspectionData);

        $response->assertStatus(404);
    }

    /**
     * Test that missing required fields returns validation error
     */
    public function test_validates_required_fields(): void
    {
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

        // Missing required fields
        $response = $this->postJson("/api/public/apparatuses/{$apparatus->id}/inspections", []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['operator_name']);
    }
}
