<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Apparatus;
use App\Models\ApparatusDefect;
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
            'last_pm_date' => '2026-08-01',
            'last_pm_engine_hours' => 12.5,
            'last_pm_mileage' => 4_000,
            'current_engine_hours' => 18.5,
            'current_miles' => 5_000,
            'status' => 'In Service',
            'daily_checkout_requirement' => 'required',
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
        $this->assertStringNotContainsString('2026-08-01', $body);

        $row = $response->json('0');
        $this->assertArrayNotHasKey('vin', $row);
        $this->assertArrayNotHasKey('snipeit_asset_id', $row);
        $this->assertArrayNotHasKey('snipeit_asset_tag', $row);
        $this->assertArrayNotHasKey('notes', $row);
        $this->assertArrayNotHasKey('current_location', $row);
        $this->assertArrayNotHasKey('station_id', $row);
        $this->assertArrayNotHasKey('last_pm_date', $row);
        $this->assertArrayNotHasKey('last_pm_engine_hours', $row);
        $this->assertArrayNotHasKey('last_pm_mileage', $row);

        // Operational fields the daily-checkout UI relies on remain present.
        $this->assertArrayHasKey('id', $row);
        $this->assertArrayHasKey('status', $row);
        $this->assertArrayHasKey('current_engine_hours', $row);
        $this->assertArrayHasKey('current_miles', $row);
        $this->assertArrayHasKey('designation', $row);
        $this->assertSame('required', $row['daily_checkout_requirement']);
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
        $this->assertArrayNotHasKey('station_id', $apparatus);
        $this->assertArrayNotHasKey('last_pm_date', $apparatus);

        // The checklist payload itself is still returned.
        $this->assertArrayHasKey('checklist', $response->json());
    }

    public function test_public_apparatus_checklist_only_exposes_an_open_defect_count(): void
    {
        ApparatusDefect::create([
            'apparatus_id' => $this->apparatus->id,
            'compartment' => 'Cab',
            'item' => 'Flashlight',
            'status' => 'Missing',
            'issue_type' => 'missing',
            'reported_date' => now()->toDateString(),
            'notes' => 'SECRET defect note',
            'photo_path' => 'defects/private-photo.png',
            'resolution_notes' => 'SECRET resolution note',
            'defect_history' => [['notes' => 'SECRET defect history']],
            'resolved' => false,
        ]);

        $response = $this->getJson("/api/public/apparatuses/{$this->apparatus->id}/checklist");

        $response->assertOk()
            ->assertJsonPath('open_defects_count', 1);

        $body = $response->getContent();
        $this->assertStringNotContainsString('SECRET defect note', $body);
        $this->assertStringNotContainsString('private-photo.png', $body);
        $this->assertStringNotContainsString('SECRET resolution note', $body);
        $this->assertStringNotContainsString('SECRET defect history', $body);
        $this->assertArrayNotHasKey('open_defects', $response->json());
    }
}
