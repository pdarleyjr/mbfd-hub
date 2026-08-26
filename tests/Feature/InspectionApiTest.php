<?php

namespace Tests\Feature;

use App\Models\Apparatus;
use App\Models\ApparatusInspection;
use App\Models\Station;
use App\Services\DailyCheckoutChecklistResolver;
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
            'unit_id' => 'E1',
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
            'daily_checkout_requirement' => 'required',
        ]);

        // Inspection data payload
        $inspectionData = [
            'client_submission_id' => 'dddddddd-dddd-4ddd-8ddd-dddddddddddd',
            'checklist_version' => $this->canonicalChecklistVersion($apparatus),
            'operator_name' => 'John Doe',
            'rank' => 'Lieutenant',
            'completed_at' => now()->toDateTimeString(),
            'shift' => 'A',
            'unit_number' => 'E1',
            'compartments' => $this->canonicalCompartments($apparatus),
            'defects' => [],
        ];

        // Make POST request
        $response = $this->postJson("/api/public/apparatuses/{$apparatus->id}/inspections", $inspectionData);

        // Assert successful creation
        $response->assertStatus(201)
            ->assertJsonStructure([
                'id',
                'apparatus_id',
                'inspection_reference',
                'review_status',
                'completed_at',
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
     * Test that invalid apparatus ID returns appropriate error
     */
    public function test_returns_404_for_invalid_apparatus(): void
    {
        $inspectionData = [
            'operator_name' => 'John Doe',
            'rank' => 'Lieutenant',
            'completed_at' => now()->toDateTimeString(),
        ];

        $response = $this->postJson('/api/public/apparatuses/99999/inspections', $inspectionData);

        // Route model binding returns 404, or validation returns 422
        $this->assertTrue(
            in_array($response->status(), [404, 422]),
            "Expected 404 or 422 for invalid apparatus. Got: {$response->status()}"
        );
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
            'unit_id' => 'E1',
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
            'daily_checkout_requirement' => 'required',
        ]);

        // Missing required fields
        $response = $this->postJson("/api/public/apparatuses/{$apparatus->id}/inspections", []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['operator_name']);
    }

    /** @return list<array{id: string, name: string, items: list<array{id: string, name: string, status: string, notes: null}>}> */
    private function canonicalChecklistVersion(Apparatus $apparatus): string
    {
        $version = app(DailyCheckoutChecklistResolver::class)->resolve($apparatus)['checklist_version'];
        $this->assertIsString($version);

        return $version;
    }

    /** @return list<array{id: string, name: string, items: list<array{id: string, name: string, status: string, notes: null}>}> */
    private function canonicalCompartments(Apparatus $apparatus): array
    {
        $checklist = app(DailyCheckoutChecklistResolver::class)->resolve($apparatus)['checklist'];
        $this->assertIsArray($checklist);

        return array_map(static function (array $compartment): array {
            $compartmentId = (string) $compartment['id'];

            return [
                'id' => $compartmentId,
                'name' => (string) ($compartment['name'] ?? $compartment['title']),
                'items' => array_map(static fn (array $item, int $index): array => [
                    'id' => (string) ($item['id'] ?? "{$compartmentId}-item-".($index + 1)),
                    'name' => (string) $item['name'],
                    'status' => 'Present',
                    'notes' => null,
                ], $compartment['items'], array_keys($compartment['items'])),
            ];
        }, $checklist['compartments']);
    }
}
