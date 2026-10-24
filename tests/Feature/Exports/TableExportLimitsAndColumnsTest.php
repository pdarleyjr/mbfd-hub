<?php

declare(strict_types=1);

namespace Tests\Feature\Exports;

use App\Filament\Resources\InventoryLocationResource;
use App\Filament\Resources\InventoryLocationResource\Pages\ListInventoryLocations;
use App\Filament\Resources\TrainingTodoAdminResource;
use App\Models\InventoryLocation;
use App\Models\Training\TrainingTodo;
use App\Services\Exports\ExportColumnReader;
use App\Services\Exports\ExportFailedException;
use App\Services\Exports\TableExportService;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/** Resource that names its own export columns, like a real resource would override exportColumns(). */
final class LocationResourceWithExplicitColumns extends InventoryLocationResource
{
    public static function exportColumns(): ?array
    {
        return ['notes', 'full_location', TextColumn::make('bin')->label('Bin'), 'does_not_exist', TextColumn::make('shelf')->hidden()];
    }
}

final class TableExportLimitsAndColumnsTest extends ExportTestCase
{
    private function seedLocations(int $count): void
    {
        $now = now();
        foreach (array_chunk(range(1, $count), 500) as $chunk) {
            DB::table('inventory_locations')->insert(array_map(
                static fn (int $number): array => ['location_name' => 'Bay '.$number, 'created_at' => $now, 'updated_at' => $now],
                $chunk,
            ));
        }
    }

    public function test_pdf_export_over_the_row_cap_is_refused_with_a_clear_message(): void
    {
        $this->seedLocations(TableExportService::PDF_ROW_CAP + 1);

        $component = Livewire::test(ListInventoryLocations::class)->call('loadTable')
            ->callTableAction('export', data: ['format' => 'pdf', 'scope' => 'all']);

        self::assertArrayNotHasKey('download', $component->effects);
        $component->assertNotified(Notification::make()->title('Export not created')
            ->body('PDF exports are limited to 2,000 rows. Narrow the filters or choose Excel and try again.')->danger());
    }

    public function test_excel_export_over_the_row_cap_is_refused_but_filters_bring_it_back_under(): void
    {
        $this->seedLocations(TableExportService::XLSX_ROW_CAP + 1);

        $refused = Livewire::test(ListInventoryLocations::class)->call('loadTable')
            ->callTableAction('export', data: ['format' => 'xlsx', 'scope' => 'filtered']);
        self::assertArrayNotHasKey('download', $refused->effects);
        $refused->assertNotified('Export not created');

        $allowed = Livewire::test(ListInventoryLocations::class)->call('loadTable')
            ->searchTable('Bay 1000')
            ->callTableAction('export', data: ['format' => 'xlsx', 'scope' => 'filtered']);
        self::assertSame('Bay 1000', $this->dataRows($this->workbook($allowed))[1][0]);
    }

    public function test_wide_excel_exports_are_limited_by_cell_count(): void
    {
        $columns = array_map(static fn (int $number): TextColumn => TextColumn::make('notes')->label('Notes '.$number), range(1, 12));
        $this->seedLocations(intdiv(TableExportService::XLSX_CELL_CAP, 12) + 1);
        $page = Livewire::test(ListInventoryLocations::class)->call('loadTable');
        $resource = new class extends InventoryLocationResource
        {
            public static array $wide = [];

            public static function exportColumns(): ?array
            {
                return self::$wide;
            }
        };
        $resource::$wide = $columns;

        try {
            app(TableExportService::class)->buildDataset($this->admin, $page->instance(), $resource::class, 'xlsx', 'all');
            self::fail('A 12-column export over the cell cap must be refused.');
        } catch (ExportFailedException $exception) {
            self::assertStringContainsString('limited to 8,333 rows with 12 columns', $exception->getMessage());
            self::assertStringContainsString('turn off columns', $exception->getMessage());
        }
    }

    public function test_all_scope_leaves_out_trashed_records_but_the_current_view_can_include_them(): void
    {
        $kept = TrainingTodo::query()->create(['title' => 'Kept task', 'status' => 'pending']);
        $trashed = TrainingTodo::query()->create(['title' => 'Trashed task', 'status' => 'pending']);
        $trashed->delete();
        $page = TrainingTodoAdminResource::getPages()['index']->getPage();

        $all = $this->workbook(Livewire::test($page)->call('loadTable')
            ->callTableAction('export', data: ['format' => 'xlsx', 'scope' => 'all']));
        $titles = array_merge(...$this->dataRows($all));
        self::assertContains('Kept task', $titles);
        self::assertNotContains('Trashed task', $titles);

        $selected = $this->workbook(Livewire::test($page)->call('loadTable')
            ->filterTable('trashed', false)
            ->callTableBulkAction('export_selected', [$trashed->getKey()], data: ['format' => 'xlsx']));
        self::assertContains('Trashed task', array_merge(...$this->dataRows($selected)), 'A record the user explicitly selected is exported.');
    }

