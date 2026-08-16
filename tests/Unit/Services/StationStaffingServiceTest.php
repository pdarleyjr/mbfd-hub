<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Apparatus;
use App\Models\Station;
use App\Services\StationStaffingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class StationStaffingServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_authoritative_assigned_complements_and_staffing_are_exact(): void
    {
        $service = app(StationStaffingService::class);

        $expected = [
            1 => [['E1', 'L1', 'R1', 'R11'], 14, 14],
            2 => [['300', 'Captain 5', 'E2', 'R2', 'R22', 'Air Truck'], 12, 12],
            3 => [['E3', 'L3', 'R3'], 11, 11],
            4 => [['E4', 'R4', 'R44'], 10, 10],
            6 => [['FB6'], 4, 4],
        ];

        foreach ($expected as $stationNumber => [$units, $personnel, $beds]) {
            $summary = $service->summaryForStationNumber($stationNumber);
            $this->assertTrue($summary['known']);
            $this->assertSame($units, $summary['assigned_units']);
            $this->assertSame(count($units), $summary['assigned_apparatus_count']);
            $this->assertSame($personnel, $summary['assigned_personnel_count']);
            $this->assertSame($beds, $summary['dorm_beds_count']);
        }
    }

    public function test_assigned_complement_is_not_conflated_with_live_availability(): void
    {
        $station = Station::query()->create([
            'station_number' => 1,
            'name' => 'Station 1',
            'address' => '1 Test Street',
            'is_active' => true,
        ]);
        $this->makeApparatus($station, 'E1', 'In Service');
        $this->makeApparatus($station, 'L1', 'Out of Service');
        $this->makeApparatus($station, 'R1', 'In Service');
        $this->makeApparatus($station, 'R11', 'Maintenance');
        $this->makeApparatus($station, 'Reserve 1', 'In Service');

        $summary = app(StationStaffingService::class)->summaryFor($station);

        $this->assertSame(4, $summary['assigned_apparatus_count']);
        $this->assertSame(2, $summary['in_service_assigned_count']);
        $this->assertSame(1, $summary['out_of_service_assigned_count']);
        $this->assertSame(1, $summary['maintenance_assigned_count']);
        $this->assertSame(['L1', 'R11'], $summary['unavailable_assigned_units']);
        $this->assertSame(['Reserve 1'], $summary['unmapped_station_units']);
    }

    public function test_unknown_station_is_explicitly_reported_and_logged(): void
    {
        Log::spy();

        $summary = app(StationStaffingService::class)->summaryForStationNumber(99);

        $this->assertFalse($summary['known']);
        $this->assertNull($summary['assigned_apparatus_count']);
        $this->assertNull($summary['assigned_personnel_count']);
        $this->assertNull($summary['dorm_beds_count']);
        Log::shouldHaveReceived('warning')->once();
    }

    private function makeApparatus(Station $station, string $designation, string $status): void
    {
        Apparatus::query()->create([
            'station_id' => $station->id,
            'unit_id' => $designation.'-'.uniqid(),
            'designation' => $designation,
            'name' => $designation,
            'type' => 'Test',
            'make' => 'Test',
            'model' => 'Test',
            'year' => 2026,
            'status' => $status,
        ]);
    }
}
