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
            'officer_name' => 'John Doe',
            'officer_rank' => 'Lieutenant',
            'inspected_at' => now()->toISOString(),
            'overall_status' => 'pass',
            'notes' => 'All systems operational',
            'checklist_data' => [
                'compartment_1' => ['item_1' => true, 'item_2' => true],
                'compartment_2' => ['item_1' => false, 'notes' => 'Missing fire extinguisher'],
            ],
        ];

        // Make POST request
        $response = $this->postJson("/api/public/apparatuses/{$apparatus->id}/inspections", $inspectionData);

        // Assert successful creation
        $response->assertStatus(201)
            ->assertJsonStructure([
                'id',
                'apparatus_id',
                'officer_name',
                'officer_rank',
                'overall_status',
                'inspected_at',
                'created_at',
                'updated_at',
            ]);

        // Verify record was created in database
        $this->assertDatabaseHas('apparatus_inspections', [
            'apparatus_id' => $apparatus->id,
            'officer_name' => 'John Doe',
            'officer_rank' => 'Lieutenant',
            'overall_status' => 'pass',
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
            'officer_name' => 'John Doe',
            'officer_rank' => 'Lieutenant',
            'inspected_at' => now()->toISOString(),
            'overall_status' => 'pass',
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
            ->assertJsonValidationErrors(['officer_name', 'overall_status']);
    }
}