    public function test_exports_are_rate_limited_per_user(): void
    {
        $page = Livewire::test(ListInventoryLocations::class)->call('loadTable');
        $service = app(TableExportService::class);

        foreach (range(1, TableExportService::EXPORTS_PER_MINUTE) as $ignored) {
            $service->download($this->admin, $page->instance(), InventoryLocationResource::class, 'xlsx', 'all');
        }

        $this->expectException(ExportFailedException::class);
        $this->expectExceptionMessage('Too many exports');
        $service->download($this->admin, $page->instance(), InventoryLocationResource::class, 'xlsx', 'all');
    }

    public function test_reader_leaves_out_columns_hidden_for_this_user_and_unsupported_columns(): void
    {
        $page = Livewire::test(ListInventoryLocations::class)->call('loadTable');
        $table = $page->instance()->getTable();
        $table->columns([
            TextColumn::make('location_name')->label('Location'),
            TextColumn::make('shelf')->label('Shelf (managers only)')->visible(fn (): bool => auth()->user()?->hasRole('super_admin') === true),
            TextColumn::make('notes')->hidden(),
            TextColumn::make('bin')->hidden(fn (): bool => true),
            ImageColumn::make('photo'),
            TextColumn::make('row')->label('Row')->toggleable(isToggledHiddenByDefault: true),
        ]);
        $page->instance()->toggledTableColumns = ['row' => false];
        $reader = new ExportColumnReader;
        $labels = static fn (array $columns): array => $reader->headers($columns);

        self::assertSame(['Location', 'Shelf (managers only)'], $labels($reader->columns($table, false)));
        self::assertSame(['Location', 'Shelf (managers only)', 'Row'], $labels($reader->columns($table, true)));

        $limited = $this->userWith('admin.access');
        Auth::guard('web')->setUser($limited);

        self::assertSame(['Location'], $labels($reader->columns($table, false)));
        self::assertSame(['Location', 'Row'], $labels($reader->columns($table, true)), 'A column the user may not see stays out even when hidden columns are requested.');
    }

    public function test_long_text_is_not_cut_by_the_table_preview_limit_and_hidden_layouts_hide_their_columns(): void
    {
        $page = Livewire::test(ListInventoryLocations::class)->call('loadTable');
        $table = $page->instance()->getTable();
        $table->columns([
            TextColumn::make('notes')->limit(20),
            \Filament\Tables\Columns\Layout\Split::make([TextColumn::make('shelf')->label('Shelf')])->hidden(),
            \Filament\Tables\Columns\Layout\Stack::make([TextColumn::make('row')->label('Row')]),
        ]);
        $reader = new ExportColumnReader;
        $columns = $reader->columns($table, false);
        $text = str_repeat('word ', 80);
        $record = new InventoryLocation(['notes' => $text]);

        self::assertSame(['Notes', 'Row'], $reader->headers($columns), 'Columns inside a hidden layout are not exported.');
        self::assertSame(trim($text), $reader->row($columns, $record)[0]->value);
        self::assertSame(20, $table->getColumn('notes')->getCharacterLimit(), 'The table itself keeps its preview limit.');
    }

    public function test_resource_can_override_the_export_column_list(): void
    {
        InventoryLocation::query()->create(['location_name' => 'North Cage', 'shelf' => 'A', 'row' => '1', 'bin' => '7', 'notes' => 'Forklift only']);
        $page = Livewire::test(ListInventoryLocations::class)->call('loadTable');

        $dataset = app(TableExportService::class)->buildDataset(
            $this->admin, $page->instance(), LocationResourceWithExplicitColumns::class, 'xlsx', 'all',
        );

        self::assertSame(['Notes', 'Location', 'Bin'], $dataset->headers);
        self::assertSame(['Forklift only', 'North Cage - Shelf A - Row 1 - Bin 7', '7'], array_map(static fn ($cell): string => $cell->display(), $dataset->rows[0]));
    }

    public function test_selected_scope_with_no_keys_is_refused(): void
    {
        $page = Livewire::test(ListInventoryLocations::class)->call('loadTable');

        $this->expectException(\App\Services\Exports\ExportFailedException::class);
        app(TableExportService::class)->buildDataset($this->admin, $page->instance(), InventoryLocationResource::class, 'xlsx', 'selected', false, []);
    }

    public function test_unsupported_format_or_scope_is_rejected(): void
    {
        $page = Livewire::test(ListInventoryLocations::class)->call('loadTable');
        $service = app(TableExportService::class);

        foreach ([['csv', 'all'], ['xlsx', 'everything']] as [$format, $scope]) {
            try {
                $service->download($this->admin, $page->instance(), InventoryLocationResource::class, $format, $scope);
                self::fail("{$format}/{$scope} must be rejected");
            } catch (\App\Services\Exports\ExportFailedException) {
                self::assertTrue(true);
            }
        }
    }
}
