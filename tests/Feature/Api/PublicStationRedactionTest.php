<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Apparatus;
use App\Models\ApparatusInspection;
use App\Models\CapitalProject;
use App\Models\FireEquipmentRequest;
use App\Models\Room;
use App\Models\RoomAsset;
use App\Models\SingleGasMeter;
use App\Models\Station;
use App\Models\StationInspection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * H-02: public station GET endpoints must redact internal/operational data
 * (personnel names, gas-meter serial numbers, project financials, internal
 * notes) while still emitting the fields the public daily-checkout UI needs.
 */
class PublicStationRedactionTest extends TestCase
{
    use RefreshDatabase;

    private Station $station;

    private Apparatus $apparatus;

    protected function setUp(): void
    {
        parent::setUp();

        $this->station = Station::create([
            'station_number' => 1,
            'name' => 'Station 1',
            'address' => '123 Main St',
            'city' => 'Miami Beach',
            'state' => 'FL',
            'zip_code' => '33139',
            'phone' => '305-555-0100',
            'is_active' => true,
            'notes' => 'SECRET internal station note',
        ]);

        $this->apparatus = Apparatus::create([
            'station_id' => $this->station->id,
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
            'status' => 'In Service',
            'notes' => 'SECRET apparatus maintenance note',
        ]);
    }

    public function test_stations_index_redacts_internal_fields(): void
    {
        $response = $this->getJson('/api/public/stations');
        $response->assertStatus(200);

        $body = $response->getContent();
        $this->assertStringNotContainsString('SECRET internal station note', $body);
        $this->assertStringNotContainsString('SECRETVIN123456789', $body);

        $station = $response->json('stations.0');
        $this->assertArrayNotHasKey('notes', $station);
        // Safe fields the UI lists with.
        $this->assertArrayHasKey('id', $station);
        $this->assertArrayHasKey('name', $station);
        $this->assertArrayHasKey('station_number', $station);
    }

    public function test_station_show_redacts_notes_and_financials(): void
    {
        CapitalProject::create([
            'station_id' => $this->station->id,
            'project_number' => 'CP-TEST-001',
            'name' => 'Roof Replacement',
            'budget_amount' => 250000,
            'status' => 'pending',
            'priority' => 'medium',
            'target_completion_date' => now()->addMonths(3)->toDateString(),
        ]);

        $response = $this->getJson("/api/public/stations/{$this->station->id}");
        $response->assertStatus(200);

        $body = $response->getContent();
        $this->assertStringNotContainsString('SECRET internal station note', $body);
        $this->assertStringNotContainsString('250000', $body);

        $data = $response->json();
        $this->assertArrayNotHasKey('notes', $data);

        // The public daily UI needs these top-level fields.
        $this->assertArrayHasKey('station_number', $data);
        $this->assertArrayHasKey('address', $data);
        $this->assertArrayHasKey('apparatuses', $data);

        // Project entries must not leak budget/spend financials.
        foreach (($data['capital_projects'] ?? []) as $project) {
            $this->assertArrayNotHasKey('budget', $project);
            $this->assertArrayNotHasKey('spent', $project);
        }
    }

    public function test_station_apparatus_redacts_vin_and_notes(): void
    {
        $response = $this->getJson("/api/public/stations/{$this->station->id}/apparatus");
        $response->assertStatus(200);

        $body = $response->getContent();
        $this->assertStringNotContainsString('SECRETVIN123456789', $body);
        $this->assertStringNotContainsString('SECRET apparatus maintenance note', $body);

        $apparatus = $response->json('apparatuses.0');
        $this->assertArrayNotHasKey('vin', $apparatus);
        $this->assertArrayNotHasKey('notes', $apparatus);
        $this->assertArrayNotHasKey('snipeit_asset_id', $apparatus);

        // Safe fields the apparatus tab renders.
        $this->assertArrayHasKey('id', $apparatus);
        $this->assertArrayHasKey('name', $apparatus);
        $this->assertArrayHasKey('vehicle_number', $apparatus);
        $this->assertArrayHasKey('type', $apparatus);
        $this->assertArrayHasKey('slug', $apparatus);
    }

    public function test_gas_meters_redact_serial_number(): void
    {
        SingleGasMeter::create([
            'apparatus_id' => $this->apparatus->id,
            'serial_number' => '98765',
            'activation_date' => now()->subMonths(6)->toDateString(),
        ]);

        $response = $this->getJson("/api/public/stations/{$this->station->id}/gas-meters");
        $response->assertStatus(200);

        $body = $response->getContent();
        // Full serial number must never appear in the public response.
        $this->assertStringNotContainsString('98765', $body);

        $meter = $response->json('gas_meters.0');
        $this->assertNotNull($meter);
        $this->assertSame("\u{2022}\u{2022}\u{2022}\u{2022}8765", $meter['serial_number']);
        // Safe fields the gas-meter tab renders.
        $this->assertArrayHasKey('id', $meter);
        $this->assertArrayHasKey('status', $meter);
        $this->assertArrayHasKey('expiration_date', $meter);
        $this->assertArrayHasKey('days_until_expiration', $meter);
        $this->assertArrayHasKey('apparatus_name', $meter);
    }

