<?php

declare(strict_types=1);

namespace Tests\Feature\Api\Display;

use App\Models\Apparatus;
use App\Models\ApparatusDefect;
use App\Models\ApparatusInspection;
use App\Models\Station;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The snapshot rollup returns the discovery-report §9 schema with computed
 * readiness (percent + status + human-readable reasons), never a bare score.
 */
class DisplaySnapshotTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $token = Str::random(48);
        config(['services.display_api.token' => $token]);
        $this->withHeader('X-Display-Token', $token);
    }

    private function seedStationWithData(): Station
    {
        $station = Station::create([
            'station_number' => 1,
            'name' => 'Station 1',
            'address' => '1051 Jefferson Ave',
            'is_active' => true,
        ]);

        $apparatus = Apparatus::create([
            'unit_id' => 'E1',
            'station_id' => $station->id,
            'designation' => 'Engine 1',
            'make' => 'Pierce',
            'model' => 'Enforcer',
            'year' => 2020,
            'status' => 'In Service',
            'daily_checkout_requirement' => 'required',
        ]);

        ApparatusInspection::create([
            'client_submission_id' => (string) Str::uuid(),
            'apparatus_id' => $apparatus->id,
            'operator_name' => 'Jane Roe',
            'rank' => 'Lieutenant',
            'shift' => 'A',
            'completed_at' => now(),
        ]);

        ApparatusDefect::create([
            'apparatus_id' => $apparatus->id,
            'compartment' => 'Cab',
            'item' => 'Flashlight',
            'status' => 'Missing',
            'notes' => 'internal note',
            'resolved' => false,
        ]);

        return $station;
    }

    public function test_snapshot_returns_expected_top_level_keys(): void
    {
        $this->seedStationWithData();

        $response = $this->getJson('/api/display/snapshot');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'metadata' => ['generated_at', 'cache_ttl_seconds', 'environment'],
                'organization' => ['name'],
                'overview' => [
                    'stations_total',
                    'stations_active',
                    'apparatus_total',
                    'apparatus_status' => ['in_service', 'out_of_service', 'maintenance'],
                    'pm_health' => ['green', 'yellow', 'red', 'critical_overdue'],
                    'readiness_percent',
                ],
                'stations',
                'defects' => ['total_open', 'critical_missing', 'items'],
                'submissions' => [
                    'inspections' => ['today', 'this_week', 'this_month', 'pending_review'],
                    'station_inspections' => ['pending_review', 'pass_rate_30d'],
                    'inventory',
                ],
                'requests' => [
                    'station_requests' => ['open', 'critical_open', 'repair_service_open', 'equipment_open'],
                    'employee_equipment' => ['pending'],
                ],
                'inventory_exceptions' => ['total_active_items', 'out_of_stock', 'low_stock', 'items'],
                'source_health' => [
                    'hub_up',
                    'ai_available',
                    'incidents_worker_up',
                    'last_deploy_sha',
                    'snapshot_age_seconds',
                ],
            ]);
    }

    public function test_snapshot_counts_are_accurate(): void
    {
        $this->seedStationWithData();

        $data = $this->getJson('/api/display/snapshot')->json();

        $this->assertSame(1, $data['overview']['stations_active']);
        $this->assertSame(1, $data['overview']['apparatus_total']);
        $this->assertSame(1, $data['overview']['apparatus_status']['in_service']);
        $this->assertSame(1, $data['defects']['total_open']);
        $this->assertSame(1, $data['defects']['critical_missing']);
        $this->assertSame('Miami Beach Fire Department', $data['organization']['name']);
    }

    public function test_station_rows_include_readiness_with_reasons(): void
    {
        $this->seedStationWithData();

        $data = $this->getJson('/api/display/snapshot')->json();

        $this->assertNotEmpty($data['stations']);
        $row = $data['stations'][0];

        $this->assertArrayHasKey('readiness_percent', $row);
        $this->assertArrayHasKey('readiness_status', $row);
        $this->assertArrayHasKey('readiness_reasons', $row);
        $this->assertSame(4, $row['assigned_apparatus_count']);
        $this->assertSame(1, $row['daily_checkout']['required_count']);
        $this->assertIsInt($row['readiness_percent']);
        $this->assertContains($row['readiness_status'], ['READY', 'ATTENTION', 'INCOMPLETE', 'CRITICAL', 'UNKNOWN']);
        $this->assertIsArray($row['readiness_reasons']);
        $this->assertNotEmpty($row['readiness_reasons'], 'readiness_reasons must never be empty');
    }

    public function test_stations_endpoint_returns_slim_grid(): void
    {
        $this->seedStationWithData();

        $data = $this->getJson('/api/display/stations')->json();

        $this->assertArrayHasKey('stations', $data);
        $this->assertCount(1, $data['stations']);
        $this->assertArrayHasKey('readiness_reasons', $data['stations'][0]);
    }

    public function test_station_detail_returns_404_for_missing_station(): void
    {
        $this->getJson('/api/display/stations/999999')->assertStatus(404);
    }

    public function test_station_detail_returns_counts_and_readiness(): void
    {
        $station = $this->seedStationWithData();

        $data = $this->getJson("/api/display/stations/{$station->id}")->json();

        $this->assertSame($station->id, $data['station']['id']);
        $this->assertSame(1, $data['counts']['inspections_today']);
        $this->assertSame(0, $data['counts']['daily_checkout']['checked_count']);
        $this->assertSame(1, $data['counts']['daily_checkout']['attention_count']);
        $this->assertSame(1, $data['counts']['open_defects']);
        $this->assertArrayHasKey('readiness', $data);
        $this->assertNotSame('READY', $data['readiness']['status']);
        $this->assertNotEmpty($data['readiness']['reasons']);
    }
}
