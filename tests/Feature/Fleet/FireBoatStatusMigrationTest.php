<?php

declare(strict_types=1);

namespace Tests\Feature\Fleet;

use App\Models\Apparatus;
use App\Models\Station;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Fire Boat 6 was the one required unit with no status, so every screen called it "Status unavailable". */
class FireBoatStatusMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_11_01_200000_set_fire_boat_6_status.php';

    public function test_the_boat_with_no_status_becomes_in_service_and_nothing_else_changes(): void
    {
        $boat = $this->unit('fb6', null);
        $other = $this->unit('e9', null);

        (require base_path(self::MIGRATION))->up();

        $this->assertSame('In Service', $boat->refresh()->status);
        $this->assertNull($other->refresh()->status);
    }

    public function test_a_status_someone_already_set_is_never_overwritten(): void
    {
        $boat = $this->unit('fb6', 'Out of Service');

        (require base_path(self::MIGRATION))->up();

        $this->assertSame('Out of Service', $boat->refresh()->status);
    }

    private function unit(string $slug, ?string $status): Apparatus
    {
        $station = Station::query()->firstOrCreate(['station_number' => 6], ['name' => 'Station 6', 'address' => '1 Fixture Way', 'is_active' => true]);
        $unit = strtoupper($slug);

        return Apparatus::query()->forceCreate([
            'station_id' => $station->id, 'unit_id' => $unit, 'name' => $unit, 'designation' => $unit, 'type' => 'Fire Boat', 'slug' => $slug, 'status' => $status,
            'daily_checkout_requirement' => 'required', 'daily_checkout_template' => 'fireboat6',
        ]);
    }
}
