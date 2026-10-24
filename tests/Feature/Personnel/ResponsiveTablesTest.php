<?php

declare(strict_types=1);

namespace Tests\Feature\Personnel;

use App\Filament\Pages\MasterPersonnelDirectory;
use App\Filament\Resources\UnitMasterVehicleResource\Pages\ListUnitMasterVehicles;
use App\Models\Employee;
use App\Models\EmployeeBidAssignment;
use App\Models\UnitMasterVehicle;
use Filament\Support\Enums\MaxWidth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Filament\StationAdminFixtures;
use Tests\TestCase;

/** The compact layouts must not lose a column, a sort, a search, a filter or an export column. */
final class ResponsiveTablesTest extends TestCase
{
    use RefreshDatabase;
    use StationAdminFixtures;

    private const DIRECTORY_COLUMNS = ['name', 'employee_id', 'city_email', 'rank', 'shift', 'a_day', 'station', 'division', 'unit', 'bid_position', 'email_match_status'];

    private const VEHICLE_COLUMNS = ['veh_number', 'tag_number', 'asset_category', 'section', 'dept_code', 'make', 'model', 'year', 'color', 'fleet_status_code',
        'fleet_class_description', 'employee_or_vehicle_name', 'assignment', 'location', 'latestTakeHomeInspection.submitted_at', 'pending_changes_count',
        'visible_documents_count', 'needs_fleet_review', 'als_license', 'serial_number', 'sunpass_number'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpStationAdmin();
    }

    public function test_every_directory_column_is_still_available_and_hidden_ones_can_be_switched_on(): void
    {
        $this->actingAsAdminWith(['personnel.directory.view']);
        $table = Livewire::test(MasterPersonnelDirectory::class)->instance()->getTable();

        foreach (self::DIRECTORY_COLUMNS as $key) {
            $column = $table->getColumn($key);
            self::assertNotNull($column, $key.' must still be a column');
            self::assertTrue($column->isToggleable(), $key.' must stay in the column chooser');
            self::assertTrue($column->isSortable() || $key === 'email_match_status', $key.' must stay sortable');
        }
        $hidden = array_values(array_filter(self::DIRECTORY_COLUMNS, fn (string $key): bool => $table->getColumn($key)->isToggledHiddenByDefault()));
        self::assertSame(['a_day', 'division', 'bid_position', 'email_match_status'], array_values(array_intersect(['a_day', 'division', 'bid_position', 'email_match_status'], $hidden)));
        foreach (['name', 'employee_id', 'city_email', 'rank', 'shift', 'station', 'unit'] as $key) {
            self::assertFalse($table->getColumn($key)->isToggledHiddenByDefault(), $key.' is a main column');
        }
    }

    public function test_values_that_moved_under_another_column_are_still_on_screen_and_in_the_default_export(): void
    {
        $employee = Employee::query()->create(['employee_id' => '00125', 'name' => 'Taylor Fixture', 'rank' => 'Captain', 'station' => '2', 'roster_status' => 'active', 'city_email' => 'taylor.fixture@miamibeachfl.gov']);
        $this->travelTo(now('America/New_York')->setDate(2026, 6, 1));
        EmployeeBidAssignment::query()->create([
            'employee_profile_id' => $employee->id, 'payload_version' => 2, 'bid_year' => 2026, 'term_label' => '2026', 'bid_session_id' => 'fx', 'position_id' => 'p',
            'rank_label' => 'Captain', 'shift_label' => 'Shift Quartz', 'station_label' => 'Station 3', 'division_label' => 'Operations Division', 'unit_label' => 'Rescue 99',
            'position_label' => 'Driver Engineer', 'bid_selection_label' => 'Rescue 99', 'assignment_source' => 'bid_award', 'a_day_label' => 'A-Day 3',
            'idempotency_key' => 'fx', 'payload_hash' => str_repeat('a', 64), 'is_forced' => false,
        ]);
        $this->actingAsAdminWith(['personnel.directory.view']);

        $page = Livewire::test(MasterPersonnelDirectory::class);
        $page->assertSee('A-Day 3')->assertSee('Driver Engineer')->assertSee('Operations Division');
        $columns = (new \ReflectionMethod($page->instance(), 'exportColumns'))->invoke($page->instance());
        foreach (self::DIRECTORY_COLUMNS as $key) {
            self::assertContains($key, $columns, $key.' must still be exported by default');
        }
        self::assertSame(count($columns), count(array_unique($columns)));
    }

