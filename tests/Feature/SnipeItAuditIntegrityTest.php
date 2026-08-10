<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\AuditEquipmentAfterInspection;
use App\Models\Apparatus;
use App\Models\ApparatusInspection;
use App\Models\Station;
use App\Services\SnipeItService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class SnipeItAuditIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_manifest_asset_not_present_on_checklist_is_not_audited_as_inspected(): void
    {
        $station = Station::create([
            'station_number' => 1,
            'name' => 'Station 1',
            'address' => '1051 Jefferson Avenue',
            'is_active' => true,
        ]);
        $apparatus = Apparatus::create([
            'station_id' => $station->id,
            'unit_id' => 'E1',
            'name' => 'Engine 1',
            'type' => 'Engine',
            'vehicle_number' => '1001',
            'designation' => 'E1',
            'slug' => 'engine-1',
            'make' => 'Pierce',
            'model' => 'Enforcer',
            'year' => 2020,
            'status' => 'In Service',
            'snipeit_asset_id' => 42,
            'snipeit_asset_tag' => 'APP-42',
        ]);
        $inspection = ApparatusInspection::create([
            'apparatus_id' => $apparatus->id,
            'operator_name' => 'Firefighter Test',
            'rank' => 'Firefighter',
            'unit_number' => '1001',
            'inspection_reference' => 'INS-E1-2026-08-10-0001',
            'results' => [[
                'title' => 'Cab',
                'items' => [[
                    'name' => 'Flashlight',
                    'status' => 'Present',
                ]],
            ]],
            'completed_at' => now(),
        ]);

        $snipeIt = Mockery::mock(SnipeItService::class);
        $snipeIt->shouldReceive('getAssetsCheckedOutTo')->once()->with(42)->andReturn([
            ['id' => 9001, 'asset_tag' => 'EQ-9001', 'name' => 'Thermal Imaging Camera'],
        ]);
        $snipeIt->shouldNotReceive('auditAsset');
        $snipeIt->shouldNotReceive('updateAssetStatus');
        $snipeIt->shouldNotReceive('createMaintenanceRecord');

        (new AuditEquipmentAfterInspection($inspection->id, $apparatus->id))->handle($snipeIt);

        $this->addToAssertionCount(1);
    }
}
