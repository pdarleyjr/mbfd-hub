<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Apparatus;
use App\Models\Station;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * H-02 (follow-up): the top-level public apparatus endpoints
 * (ApparatusController@index and @checklist) must not leak internal/identifying
 * apparatus fields (VIN, Snipe-IT asset ids, internal notes, physical location)
 * while still emitting the operational/status fields the daily-checkout SPA needs.
 */
class PublicApparatusIndexRedactionTest extends TestCase
{
    use RefreshDatabase;

    private Apparatus $apparatus;

    protected function setUp(): void
    {
        parent::setUp();

        $station = Station::create([
            'station_number' => 1,
            'name' => 'Station 1',
            'address' => '123 Main St',
            'city' => 'Miami Beach',
            'state' => 'FL',
            'zip_code' => '33139',
            'is_active' => true,
        ]);

        $this->apparatus = Apparatus::create([
            'station_id' => $station->id,
            'unit_id' => 'E1-'.uniqid(),
            'name' => 'Engine 1',
            'type' => 'Engine',
            'vehicle_number' => 'V100',
            'designation' => 'E1',
            'slug' => 'engine-1-'.uniqid(),
            'make' => 'Pierce',
            'model' => 'Enforcer',
            'year' => 2020,
            'vin' => 'SECRETVIN123456789',
            'snipeit_asset_id' => 4242,
            'snipeit_asset_tag' => 'MBFD-SECRET-TAG',
            'current_location' => 'Secret Bay 3',
            'status' => 'In Service',
            'notes' => 'SECRET apparatus maintenance note',
        ]);
    }

    public function test_public_apparatus_index_redacts_internal_fields(): void
    {
        $response = $this->getJson('/api/public/apparatuses');
        $response->assertStatus(200);

        $body = $response->getContent();
        $this->assertStringNotContainsString('SECRETVIN123456789', $body);
        $this->assertStringNotContainsString('MBFD-SECRET-TAG', $body);
        $this->assertStringNotContainsString('SECRET apparatus maintenance note', $body);
        $this->assertStringNotContainsString('Secret Bay 3', $body);

        $row = $response->json('0');
        $this->assertArrayNotHasKey('vin', $row);
        $this->assertArrayNotHasKey('snipeit_asset_id', $row);
        $this->assertArrayNotHasKey('snipeit_asset_tag', $row);
        $this->assertArrayNotHasKey('notes', $row);
        $this->assertArrayNotHasKey('current_location', $row);

        // Operational fields the daily-checkout UI relies on remain present.
        $this->assertArrayHasKey('id', $row);
        $this->assertArrayHasKey('status', $row);
        $this->assertArrayHasKey('pm_health', $row);
        $this->assertArrayHasKey('designation', $row);
    }

    public function test_public_apparatus_checklist_redacts_internal_fields(): void
    {
        $response = $this->getJson("/api/public/apparatuses/{$this->apparatus->id}/checklist");
        $response->assertStatus(200);

        $body = $response->getContent();
        $this->assertStringNotContainsString('SECRETVIN123456789', $body);
        $this->assertStringNotContainsString('MBFD-SECRET-TAG', $body);
        $this->assertStringNotContainsString('Secret Bay 3', $body);

        $apparatus = $response->json('apparatus');
        $this->assertArrayNotHasKey('vin', $apparatus);
        $this->assertArrayNotHasKey('snipeit_asset_id', $apparatus);
        $this->assertArrayNotHasKey('current_location', $apparatus);

        // The checklist payload itself is still returned.
        $this->assertArrayHasKey('checklist', $response->json());
    }
}
