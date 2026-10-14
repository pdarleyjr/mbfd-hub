<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Apparatus;
use App\Models\CapitalProject;
use App\Models\Room;
use App\Models\ShopWork;
use App\Models\Station;
use App\Models\Under25kProject;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

final class StationApiConsistencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_member_station_list_hides_inactive_stations_but_admin_list_keeps_them(): void
    {
        $active = $this->station(1);
        $inactive = $this->station(5, ['is_active' => false]);
        $this->apparatus($inactive, 'E5');

        $this->actingAsCanonicalFixture();
        $public = $this->getJson('/api/public/stations')->assertOk();
        self::assertSame([$active->id], collect($public->json('stations'))->pluck('id')->all());
        self::assertSame(1, $public->json('total'));

        // A direct link to an inactive station still opens so existing apparatus
        // keep their Daily Checkout status; the page shows the inactive state.
        $this->getJson("/api/public/stations/{$inactive->id}")
            ->assertOk()
            ->assertJsonPath('is_active', false)
            ->assertJsonCount(1, 'daily_checkout.matrix');

        $this->actingAsStationViewer();
        $admin = $this->getJson('/api/admin/stations')->assertOk();
        self::assertEqualsCanonicalizing([$active->id, $inactive->id], collect($admin->json('stations'))->pluck('id')->all());
    }

    public function test_member_station_cards_do_not_ship_every_apparatus_and_room(): void
    {
        $station = $this->station(1);
        $this->apparatus($station, 'E1');
        Room::query()->create(['station_id' => $station->id, 'name' => 'Kitchen', 'room_type' => 'kitchen']);

        $this->actingAsCanonicalFixture();
        $card = $this->getJson('/api/public/stations')->assertOk()->json('stations.0');

        self::assertArrayNotHasKey('apparatuses', $card);
        self::assertArrayNotHasKey('rooms', $card);
        self::assertSame(1, $card['rooms_count']);
        self::assertTrue($card['staffing_known']);
        self::assertSame(4, $card['assigned_apparatus_count']);
        self::assertSame(1, $card['in_service_assigned_count']);
    }

    public function test_member_station_list_query_count_does_not_scale_with_stations(): void
    {
        $first = $this->station(1);
        $this->apparatus($first, 'E1');
        $this->actingAsCanonicalFixture();
        $this->getJson('/api/public/stations')->assertOk();

        $one = $this->countQueries(fn () => $this->getJson('/api/public/stations')->assertOk());
        foreach ([2, 3, 4] as $number) {
            $this->apparatus($this->station($number), 'E'.$number);
        }
        $four = $this->countQueries(fn () => $this->getJson('/api/public/stations')->assertOk()->assertJsonCount(4, 'stations'));

        self::assertSame($one, $four);
    }

    public function test_station_detail_totals_agree_with_the_lists_it_returns(): void
    {
        Schema::table('rooms', function (Blueprint $table): void {
            $table->boolean('is_active')->default(true);
        });
        $station = $this->station(1);
        $engine = $this->apparatus($station, 'E1');
        Room::query()->create(['station_id' => $station->id, 'name' => 'Kitchen', 'room_type' => 'kitchen', 'is_active' => true]);
        Room::query()->create(['station_id' => $station->id, 'name' => 'Old Kitchen', 'room_type' => 'kitchen', 'is_active' => false]);
        Room::query()->create(['station_id' => $station->id, 'name' => 'Dorm', 'room_type' => 'dormitory', 'is_active' => true]);
        $this->capitalProject($station, 'pending', 1000);
        $this->capitalProject($station, 'pending', 2500);
        $this->capitalProject($station, 'completed', 99999);
        $this->under25kProject($station, 'Planning', 400);
        $this->under25kProject($station, 'Completed', 7777);
        foreach (range(1, 12) as $index) {
            (new ShopWork)->forceFill(['project_name' => "Repair {$index}", 'apparatus_id' => $engine->id, 'status' => 'Pending'])->save();
        }

        $this->actingAsStationViewer();
        $data = $this->getJson("/api/admin/stations/{$station->id}")->assertOk()->json();

        self::assertCount(2, $data['capital_projects']);
        self::assertSame(2, $data['capital_projects_count']);
        self::assertSame(2, $data['summary']['project_totals']['capital_projects']['active']);
        self::assertEquals(3500.0, $data['summary']['project_totals']['capital_projects']['budget']);
        self::assertCount(1, $data['under_25k_projects']);
        self::assertSame(1, $data['under_25k_projects_count']);
        self::assertSame(1, $data['summary']['project_totals']['under_25k_projects']['active']);
        self::assertEquals(400.0, $data['summary']['project_totals']['under_25k_projects']['budget']);
        self::assertCount(2, $data['rooms']);
        self::assertSame(2, $data['rooms_count']);
        self::assertEquals(['kitchen' => 1, 'dormitory' => 1], $data['summary']['rooms_by_type']);
        self::assertEquals(['Engine' => 1], $data['summary']['apparatus_by_type']);
        self::assertCount(10, $data['shop_works']);
        self::assertSame(12, $data['shop_works_count']);

        $this->actingAsCanonicalFixture();
        $public = $this->getJson("/api/public/stations/{$station->id}")->assertOk()->json();
        self::assertArrayNotHasKey('shop_works', $public);
        self::assertArrayNotHasKey('project_totals', $public['summary']);
        self::assertSame(12, $public['shop_works_count']);
        self::assertCount(2, $public['capital_projects']);
        self::assertSame(2, $public['capital_projects_count']);
        self::assertEquals(['kitchen' => 1, 'dormitory' => 1], $public['summary']['rooms_by_type']);
        foreach (['id', 'name', 'station_number', 'address', 'city', 'state', 'zip_code', 'phone', 'is_active',
            'apparatuses', 'rooms', 'daily_checkout', 'assigned_apparatus_count', 'in_service_assigned_count',
            'assigned_personnel_count', 'dorm_beds_count', 'staffing_known', 'rooms_count'] as $consumed) {
            self::assertArrayHasKey($consumed, $public);
        }
    }

    private function countQueries(callable $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $callback();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    private function actingAsStationViewer(): User
    {
        $user = User::factory()->create(['account_status' => 'active']);
        $user->givePermissionTo(collect(['admin.access', 'admin.stations.view'])
            ->map(fn (string $permission) => Permission::findOrCreate($permission, 'web')));
        $this->actingAsCanonicalUser($user);

        return $user;
    }

    /** @param array<string, mixed> $overrides */
    private function station(int $number, array $overrides = []): Station
    {
        return Station::query()->create(array_merge([
            'station_number' => $number,
            'name' => "Station {$number}",
            'address' => "{$number} Test Street",
            'city' => 'Miami Beach',
            'state' => 'FL',
            'zip_code' => '33139',
            'is_active' => true,
        ], $overrides));
    }

    private function apparatus(Station $station, string $unit): Apparatus
    {
        return Apparatus::query()->create([
            'station_id' => $station->id,
            'unit_id' => $unit,
            'designation' => $unit,
            'name' => $unit,
            'type' => 'Engine',
            'vehicle_number' => 'V-'.$unit,
            'slug' => strtolower($unit).'-'.uniqid(),
            'make' => 'Fixture',
            'model' => 'Engine',
            'status' => 'In Service',
            'daily_checkout_requirement' => 'required',
        ]);
    }

    private function capitalProject(Station $station, string $status, float $budget): CapitalProject
    {
        return CapitalProject::query()->create([
            'station_id' => $station->id,
            'project_number' => 'CP-'.uniqid(),
            'name' => 'Capital '.uniqid(),
            'budget_amount' => $budget,
            'status' => $status,
            'priority' => 'medium',
        ]);
    }

    private function under25kProject(Station $station, string $status, float $budget): Under25kProject
    {
        return Under25kProject::query()->create([
            'station_id' => $station->id,
            'project_number' => 'FIRE-'.uniqid(),
            'name' => 'Small '.uniqid(),
            'budget_amount' => $budget,
            'status' => $status,
            'priority' => 'medium',
        ]);
    }
}