    public function test_equipment_requests_redact_requester_name(): void
    {
        FireEquipmentRequest::create([
            'station_id' => $this->station->id,
            'equipment_type' => 'Helmet',
            'description' => 'New helmet',
            'priority' => 'high',
            'status' => 'pending',
            'requested_by_name' => 'Captain Secret Person',
        ]);

        $response = $this->getJson("/api/public/stations/{$this->station->id}/equipment-requests");
        $response->assertStatus(200);

        $body = $response->getContent();
        $this->assertStringNotContainsString('Captain Secret Person', $body);

        $request = $response->json('equipment_requests.0');
        $this->assertNotNull($request);
        $this->assertArrayNotHasKey('requested_by_name', $request);
        // Safe operational fields the UI renders.
        $this->assertArrayHasKey('equipment_type', $request);
        $this->assertArrayHasKey('priority', $request);
        $this->assertArrayHasKey('status', $request);
    }

    public function test_apparatus_inspections_redact_operator_name(): void
    {
        ApparatusInspection::create([
            'apparatus_id' => $this->apparatus->id,
            'operator_name' => 'Operator Secret Name',
            'rank' => 'Lieutenant',
            'shift' => 'A',
            'completed_at' => now(),
            'review_status' => 'approved',
        ]);

        $response = $this->getJson("/api/public/stations/{$this->station->id}/apparatus-inspections");
        $response->assertStatus(200);

        $body = $response->getContent();
        $this->assertStringNotContainsString('Operator Secret Name', $body);

        $inspection = $response->json('inspections.0');
        $this->assertNotNull($inspection);
        $this->assertArrayNotHasKey('operator_name', $inspection);
        // Safe summary fields the "Today's Inspections" widget renders.
        $this->assertArrayHasKey('apparatus_name', $inspection);
        $this->assertArrayHasKey('shift', $inspection);
        $this->assertArrayHasKey('completed_at', $inspection);
        $this->assertArrayHasKey('defect_count', $inspection);
    }

    public function test_station_inspections_redact_inspector_name_and_notes(): void
    {
        $inspector = User::factory()->create(['name' => 'Inspector Secret']);

        StationInspection::create([
            'station_id' => $this->station->id,
            'inspector_id' => $inspector->id,
            'inspection_date' => now()->toDateString(),
            'inspection_type' => 'annual',
            'overall_status' => 'pass',
            'form_data' => [],
            'notes' => 'SECRET inspector notes',
        ]);

        $response = $this->getJson("/api/public/stations/{$this->station->id}/inspections");
        $response->assertStatus(200);

        $body = $response->getContent();
        $this->assertStringNotContainsString('Inspector Secret', $body);
        $this->assertStringNotContainsString('SECRET inspector notes', $body);

        $inspection = $response->json('inspections.0');
        $this->assertNotNull($inspection);
        $this->assertArrayNotHasKey('inspector_name', $inspection);
        $this->assertArrayNotHasKey('notes', $inspection);
        // Safe summary fields.
        $this->assertArrayHasKey('inspection_type', $inspection);
        $this->assertArrayHasKey('overall_status', $inspection);
        $this->assertArrayHasKey('inspection_date', $inspection);
    }

    public function test_room_assets_redact_serial_and_financials(): void
    {
        $room = Room::create([
            'station_id' => $this->station->id,
            'name' => 'Storage',
            'room_type' => 'storage',
        ]);

        RoomAsset::create([
            'room_id' => $room->id,
            'name' => 'Generator',
            'category' => 'Equipment',
            'serial_number' => 'ASSET-SN-SECRET-55',
            'purchase_price' => 8200,
        ]);

        $response = $this->getJson("/api/public/stations/{$this->station->id}/rooms/{$room->id}/assets");
        $response->assertStatus(200);

        $body = $response->getContent();
        $this->assertStringNotContainsString('ASSET-SN-SECRET-55', $body);
        $this->assertStringNotContainsString('8200', $body);

        $asset = $response->json('assets.0');
        $this->assertNotNull($asset);
        $this->assertArrayNotHasKey('serial_number', $asset);
        $this->assertArrayNotHasKey('purchase_price', $asset);
        // Safe fields.
        $this->assertArrayHasKey('id', $asset);
        $this->assertArrayHasKey('name', $asset);
        $this->assertArrayHasKey('category', $asset);
    }
}
