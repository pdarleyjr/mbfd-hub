<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Apparatus;
use App\Models\Station;
use App\Services\Display\DisplaySnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class ApparatusDisplayCacheInvalidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_policy_status_and_station_assignment_changes_invalidate_display_read_models(): void
    {
        $apparatus = $this->makeApparatus();
        $replacementStation = $this->makeStation(2);

        foreach ([
            ['daily_checkout_requirement' => 'required'],
            ['status' => 'Out of Service'],
            ['station_id' => $replacementStation->id],
        ] as $changes) {
            $this->primeDisplayReadModels();

            $apparatus->update($changes);

            $this->assertFalse(Cache::has(DisplaySnapshotService::SNAPSHOT_CACHE_KEY));
            $this->assertFalse(Cache::has(DisplaySnapshotService::STATIONS_CACHE_KEY));
        }
    }

    public function test_unrelated_apparatus_changes_leave_display_read_models_cached(): void
    {
        $apparatus = $this->makeApparatus();
        $this->primeDisplayReadModels();

        $apparatus->update(['notes' => 'Updated service note.']);

        $this->assertSame('snapshot', Cache::get(DisplaySnapshotService::SNAPSHOT_CACHE_KEY));
        $this->assertSame('stations', Cache::get(DisplaySnapshotService::STATIONS_CACHE_KEY));
    }

    public function test_display_read_models_are_not_invalidated_until_the_relevant_change_commits(): void
    {
        $apparatus = $this->makeApparatus();
        $this->primeDisplayReadModels();

        DB::transaction(function () use ($apparatus): void {
            $apparatus->update(['status' => 'Out of Service']);

            $this->assertSame('snapshot', Cache::get(DisplaySnapshotService::SNAPSHOT_CACHE_KEY));
            $this->assertSame('stations', Cache::get(DisplaySnapshotService::STATIONS_CACHE_KEY));
        });

        $this->assertFalse(Cache::has(DisplaySnapshotService::SNAPSHOT_CACHE_KEY));
        $this->assertFalse(Cache::has(DisplaySnapshotService::STATIONS_CACHE_KEY));
    }

    public function test_rolled_back_relevant_apparatus_changes_leave_display_read_models_cached(): void
    {
        $apparatus = $this->makeApparatus();
        $this->primeDisplayReadModels();

        try {
            DB::transaction(function () use ($apparatus): void {
                $apparatus->update(['status' => 'Out of Service']);

                throw new \RuntimeException('Simulate an enclosing write failure.');
            });
        } catch (\RuntimeException) {
            // The cache must still describe the committed database state.
        }

        $this->assertSame('snapshot', Cache::get(DisplaySnapshotService::SNAPSHOT_CACHE_KEY));
        $this->assertSame('stations', Cache::get(DisplaySnapshotService::STATIONS_CACHE_KEY));
    }

    public function test_apparatus_addition_and_removal_invalidate_display_read_models(): void
    {
        $apparatus = $this->makeApparatus();
        $this->primeDisplayReadModels();

        $added = Apparatus::query()->create([
            'station_id' => $apparatus->station_id,
            'unit_id' => 'E2',
            'name' => 'Engine 2',
            'type' => 'Engine',
            'vehicle_number' => 'V2',
            'designation' => 'Engine 2',
            'slug' => 'engine-2',
            'make' => 'Pierce',
            'model' => 'Enforcer',
            'year' => 2020,
            'status' => 'In Service',
            'daily_checkout_requirement' => 'required',
        ]);

        $this->assertFalse(Cache::has(DisplaySnapshotService::SNAPSHOT_CACHE_KEY));
        $this->assertFalse(Cache::has(DisplaySnapshotService::STATIONS_CACHE_KEY));

        $this->primeDisplayReadModels();
        $added->delete();

        $this->assertFalse(Cache::has(DisplaySnapshotService::SNAPSHOT_CACHE_KEY));
        $this->assertFalse(Cache::has(DisplaySnapshotService::STATIONS_CACHE_KEY));
    }

    private function makeApparatus(): Apparatus
    {
        $station = $this->makeStation(1);

        return Apparatus::query()->create([
            'station_id' => $station->id,
            'unit_id' => 'E1',
            'name' => 'Engine 1',
            'type' => 'Engine',
            'vehicle_number' => 'V1',
            'designation' => 'Engine 1',
            'slug' => 'engine-1',
            'make' => 'Pierce',
            'model' => 'Enforcer',
            'year' => 2020,
            'status' => 'In Service',
            'daily_checkout_requirement' => 'unknown',
        ]);
    }

    private function makeStation(int $number): Station
    {
        return Station::query()->create([
            'station_number' => $number,
            'name' => "Station {$number}",
            'address' => "{$number} Main Street",
            'is_active' => true,
        ]);
    }

    private function primeDisplayReadModels(): void
    {
        Cache::put(DisplaySnapshotService::SNAPSHOT_CACHE_KEY, 'snapshot', now()->addMinutes(5));
        Cache::put(DisplaySnapshotService::STATIONS_CACHE_KEY, 'stations', now()->addMinutes(5));
    }
}
