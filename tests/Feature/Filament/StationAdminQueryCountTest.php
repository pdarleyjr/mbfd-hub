<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Resources\StationResource\Pages\ListStations;
use App\Filament\Resources\StationResource\Pages\ViewStation;
use App\Models\Station;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class StationAdminQueryCountTest extends TestCase
{
    use RefreshDatabase;
    use StationAdminFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpStationAdmin();
        $this->actingAsSuperAdmin();
    }

    public function test_station_list_query_count_does_not_scale_with_the_number_of_stations(): void
    {
        $first = $this->station(1);
        $this->apparatus($first, 'E1');
        $this->apparatus($first, 'L1');

        Livewire::test(ListStations::class)->call('loadTable')->assertSuccessful();

        $oneStationCount = $this->countQueries(fn () => Livewire::test(ListStations::class)->call('loadTable')->assertCanSeeTableRecords([$first]));

        foreach ([2, 3, 4, 6] as $number) {
            $station = $this->station($number);
            $this->apparatus($station, 'E'.$number);
            $this->apparatus($station, 'R'.$number);
        }

        $stations = Station::query()->get();
        $fiveStationCount = $this->countQueries(fn () => Livewire::test(ListStations::class)->call('loadTable')->assertCanSeeTableRecords($stations));

        self::assertSame($oneStationCount, $fiveStationCount, implode("\n", $this->lastQueries));
    }

    public function test_view_page_loads_staffing_and_readiness_counts_without_repeated_queries(): void
    {
        $station = $this->station(1);
        $this->apparatus($station, 'E1');
        $this->inventoryItem($station, 'low');

        $this->countQueries(fn () => Livewire::test(ViewStation::class, ['record' => $station->getRouteKey()])
            ->assertSuccessful()
            ->assertSee('Low Stock'));

        $queriesTouching = fn (string $table): int => collect($this->lastQueries)
            ->filter(fn (string $sql): bool => str_contains($sql, '"'.$table.'"'))
            ->count();

        self::assertSame(1, $queriesTouching('apparatuses'), 'Staffing figures must share one apparatus query per render.');
        self::assertSame(1, $queriesTouching('station_inventory_items'), 'Readiness counts must load in one query.');
    }
}
