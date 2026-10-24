<?php

declare(strict_types=1);

namespace Tests\Feature\Exports;

use App\Filament\Resources\ShopWorkResource\Pages\ListShopWorks;
use App\Filament\Resources\StationResource\Pages\ListStations;
use App\Filament\Resources\TodoResource\Pages\ListTodos;
use App\Models\Apparatus;
use App\Models\ShopWork;
use App\Models\Station;
use App\Models\Todo;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date;

/** Row-level export checks on tables with unusual columns: images, booleans, layouts, decimals, arrays, relations. */
final class TableExportMixedColumnsTest extends ExportTestCase
{
    public function test_station_export_skips_the_photo_column_and_writes_yes_no_for_booleans(): void
    {
        Station::query()->create(['station_number' => 4, 'name' => 'Station 4', 'address' => '=cmd|calc', 'is_active' => true, 'phone' => '305-555-0100']);
        Station::query()->create(['station_number' => 7, 'name' => 'Station 7', 'address' => '7 Ocean Drive', 'is_active' => false]);

        $grid = $this->dataRows($this->workbook(Livewire::test(ListStations::class)->call('loadTable')
            ->callTableAction('export', data: ['format' => 'xlsx', 'scope' => 'filtered', 'include_toggled_off' => true])));
        $headers = $grid[0];

        self::assertNotContains('Photo', $headers);
        $first = array_combine($headers, $grid[1]);
        self::assertSame(4, (int) $first['Station']);
        self::assertSame("'=cmd|calc", $first['Address']);
        self::assertSame('Yes', $first['Active']);
        self::assertSame('No', array_combine($headers, $grid[2])['Active']);
    }

    public function test_layout_columns_and_toggle_columns_export_as_plain_values(): void
    {
        Todo::query()->create(['title' => 'Order new hose', 'description' => 'Pending purchase order', 'is_completed' => false, 'assigned_to' => []]);
        Todo::query()->create(['title' => 'Test ladders', 'description' => 'Annual test', 'is_completed' => true, 'assigned_to' => []]);

        $grid = $this->dataRows($this->workbook(Livewire::test(ListTodos::class)->call('loadTable')
            ->callTableAction('export', data: ['format' => 'xlsx', 'scope' => 'all', 'include_toggled_off' => true])));

        self::assertCount(3, $grid, 'Header plus two todos.');
        $flat = array_merge(...array_slice($grid, 1));
        self::assertContains('Order new hose', $flat);
        self::assertContains('Yes', $flat);
        self::assertContains('No', $flat);
    }

    public function test_shop_work_keeps_decimals_as_numbers_dates_as_dates_and_shows_the_unit(): void
    {
        $apparatus = Apparatus::query()->create([
            'unit_id' => 'E1', 'name' => 'Engine 1', 'type' => 'Engine', 'vehicle_number' => '1001', 'designation' => 'E1',
            'slug' => 'engine-1', 'make' => 'Pierce', 'model' => 'Enforcer', 'year' => 2021, 'status' => 'In Service',
            'current_engine_hours' => 10, 'current_miles' => 100,
        ]);
        ShopWork::query()->create([
            'project_name' => '+Pump rebuild', 'apparatus_id' => $apparatus->id, 'status' => 'In Progress',
            'estimated_cost' => '1250.50', 'started_date' => '2026-09-01', 'assigned_to' => 'Fleet',
        ]);

        $sheet = $this->workbook(Livewire::test(ListShopWorks::class)->call('loadTable')
            ->callTableAction('export', data: ['format' => 'xlsx', 'scope' => 'filtered']))->getSheet(0);
        $headers = array_flip(array_map(static fn ($cell) => $cell, $sheet->toArray()[0]));
        $row = 2;

        self::assertSame("'+Pump rebuild", $sheet->getCell([$headers['Project name'] + 1, $row])->getValue());
        self::assertSame('E1', $sheet->getCell([$headers['Apparatus'] + 1, $row])->getValue());
        $estimated = $sheet->getCell([$headers['Estimated cost'] + 1, $row]);
        self::assertSame(DataType::TYPE_NUMERIC, $estimated->getDataType());
        self::assertEqualsWithDelta(1250.5, (float) $estimated->getValue(), 0.001);
        $started = $sheet->getCell([$headers['Started date'] + 1, $row]);
        self::assertSame(DataType::TYPE_NUMERIC, $started->getDataType());
        self::assertSame('2026-09-01', Date::excelToDateTimeObject($started->getValue())->format('Y-m-d'));
        self::assertSame('Fleet', $sheet->getCell([$headers['Assigned to'] + 1, $row])->getValue());
    }
}
