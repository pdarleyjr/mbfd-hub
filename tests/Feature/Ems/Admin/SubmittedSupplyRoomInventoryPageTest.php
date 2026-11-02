<?php

declare(strict_types=1);

namespace Tests\Feature\Ems\Admin;

use App\Filament\Admin\Pages\MedicalSupplyRoom;
use App\Filament\Admin\Pages\SubmittedMedicalSupplyRoomInventory;
use App\Filament\Support\EmsLabels;
use App\Models\Captain5\Captain5AccessOverride;
use App\Models\Ems\InventorySnapshot;
use App\Models\Ems\InventorySnapshotLine;
use App\Models\Ems\StockMovement;
use App\Models\Ems\SupplyOrder;
use App\Models\User;
use App\Services\Ems\Exports\SupplyCountReport;
use App\Services\Ems\Exports\SupplyCountReportExporter;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\Feature\Ems\Concerns\BuildsEmsWorld;
use Tests\TestCase;

/**
 * Admin -> EMS -> Submitted Medical Supply Room Inventory: the counts Captain 5 submitted, each as a report
 * built only from the stamps on the final lines.
 */
final class SubmittedSupplyRoomInventoryPageTest extends TestCase
{
    use BuildsEmsWorld;
    use RefreshDatabase;

    private User $manager;

    private User $viewer;

    private User $member;

