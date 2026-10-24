<?php

declare(strict_types=1);

namespace Tests\Feature\Exports;

use App\Filament\Resources\InventoryLocationResource\Pages\ListInventoryLocations;
use App\Filament\Resources\ShopWorkResource\Pages\CreateShopWork;
use App\Filament\Resources\ShopWorkResource\Pages\ListShopWorks;
use App\Filament\Resources\StationResource\Pages\ListStations;
use App\Models\InventoryLocation;
use App\Models\Station;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/** Reference-data tables gained filters and predictable default order in the admin table audit. */
final class AdminTableCapabilitiesTest extends ExportTestCase
{
    private function shopWork(string $name, string $status, ?string $completed): int
    {
        return DB::table('shop_works')->insertGetId([
            'project_name' => $name, 'status' => $status, 'completed_date' => $completed,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_shop_needs_can_be_filtered_by_status_and_completion(): void
    {
        $open = $this->shopWork('Replace bay door seal', 'Pending', null);
        $done = $this->shopWork('Rebuild pump cart', 'Completed', '2026-09-30');
        $started = $this->shopWork('Repaint hose bed', 'In Progress', null);
        $model = \App\Models\ShopWork::class;

        Livewire::test(ListShopWorks::class)->call('loadTable')
            ->assertTableFilterExists('status')
            ->assertTableFilterExists('completed')
            ->assertCanSeeTableRecords($model::query()->whereKey([$open, $done, $started])->get())
            ->filterTable('completed', true)
            ->assertCanSeeTableRecords($model::query()->whereKey([$done])->get())
            ->assertCanNotSeeTableRecords($model::query()->whereKey([$open, $started])->get())
            ->filterTable('completed', false)
            ->assertCanSeeTableRecords($model::query()->whereKey([$open, $started])->get())
            ->assertCanNotSeeTableRecords($model::query()->whereKey([$done])->get())
            ->removeTableFilters()
            ->filterTable('status', 'In Progress')
            ->assertCanSeeTableRecords($model::query()->whereKey([$started])->get())
            ->assertCanNotSeeTableRecords($model::query()->whereKey([$open, $done])->get());
    }

    public function test_shop_need_status_is_a_choice_that_matches_what_the_database_accepts(): void
    {
        Livewire::test(CreateShopWork::class)
            ->fillForm(['project_name' => 'Replace bay door seal', 'status' => 'Waiting for Parts'])
            ->call('create')
            ->assertHasNoFormErrors();
        self::assertSame('Waiting for Parts', \App\Models\ShopWork::query()->sole()->status);

        Livewire::test(CreateShopWork::class)
            ->fillForm(['project_name' => 'Bad status', 'status' => 'Done'])
            ->call('create')
            ->assertHasFormErrors(['status']);
    }

    public function test_shop_needs_list_newest_first_by_default(): void
    {
        $old = $this->shopWork('Older job', 'Pending', null);
        DB::table('shop_works')->where('id', $old)->update(['created_at' => now()->subDays(5)]);
        $new = $this->shopWork('Newer job', 'Pending', null);
        $model = \App\Models\ShopWork::class;

        Livewire::test(ListShopWorks::class)->call('loadTable')
            ->assertCanSeeTableRecords($model::query()->whereKey([$new, $old])->orderByDesc('created_at')->get(), inOrder: true);
    }

    public function test_inventory_locations_and_stations_open_in_a_predictable_order(): void
    {
        $zulu = InventoryLocation::query()->create(['location_name' => 'Zulu Cage']);
        $alpha = InventoryLocation::query()->create(['location_name' => 'Alpha Cage']);
        Livewire::test(ListInventoryLocations::class)->call('loadTable')
            ->assertCanSeeTableRecords([$alpha, $zulu], inOrder: true);

        $four = Station::query()->create(['station_number' => 4, 'name' => 'Station 4', 'address' => '6880 Indian Creek Drive', 'is_active' => true]);
        $one = Station::query()->create(['station_number' => 1, 'name' => 'Station 1', 'address' => '2300 Pine Tree Drive', 'is_active' => true]);
        Livewire::test(ListStations::class)->call('loadTable')
            ->assertCanSeeTableRecords([$one, $four], inOrder: true);
    }
}