    public function test_directory_filters_sorting_and_search_are_unchanged(): void
    {
        $first = $this->employee('Alex Fixture', '00125', 'Captain');
        $second = $this->employee('Zoe Fixture', '00126', 'Captain');
        $other = $this->employee('Sam Fixture', '00127', 'Lieutenant');
        $this->actingAsAdminWith(['personnel.directory.view']);

        Livewire::test(MasterPersonnelDirectory::class)
            ->filterTable('rank', ['Captain'])->assertCanSeeTableRecords([$first, $second])->assertCanNotSeeTableRecords([$other])
            ->set('personnelSortRules', [['field' => 'name', 'direction' => 'desc']])->assertCanSeeTableRecords([$second, $first], inOrder: true)
            ->searchTable('00125')->assertCanSeeTableRecords([$first])->assertCanNotSeeTableRecords([$second]);
    }

    public function test_the_two_wide_tables_use_the_full_content_width_and_load_the_compact_styles_only_there(): void
    {
        $this->actingAsAdminWith(['personnel.directory.view', 'admin.fleet.view']);

        self::assertSame(MaxWidth::Full, (new MasterPersonnelDirectory)->getMaxContentWidth());
        self::assertSame(MaxWidth::Full, (new ListUnitMasterVehicles)->getMaxContentWidth());
        foreach (['/admin/master-personnel-directory', '/admin/unit-master-vehicles'] as $url) {
            $this->get($url)->assertOk()->assertSee('--mbfd-rt-surface', false)->assertSee('prefers-reduced-motion', false);
        }
        $this->get('/admin/custom-personnel-tables')->assertDontSee('--mbfd-rt-surface', false);
    }

    public function test_every_vehicle_column_is_still_available_with_its_sorting_and_search(): void
    {
        $this->actingAsAdminWith(['admin.fleet.view']);
        $table = Livewire::test(ListUnitMasterVehicles::class)->instance()->getTable();

        foreach (self::VEHICLE_COLUMNS as $key) {
            $column = $table->getColumn($key);
            self::assertNotNull($column, $key.' must still be a column');
            self::assertTrue($column->isToggleable() || ! $column->isToggledHiddenByDefault(), $key.' must be visible or in the chooser');
        }
        foreach (['tag_number', 'model', 'year', 'dept_code', 'color', 'assignment', 'als_license', 'serial_number', 'sunpass_number', 'fleet_class_description'] as $key) {
            self::assertTrue($table->getColumn($key)->isToggleable(), $key.' moved to the chooser');
            self::assertTrue($table->getColumn($key)->isToggledHiddenByDefault(), $key.' is shown under a main column by default');
        }
        foreach (['veh_number', 'tag_number', 'section', 'make', 'model', 'year', 'dept_code'] as $key) {
            self::assertTrue($table->getColumn($key)->isSortable(), $key.' stays sortable');
        }
        foreach (['veh_number', 'tag_number', 'section', 'employee_or_vehicle_name', 'assignment', 'location', 'serial_number'] as $key) {
            self::assertTrue($table->getColumn($key)->isSearchable(), $key.' stays searchable');
        }
        foreach (['make', 'model', 'year'] as $key) {
            self::assertFalse($table->getColumn($key)->isSearchable(), $key.' was not searchable before and still is not');
        }
    }

    public function test_stacked_vehicle_values_are_on_screen_and_search_and_sort_still_work(): void
    {
        $a = $this->vehicle(['veh_number' => '1001', 'tag_number' => 'TAGONE', 'make' => 'Ford', 'model' => 'F-150', 'year' => '2019', 'section' => 'Operations', 'dept_code' => 'FIRE', 'assignment' => 'Taylor Fixture']);
        $b = $this->vehicle(['veh_number' => '1002', 'tag_number' => 'TAGTWO', 'make' => 'Chevrolet', 'model' => 'Tahoe', 'year' => '2021']);
        $this->actingAsAdminWith(['admin.fleet.view']);

        Livewire::test(ListUnitMasterVehicles::class)->loadTable()
            ->assertSee('Tag TAGONE')->assertSee('F-150')->assertSee('2019')->assertSee('Dept. FIRE')->assertSee('Taylor Fixture')
            ->searchTable('TAGTWO')->assertCanSeeTableRecords([$b])->assertCanNotSeeTableRecords([$a])
            ->searchTable('')->sortTable('make')->assertCanSeeTableRecords([$b, $a], inOrder: true)
            ->sortTable('model', 'desc')->assertCanSeeTableRecords([$b, $a], inOrder: true)
            ->sortTable('year', 'asc')->assertCanSeeTableRecords([$a, $b], inOrder: true);
    }

    /** @param array<string, mixed> $attributes */
    private function vehicle(array $attributes): UnitMasterVehicle
    {
        return UnitMasterVehicle::query()->create($attributes);
    }

    private function employee(string $name, string $id, string $rank): Employee
    {
        return Employee::query()->create(['employee_id' => $id, 'name' => $name, 'rank' => $rank, 'station' => '1', 'roster_status' => 'active', 'city_email' => 'fixture'.(int) $id.'@miamibeachfl.gov']);
    }
}