    private int $version = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->buildEmsWorld();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->manager = $this->emsAdmin(['admin.ems.manage'], 'Manny Manager');
        $this->viewer = $this->emsAdmin(['admin.ems.view'], 'Vera Viewer');
        $this->member = $this->emsMember('Paramedic', 'Pat Medic');
        // Shelf order is the table's sort, not the alphabet: B1, then A2, C1, D1.
        foreach (['B1' => 10, 'A2' => 20, 'C1' => 30, 'D1' => 40, 'A3' => 50] as $code => $sort) {
            DB::table('ems_storage_locations')->where('code', $code)->update(['sort' => $sort]);
        }
    }

    protected function tearDown(): void
    {
        $this->tearDownEmsWorld();
        parent::tearDown();
    }

    private function actAs(User $user): void
    {
        $this->forgetEmsMemo();
        $this->actingAs($user->fresh());
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function page(): Testable
    {
        return Livewire::test(SubmittedMedicalSupplyRoomInventory::class);
    }

    // ------------------------------------------------------------------ fixtures

    /**
     * A final snapshot, built the way the services build one: the lines go in while the snapshot is a draft, then it is finalized.
     *
     * @param  array<string, mixed>  $snapshot  kind, origin, status, finalized_at, finalized_name and the three coverage numbers
     * @param  list<array<string, mixed>>  $lines
     */
    private function makeCount(array $snapshot = [], array $lines = []): InventorySnapshot
    {
        $this->version++;
        $at = $snapshot['finalized_at'] ?? CarbonImmutable::parse('2026-11-02 14:30:00', 'UTC');
        $draft = InventorySnapshot::query()->create([
            'uuid' => (string) Str::uuid(),
            'kind' => $snapshot['kind'] ?? InventorySnapshot::KIND_COUNT,
            'origin' => array_key_exists('origin', $snapshot) ? $snapshot['origin'] : InventorySnapshot::ORIGIN_COUNT_FORM,
            'status' => InventorySnapshot::DRAFT,
            'started_at' => $at->subHour(),
        ]);
        foreach ($lines as $line) {
            InventorySnapshotLine::query()->create(['snapshot_id' => $draft->getKey()] + $line);
        }
        if (($snapshot['status'] ?? InventorySnapshot::FINAL) === InventorySnapshot::DRAFT) {
            return $draft;
        }

        $counted = count(array_filter($lines, static fn (array $l): bool => ($l['value_source'] ?? '') === InventorySnapshotLine::COUNTED));
        $draft->update([
            'status' => InventorySnapshot::FINAL,
            'version' => $this->version,
            'finalized_at' => $at,
            'finalized_name' => array_key_exists('finalized_name', $snapshot) ? $snapshot['finalized_name'] : 'Manny Counter',
            'counted_items' => array_key_exists('counted_items', $snapshot) ? $snapshot['counted_items'] : $counted,
            'uncounted_items' => array_key_exists('uncounted_items', $snapshot) ? $snapshot['uncounted_items'] : count($lines) - $counted,
            'order_items' => array_key_exists('order_items', $snapshot)
                ? $snapshot['order_items']
                : count(array_filter($lines, static fn (array $l): bool => (int) ($l['order_qty_count_unit'] ?? 0) > 0)),
        ]);

        return $draft->fresh();
    }

    /**
     * A counted line with its stamps. $order is in count units; $expectedBase is what the books said.
     *
     * @return array<string, mixed>
     */
    private function counted(string $rowKey, string $name, ?string $shelf, string $unit, int $per, int $countedValue, ?int $requiredBase, ?int $order, ?int $expectedBase = null, ?string $problem = null): array
    {
        return [
            'supply_item_id' => $this->supplyItem($rowKey)->id, 'location_code' => $shelf, 'item_name' => $name,
            'quantity_base' => $countedValue * $per, 'value_source' => InventorySnapshotLine::COUNTED, 'entry' => [],
            'section_key' => 'supplies', 'count_unit' => $unit, 'units_per_count_unit' => $per, 'counted_value' => $countedValue,
            'expected_quantity_base' => $expectedBase ?? $countedValue * $per, 'required_qty_base' => $requiredBase,
            'order_qty_base' => $order === null ? null : $order * $per, 'order_qty_count_unit' => $order, 'config_problem' => $problem,
        ];
    }

    /** @return array<string, mixed> */
    private function uncounted(string $rowKey, string $name, ?string $shelf): array
    {
        return [
            'supply_item_id' => $this->supplyItem($rowKey)->id, 'location_code' => $shelf, 'item_name' => $name,
            'quantity_base' => null, 'value_source' => InventorySnapshotLine::NOT_COUNTED, 'entry' => [],
            'count_unit' => 'each', 'units_per_count_unit' => 1,
        ];
    }

    /** One count with every kind of line, on shelves whose sort order differs from alphabetical order. */
    private function fullCount(string $by = 'Cathy Counter'): InventorySnapshot
    {
        return $this->makeCount(['finalized_name' => $by], [
            $this->counted('sr.t.03', 'Stethoscope', 'B1', 'each', 1, 4, 3, 0),
            $this->counted('sr.t.02', 'Tape 1 inch', 'A2', 'box', 24, 1, 48, 1, expectedBase: 48),
            $this->counted('sr.t.01', 'Gauze 4x4', 'A2', 'box', 200, 0, 200, 1),
            $this->uncounted('sr.t.04', 'Gloves Medium', 'C1'),
            $this->counted('sr.t.05', 'Cardizem 25mg', 'D1', 'each', 1, 3, null, null, problem: 'No order level set'),
            $this->counted('sr.t.07', 'Gauze 4x4 Sterile', null, 'box', 50, 2, 100, 0),
        ]);
    }

    private function openReport(InventorySnapshot $snapshot): Testable
    {
        return $this->page()->call('openReport', $snapshot->uuid);
    }

    // ---------------------------------------------------------------------- access

    public function test_a_viewer_a_manager_and_a_super_administrator_can_open_the_page(): void
    {
        $this->fullCount();
        foreach ([$this->viewer, $this->manager, $this->emsSuperAdmin()] as $user) {
            $this->actAs($user);
            $this->assertTrue(SubmittedMedicalSupplyRoomInventory::canAccess());
            $this->page()->assertOk()->assertSee('Cathy Counter');
            $this->get('/admin/submitted-medical-supply-room-inventory')->assertOk()->assertSee('Submitted Medical Supply Room Inventory');
        }
    }

    public function test_a_member_and_an_administrator_without_ems_access_are_refused(): void
    {
        foreach ([$this->member, $this->emsAdmin([], 'Plain Admin')] as $user) {
            $this->actAs($user);
            $this->assertFalse(SubmittedMedicalSupplyRoomInventory::canAccess());
            $this->page()->assertForbidden();
        }
        $this->actAs($this->emsAdmin([], 'Plain Admin Two'));
        $this->get('/admin/submitted-medical-supply-room-inventory')->assertForbidden();
    }

    public function test_a_captain_5_deny_override_ends_access_and_every_open_page_interaction_is_refused(): void
    {
        $count = $this->fullCount();
        $this->actAs($this->viewer);
        // One open page per interaction: a refused request ends that page's session in the test harness.
        $pages = ['openReport' => $this->page(), 'showMore' => $this->page(), 'closeReport' => $this->page(), 'export' => $this->openReport($count)];
        $this->assertTrue(SubmittedMedicalSupplyRoomInventory::canAccess());

        Captain5AccessOverride::query()->create([
            'employee_profile_id' => $this->viewer->employee_profile_id, 'effect' => 'deny', 'reason' => 'Under review', 'set_by_user_id' => $this->manager->id,
        ]);
        $this->actAs($this->viewer);

        $this->assertFalse(SubmittedMedicalSupplyRoomInventory::canAccess());
        $pages['openReport']->call('openReport', $count->uuid)->assertForbidden();
        $pages['showMore']->call('showMore')->assertForbidden();
        $pages['closeReport']->call('closeReport')->assertForbidden();
        $pages['export']->call('mountAction', 'exportReport', ['report' => $count->uuid, 'format' => 'pdf'])->assertForbidden();
        $this->page()->assertForbidden();
    }

    public function test_the_page_is_read_only_and_never_creates_or_claims_an_order(): void
    {
        $count = $this->fullCount();
        $before = [InventorySnapshot::query()->count(), InventorySnapshotLine::query()->count(), SupplyOrder::query()->count(), StockMovement::query()->count()];
        $this->actAs($this->manager);

        $page = $this->openReport($count)->set('search', 'tape')->set('onlyToOrder', true)->call('closeReport')->call('showMore');
        $this->openReport($count)->callAction('exportReport', [], ['report' => $count->uuid, 'format' => 'xlsx'])->assertFileDownloaded();
        $page->assertDontSee('Record order placed')->assertDontSee('Place order');

        $this->assertSame($before, [InventorySnapshot::query()->count(), InventorySnapshotLine::query()->count(), SupplyOrder::query()->count(), StockMovement::query()->count()]);

        $methods = array_map(static fn (\ReflectionMethod $m): string => $m->getName(), (new \ReflectionClass(SubmittedMedicalSupplyRoomInventory::class))->getMethods(\ReflectionMethod::IS_PUBLIC));
        $own = array_values(array_filter($methods, static fn (string $m): bool => (new \ReflectionMethod(SubmittedMedicalSupplyRoomInventory::class, $m))->class === SubmittedMedicalSupplyRoomInventory::class));
        foreach ($own as $method) {
            $this->assertDoesNotMatchRegularExpression('/^(save|create|place|confirm|finalize|reconcile|update|delete|record)/i', $method, "{$method} looks like a write.");
        }
    }

    // ------------------------------------------------------------------------ list

    public function test_the_list_is_newest_first_marks_only_the_latest_and_tags_partial_counts(): void
    {
        $old = $this->makeCount(['finalized_name' => 'Oscar Older', 'finalized_at' => CarbonImmutable::parse('2026-10-19 14:00:00', 'UTC')], [
            $this->counted('sr.t.02', 'Tape 1 inch', 'A2', 'box', 24, 1, 48, 1),
        ]);
        $mid = $this->makeCount(['finalized_name' => 'Mia Middle', 'finalized_at' => CarbonImmutable::parse('2026-10-26 14:00:00', 'UTC')], [
            $this->counted('sr.t.02', 'Tape 1 inch', 'A2', 'box', 24, 2, 48, 0),
            $this->uncounted('sr.t.03', 'Stethoscope', 'B1'),
            $this->uncounted('sr.t.04', 'Gloves Medium', 'C1'),
        ]);
        $new = $this->makeCount(['finalized_name' => 'Nina Newest', 'finalized_at' => CarbonImmutable::parse('2026-11-02 14:00:00', 'UTC')], [
            $this->counted('sr.t.02', 'Tape 1 inch', 'A2', 'box', 24, 0, 48, 2),
            $this->counted('sr.t.03', 'Stethoscope', 'B1', 'each', 1, 1, 3, 2),
        ]);
        $this->actAs($this->viewer);

        $page = $this->page()->assertSeeInOrder([
            EmsLabels::et($new->finalized_at), 'Nina Newest',
            EmsLabels::et($mid->finalized_at), 'Mia Middle',
            EmsLabels::et($old->finalized_at), 'Oscar Older',
        ]);
        foreach (['Date and time', 'Counted by', 'Items counted', 'Not counted', 'Items to order'] as $column) {
            $page->assertSee($column);
        }

        $html = $page->html();
        $this->assertSame(1, preg_match_all('/>\s*Latest\s*</', $html), 'Only the newest count is marked Latest.');
        $this->assertSame(1, preg_match_all('/>\s*Partial\s*</', $html), 'Only the count with uncounted items is tagged Partial.');
        $rows = array_slice(preg_split('/data-testid="submitted-count"/', $html), 1);
        $this->assertCount(3, $rows);
        $this->assertMatchesRegularExpression('/>\s*Latest\s*</', $rows[0]);
        $this->assertMatchesRegularExpression('/>\s*Partial\s*</', $rows[1]);
        $this->assertSame([2, 0, 2], [$new->fresh()->counted_items, $new->fresh()->uncounted_items, $new->fresh()->order_items]);
    }

    public function test_counts_made_by_direct_edit_baselines_reconciliations_and_drafts_are_not_listed(): void
    {
        $this->makeCount(['finalized_name' => 'Kept FromForm'], [$this->counted('sr.t.02', 'Tape 1 inch', 'A2', 'box', 24, 1, 48, 1)]);
        $this->makeCount(['finalized_name' => 'Kept NullOrigin', 'origin' => null], [$this->counted('sr.t.02', 'Tape 1 inch', 'A2', 'box', 24, 1, 48, 1)]);
        $this->makeCount(['finalized_name' => 'Hidden DirectEdit', 'origin' => InventorySnapshot::ORIGIN_DIRECT_EDIT], [$this->counted('sr.t.02', 'Tape 1 inch', 'A2', 'box', 24, 1, 48, 1)]);
        $this->makeCount(['finalized_name' => 'Hidden Baseline', 'kind' => InventorySnapshot::KIND_BASELINE, 'origin' => InventorySnapshot::ORIGIN_WORKBOOK], [$this->counted('sr.t.02', 'Tape 1 inch', 'A2', 'box', 24, 1, 48, 1)]);
        $this->makeCount(['finalized_name' => 'Hidden Calculated', 'kind' => InventorySnapshot::KIND_RECONCILIATION, 'origin' => null], []);
        $this->makeCount(['finalized_name' => 'Hidden Draft', 'status' => InventorySnapshot::DRAFT], []);
        $this->actAs($this->viewer);

        $this->page()->assertSee('Kept FromForm')->assertSee('Kept NullOrigin')
            ->assertDontSee('Hidden DirectEdit')->assertDontSee('Hidden Baseline')->assertDontSee('Hidden Calculated')->assertDontSee('Hidden Draft');
    }

    public function test_an_older_count_without_stamps_renders_dashes_never_zeros(): void
    {
        $legacy = $this->makeCount(
            ['finalized_name' => null, 'origin' => null, 'counted_items' => null, 'uncounted_items' => null, 'order_items' => null],
            [[
                'supply_item_id' => $this->supplyItem('sr.t.02')->id, 'location_code' => 'A2', 'item_name' => 'Tape 1 inch', 'quantity_base' => 48,
                'value_source' => InventorySnapshotLine::COUNTED, 'entry' => ['boxes' => 2],
            ]],
        );
        $this->actAs($this->viewer);

        $row = array_slice(preg_split('/data-testid="submitted-count"/', $this->page()->html()), 1)[0];
        $text = trim((string) preg_replace('/\s+/', ' ', strip_tags($row)));
        $this->assertStringContainsString('Counted by - Items counted - Not counted - Items to order - Open', $text);
        $this->assertDoesNotMatchRegularExpression('/Partial/', $text);

        $listed = app(SupplyCountReport::class)->listing(25)['rows'][0];
        $this->assertNull($listed['counted']);
        $this->assertNull($listed['to_order']);
        $this->assertFalse($listed['partial']);

        // The report itself still reads: the base quantity, no order claim.
        $this->openReport($legacy)->assertSee('Coverage was not recorded for this count.')->assertSee('48 units')->assertDontSee('No order needed');
    }

    // ---------------------------------------------------------------------- report

    public function test_the_report_shows_who_counted_when_and_groups_by_shelf_in_the_tables_sort_order(): void
    {
        $count = $this->fullCount('Cathy Counter');
        $this->actAs($this->viewer);

        $this->openReport($count)
            ->assertSee('Counted by Cathy Counter')
            ->assertSee('Count of '.EmsLabels::et($count->finalized_at))
            ->assertSeeInOrder(['Shelf B1', 'Stethoscope', 'Shelf A2', 'Gauze 4x4', 'Tape 1 inch', 'Shelf C1', 'Gloves Medium', 'Shelf D1', 'Cardizem 25mg', 'No shelf assigned', 'Gauze 4x4 Sterile'])
            ->assertSee('Partial');
    }

    public function test_each_row_shows_the_stamped_counted_value_and_the_order_in_the_count_unit(): void
    {
        $count = $this->fullCount();
        $this->actAs($this->viewer);

        $report = app(SupplyCountReport::class)->report($count);
        $rows = collect($report['shelves'])->flatMap(fn (array $shelf) => $shelf['rows'])->keyBy('item');

        $this->assertSame('1 box', $rows['Tape 1 inch']['counted_text']);
        $this->assertSame('1 box', $rows['Tape 1 inch']['order_text']);
        $this->assertSame('0 boxes', $rows['Gauze 4x4']['counted_text'], 'A counted zero is shown as zero.');
        $this->assertSame('1 box', $rows['Gauze 4x4']['order_text']);
        $this->assertSame('4 units', $rows['Stethoscope']['counted_text']);
        $this->assertSame('No order needed', $rows['Stethoscope']['order_text']);
        $this->assertSame('-', $rows['Gloves Medium']['counted_text']);
        $this->assertSame('Not counted', $rows['Gloves Medium']['order_text']);
        $this->assertSame('No order level set', $rows['Cardizem 25mg']['order_text']);
        $this->assertSame('No order needed', $rows['Gauze 4x4 Sterile']['order_text']);

        $this->openReport($count)->assertSee('1 box')->assertSee('0 boxes')->assertSee('No order needed')->assertSee('Not counted')->assertSee('No order level set');
    }

    public function test_a_small_note_appears_only_where_the_books_disagree_with_the_count(): void
    {
        $count = $this->makeCount([], [
            $this->counted('sr.t.02', 'Tape 1 inch', 'A2', 'box', 24, 1, 48, 1, expectedBase: 85 * 24),
            $this->counted('sr.t.01', 'Gauze 4x4', 'A2', 'box', 200, 1, 200, 0, expectedBase: 200),
            $this->counted('sr.t.03', 'Stethoscope', 'B1', 'each', 1, 4, 3, 0, expectedBase: 5),
        ]);
        $this->actAs($this->viewer);

        $page = $this->openReport($count)->assertSee('Books said 85')->assertSee('Books said 5');
        $this->assertSame(2, substr_count($page->html(), 'data-testid="report-note"'), 'No note where the books agree.');
    }

    public function test_the_report_reads_only_stamps_so_it_does_not_change_when_the_item_is_renamed_moved_or_re_thresholded(): void
    {
        $count = $this->fullCount();
        $this->actAs($this->viewer);
        $before = app(SupplyCountReport::class)->report($count);

        $b1 = (int) DB::table('ems_storage_locations')->where('code', 'B1')->value('id');
        DB::table('ems_supply_items')->where('source_row_key', 'sr.t.02')->update([
            'name' => 'Renamed Silk Tape', 'location_id' => $b1, 'threshold_qty_base' => 99999, 'threshold_source_text' => '< 99999U',
            'count_unit' => 'case', 'units_per_package' => 999, 'active' => false,
        ]);
        DB::table('ems_supply_items')->where('source_row_key', 'sr.t.05')->update(['threshold_qty_base' => 50]);

        $this->assertSame($before, app(SupplyCountReport::class)->report($count->fresh()));
        $this->openReport($count)
            ->assertSee('Tape 1 inch')->assertDontSee('Renamed Silk Tape')->assertDontSee('99,999')
            ->assertSeeInOrder(['Shelf A2', 'Gauze 4x4', 'Tape 1 inch', 'Shelf C1'])
            ->assertSee('No order level set');
    }

    public function test_search_narrows_the_items_and_says_so_when_nothing_matches(): void
    {
        $count = $this->fullCount();
        $this->actAs($this->viewer);

        $page = $this->openReport($count)->set('search', 'TAPE')
            ->assertSee('Tape 1 inch')->assertDontSee('Stethoscope')->assertDontSee('Gloves Medium')->assertDontSee('Shelf B1');
        $page->set('search', 'gauze')->assertSee('Gauze 4x4')->assertSee('Gauze 4x4 Sterile')->assertDontSee('Tape 1 inch');
        $page->set('search', 'no such thing')->assertSee('No items match')->assertDontSee('Tape 1 inch');
        $page->set('search', '')->assertSee('Stethoscope')->assertSee('Tape 1 inch');
    }

    public function test_only_items_to_order_is_off_by_default_and_keeps_just_the_orders_when_on(): void
    {
        $count = $this->fullCount();
        $this->actAs($this->viewer);

        $page = $this->openReport($count)->assertSet('onlyToOrder', false)->assertSee('Only items to order')
            ->assertSee('Stethoscope')->assertSee('Gloves Medium')->assertSee('Cardizem 25mg');
        $page->set('onlyToOrder', true)
            ->assertSee('Tape 1 inch')->assertSee('Gauze 4x4')
            ->assertDontSee('Stethoscope')->assertDontSee('Gloves Medium')->assertDontSee('Cardizem 25mg')->assertDontSee('Gauze 4x4 Sterile');
        $page->set('search', 'tape')->assertSee('Tape 1 inch')->assertDontSee('Shelf B1');
    }

    public function test_the_report_says_these_are_recommendations_and_links_to_the_reorder_tab(): void
    {
        $count = $this->fullCount();
        $this->actAs($this->viewer);

        $this->openReport($count)
            ->assertSee('They are not purchase orders. Nothing was ordered.')
            ->assertSee('Reorder on the Medical Supply Room page')
            ->assertSee(MedicalSupplyRoom::getUrl(['tab' => 'reorder']), false)
            ->assertSee('data-testid="reorder-link"', false);
        $this->assertSame(0, SupplyOrder::query()->count());
    }

    public function test_a_report_id_that_is_not_a_listed_count_never_opens(): void
    {
        $baseline = $this->makeCount(['finalized_name' => 'Hidden Baseline', 'kind' => InventorySnapshot::KIND_BASELINE, 'origin' => InventorySnapshot::ORIGIN_WORKBOOK], [
            $this->counted('sr.t.02', 'Tape 1 inch', 'A2', 'box', 24, 1, 48, 1),
        ]);
        $edit = $this->makeCount(['finalized_name' => 'Hidden DirectEdit', 'origin' => InventorySnapshot::ORIGIN_DIRECT_EDIT], [$this->counted('sr.t.02', 'Tape 1 inch', 'A2', 'box', 24, 1, 48, 1)]);
        $this->actAs($this->viewer);

        $this->page()->call('openReport', $baseline->uuid)->assertStatus(404);
        $this->page()->set('reportUuid', $edit->uuid)->assertSee('Submitted counts')->assertDontSee('Hidden DirectEdit')->assertDontSee('Count of');
        $this->page()->call('mountAction', 'exportReport', ['report' => $baseline->uuid, 'format' => 'xlsx'])->assertStatus(404);
    }

    // --------------------------------------------------------------------- exports

    private function sheetRows(string $bytes, int $sheet = 0): array
    {
        $path = tempnam(sys_get_temp_dir(), 'ems-read-');
        file_put_contents($path, $bytes);
        try {
            $book = IOFactory::load($path);
        } finally {
            @unlink($path);
        }
        $this->assertInstanceOf(Spreadsheet::class, $book);

        return $book->getSheet($sheet)->toArray(null, true, false, false);
    }

    public function test_the_excel_file_holds_the_stamped_values_in_the_agreed_columns_and_says_it_is_not_a_purchase_order(): void
    {
        $count = $this->makeCount(['finalized_name' => 'Cathy Counter'], [
            $this->counted('sr.t.03', 'Stethoscope', 'B1', 'each', 1, 4, 3, 0),
            $this->counted('sr.t.02', 'Tape 1 inch', 'A2', 'box', 24, 1, 48, 1, expectedBase: 85 * 24),
            $this->uncounted('sr.t.04', 'Gloves Medium', 'C1'),
            $this->counted('sr.t.05', 'Cardizem 25mg', 'D1', 'each', 1, 3, null, null, problem: 'No order level set'),
            $this->counted('sr.t.07', '=HYPERLINK("http://example.test")', 'A3', 'box', 50, 2, 100, 0),
        ]);
        DB::table('ems_supply_items')->where('source_row_key', 'sr.t.02')->update(['name' => 'Renamed Silk Tape']);
        $exporter = app(SupplyCountReportExporter::class);
        $report = app(SupplyCountReport::class)->report($count->fresh());
        $at = CarbonImmutable::parse('2026-11-03 12:00:00', EmsLabels::TIMEZONE);

        $rows = $this->sheetRows($exporter->xlsx($report, $at));
        $this->assertSame(['Shelf', 'Item', 'Counted', 'Unit', 'Required', 'Order', 'Note'], $rows[0]);
        $byItem = collect(array_slice($rows, 1))->keyBy(1);
        $this->assertEquals(['A2', 'Tape 1 inch', 1, 'box', 2, 1, 'Books said 85'], $byItem['Tape 1 inch']);
        $this->assertEquals(['B1', 'Stethoscope', 4, 'unit', 3, 'No order needed', null], $byItem['Stethoscope']);
        $this->assertEquals(['C1', 'Gloves Medium', null, 'unit', null, 'Not counted', null], $byItem['Gloves Medium']);
        $this->assertEquals('No order level set', $byItem['Cardizem 25mg'][5]);
        $this->assertArrayNotHasKey('Renamed Silk Tape', $byItem->all());
        $this->assertSame(['B1', 'A2', 'C1', 'D1', 'A3'], collect(array_slice($rows, 1))->pluck(0)->all(), 'Shelves follow the table order.');
        $this->assertTrue($byItem->has("'=HYPERLINK(\"http://example.test\")"), 'Formula-looking text is neutralized.');

        $facts = collect($this->sheetRows($exporter->xlsx($report, $at), 1))->map(fn (array $r): string => implode(' ', array_filter($r, 'is_string')))->implode("\n");
        $this->assertStringContainsString('Counted by', $facts);
        $this->assertStringContainsString('Cathy Counter', $facts);
        $this->assertStringContainsString('Partial: 4 counted, 1 not counted.', $facts);
        $this->assertStringContainsString('recommended quantities', $facts);
        $this->assertStringContainsString('not purchase orders', $facts);
        $this->assertSame('MBFD - Submitted Medical Supply Room Inventory - '.$report['counted_date'].'.xlsx', $exporter->filename($report, 'xlsx'));
    }

    public function test_the_export_follows_only_items_to_order_and_both_formats_download_from_the_page(): void
    {
        $count = $this->fullCount();
        $this->actAs($this->viewer);
        $exporter = app(SupplyCountReportExporter::class);
        $reports = app(SupplyCountReport::class);

        $only = $this->sheetRows($exporter->xlsx($reports->report($count, true), CarbonImmutable::now(EmsLabels::TIMEZONE)));
        $this->assertSame(['Gauze 4x4', 'Tape 1 inch'], collect(array_slice($only, 1))->pluck(1)->sort()->values()->all());
        $this->assertStringStartsWith('%PDF-', $exporter->pdf($reports->report($count), CarbonImmutable::now(EmsLabels::TIMEZONE)));

        $name = $exporter->filename($reports->report($count), 'xlsx');
        $page = $this->openReport($count);
        $page->callAction('exportReport', [], ['report' => $count->uuid, 'format' => 'xlsx'])->assertFileDownloaded($name);
        $page->callAction('exportReport', [], ['report' => $count->uuid, 'format' => 'pdf'])->assertFileDownloaded(str_replace('.xlsx', '.pdf', $name));
    }

    public function test_the_export_actions_check_access_again_when_they_run(): void
    {
        $count = $this->fullCount();
        $this->actAs($this->viewer);
        $page = $this->openReport($count);

        $viewer = $this->viewer->fresh();
        $viewer->revokePermissionTo('admin.ems.view');
        $this->actAs($viewer);
        $page->call('mountAction', 'exportReport', ['report' => $count->uuid, 'format' => 'xlsx'])->assertForbidden();
    }

    // ----------------------------------------------------------------- query budget

    public function test_the_list_and_a_report_stay_inside_the_admin_query_budget(): void
    {
        foreach (range(1, 8) as $n) {
            $this->makeCount(
                ['finalized_name' => "Counter {$n}", 'finalized_at' => CarbonImmutable::parse('2026-10-01 14:00:00', 'UTC')->addDays($n)],
                [$this->counted('sr.t.02', 'Tape 1 inch', 'A2', 'box', 24, 1, 48, 1)],
            );
        }
        $full = $this->fullCount('Budget Counter');
        $this->actAs($this->emsSuperAdmin());

        $queries = 0;
        DB::listen(static function () use (&$queries): void {
            $queries++;
        });
        $this->page(); // first render also pays for one-time framework work
        $queries = 0;
        $page = $this->page();
        $listQueries = $queries;
        $page->call('openReport', $full->uuid);
        $openQueries = $queries - $listQueries;
        $page->set('search', 'gauze')->set('onlyToOrder', true);
        $filterQueries = $queries - $listQueries - $openQueries;

        $this->assertLessThanOrEqual(30, $listQueries, "The list took {$listQueries} queries.");
        $this->assertLessThanOrEqual(30, $openQueries, "Opening a report took {$openQueries} queries.");
        $this->assertLessThanOrEqual(30, $filterQueries, "Searching and filtering a report took {$filterQueries} queries.");
    }

    public function test_the_query_count_does_not_grow_with_the_number_of_counts_or_lines(): void
    {
        $this->makeCount([], [$this->counted('sr.t.02', 'Tape 1 inch', 'A2', 'box', 24, 1, 48, 1)]);
        $full = $this->fullCount();
        $this->actAs($this->manager);
        $queries = 0;
        DB::listen(static function () use (&$queries): void {
            $queries++;
        });
        $this->page()->call('openReport', $full->uuid); // warm-up

        $measure = function () use (&$queries, $full): int {
            $this->actAs($this->manager);
            $queries = 0;
            $this->page()->call('openReport', $full->uuid);

            return $queries;
        };
        $few = $measure();
        foreach (range(1, 12) as $n) {
            $this->makeCount(['finalized_name' => "Counter {$n}", 'finalized_at' => CarbonImmutable::parse('2026-10-01 14:00:00', 'UTC')->addDays($n)], [
                $this->counted('sr.t.02', 'Tape 1 inch', 'A2', 'box', 24, 1, 48, 1), $this->counted('sr.t.03', 'Stethoscope', 'B1', 'each', 1, 2, 3, 1),
            ]);
        }
        $this->assertLessThanOrEqual($few + 2, $measure(), 'More counts must not mean more queries.');
    }
}
