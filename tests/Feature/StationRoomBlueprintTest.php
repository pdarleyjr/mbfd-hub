<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Station;
use App\Services\StationRoomBlueprintService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StationRoomBlueprintTest extends TestCase
{
    use RefreshDatabase;

    public function test_blueprints_create_every_required_dorm_position_for_each_station(): void
    {
        $expectedBeds = [1 => 14, 2 => 12, 3 => 11, 4 => 10, 6 => 4];

        foreach ($expectedBeds as $stationNumber => $beds) {
            $station = $this->station($stationNumber);
            app(StationRoomBlueprintService::class)->sync($station);

            $this->assertSame(
                $beds,
                $station->rooms()->where('type', 'dormitory')->sum('capacity'),
                "Station {$stationNumber} must have one dorm position per assigned member."
            );
        }

        $stationOneDorms = Station::query()->where('station_number', 1)->sole()
            ->rooms()->where('type', 'dormitory')->orderBy('sort_order')->pluck('name')->all();
        $this->assertSame([
            'Combat officer dorm room',
            'Combat firefighter dorm room',
            'Rescue dorm room',
        ], $stationOneDorms);
        $this->assertNotContains('Rescue officer dorm room', $stationOneDorms);

        $stationTwoDorms = Station::query()->where('station_number', 2)->sole()
            ->rooms()->where('type', 'dormitory')->pluck('name')->all();
        $this->assertContains('Captain 5 dorm room', $stationTwoDorms);
        $this->assertContains('300 dorm room', $stationTwoDorms);

        $stationSixDorms = Station::query()->where('station_number', 6)->sole()
            ->rooms()->where('type', 'dormitory')->orderBy('sort_order')->pluck('name')->all();
        $this->assertSame([
            'Fireboat officer dorm room',
            'Fireboat firefighter dorm room',
        ], $stationSixDorms);
    }

    public function test_blueprints_create_station_specific_apparatus_positions_and_are_idempotent(): void
    {
        $stationOne = $this->station(1);
        $service = app(StationRoomBlueprintService::class);

        $service->sync($stationOne);
        $firstCount = $stationOne->rooms()->count();
        $service->sync($stationOne);

        $this->assertSame($firstCount, $stationOne->rooms()->count());
        $this->assertSame([
            'E1 apparatus bay position',
            'L1 apparatus bay position',
        ], $stationOne->rooms()->where('type', 'combat_apparatus_bay')->orderBy('sort_order')->pluck('name')->all());
        $this->assertSame([
            'R1 apparatus bay position',
            'R11 apparatus bay position',
        ], $stationOne->rooms()->where('type', 'rescue_apparatus_bay')->orderBy('sort_order')->pluck('name')->all());

        $stationTwo = $this->station(2);
        $service->sync($stationTwo);
        $this->assertSame([
            '300 apparatus bay position',
            'Captain 5 apparatus bay position',
            'Air Truck apparatus bay position',
        ], $stationTwo->rooms()->where('type', 'support_apparatus_bay')->orderBy('sort_order')->pluck('name')->all());

        $stationSix = $this->station(6);
        $service->sync($stationSix);
        $this->assertSame(
            ['Fire Boat 6 berth / apparatus area'],
            $stationSix->rooms()->where('type', 'fireboat_apparatus_area')->pluck('name')->all()
        );
    }

    public function test_public_rooms_endpoint_exposes_blueprint_keys_capacity_and_stable_order(): void
    {
        $station = $this->station(1);
        app(StationRoomBlueprintService::class)->sync($station);

        $response = $this->getJson("/api/public/stations/{$station->id}/rooms")
            ->assertOk()
            ->assertJsonPath('rooms.0.name', 'Kitchen')
            ->assertJsonPath('rooms.0.type', 'kitchen')
            ->assertJsonPath('rooms.0.blueprint_key', 'kitchen.main');

        $rooms = collect($response->json('rooms'));
        $this->assertSame(6, $rooms->firstWhere('blueprint_key', 'dorm.combat_firefighters')['capacity']);
        $this->assertNull($rooms->firstWhere('blueprint_key', 'office.station')['notes'] ?? null);
    }

    private function station(int $stationNumber): Station
    {
        return Station::query()->create([
            'station_number' => $stationNumber,
            'name' => "Station {$stationNumber}",
            'address' => "{$stationNumber} Blueprint Way",
            'is_active' => true,
        ]);
    }
}
