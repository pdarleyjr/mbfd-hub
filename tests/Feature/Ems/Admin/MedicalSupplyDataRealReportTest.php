<?php

declare(strict_types=1);

namespace Tests\Feature\Ems\Admin;

use App\Filament\Admin\Pages\MedicalDataStatistics;
use App\Models\Ems\StockMovement;
use App\Models\User;
use App\Services\Ems\RequestWorkflowService;
use App\Services\Ems\SupplyData\SupplyDataFilters;
use App\Services\Ems\SupplyData\SupplyDataReport;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Feature\Ems\Concerns\BuildsEmsWorld;
use Tests\TestCase;

/**
 * The Medical Supply Data page against the real SupplyDataReport and real stock history: whatever the report
 * says is exactly what the page shows, the drill-downs open, and reading changes nothing.
 */
final class MedicalSupplyDataRealReportTest extends TestCase
{
    use BuildsEmsWorld;
    use RefreshDatabase;

    private User $manager;

    private User $viewer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->buildEmsWorld();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->manager = $this->emsAdmin(['admin.ems.manage'], 'Manny Manager');
        $this->viewer = $this->emsAdmin(['admin.ems.view'], 'Vera Viewer');
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
        return Livewire::test(MedicalDataStatistics::class);
    }

    /** Gauze is counted (a real baseline), then two requests from Rescue 2 are made and the first one's tape and gauze are issued. */
    private function history(): void
    {
        $this->finalizeCount($this->manager, ['sr.t.01' => 400, 'sr.t.02' => 100]);
        $member = $this->emsMember('Paramedic', 'Pat Medic');
        $first = $this->submitRequest($member, [
            ['form' => 'als-med-bag', 'item_key' => 'als.trauma.tape', 'quantity' => 4],
            ['form' => 'als-med-bag', 'item_key' => 'als.trauma.gauze', 'quantity' => 10],
        ]);
        $this->submitRequest($member, [['form' => 'als-med-bag', 'item_key' => 'als.trauma.tape', 'quantity' => 2]]);
        $request = $this->requestModel($first['uuid']);
        $issuances = $request->lines()->whereIn('item_key', ['als.trauma.tape', 'als.trauma.gauze'])->get()
            ->map(fn ($line): array => ['line_id' => (int) $line->id, 'quantity' => 3])->all();
        app(RequestWorkflowService::class)->issue((int) $request->id, $this->manager, 'real-report-issue-1', ['issuances' => $issuances]);
    }

    /** @return array<string, mixed> */
    private function realOverview(): array
    {
        return app(SupplyDataReport::class)->overview(SupplyDataFilters::fromArray(['period' => '30d']));
    }

    public function test_the_page_shows_the_real_reports_figures_and_its_label(): void
    {
        $this->history();
        $this->actAs($this->viewer);
        $expected = $this->realOverview();
        $this->assertGreaterThan(0, $expected['headline']['distribution_events'], 'The fixture must have distributed something for this test to mean anything.');

        $page = $this->page()->assertOk()->assertSee(SupplyDataReport::LABEL);
        $html = $page->html();
        foreach (['distribution_events', 'items_distributed', 'units_supplied', 'requests_submitted', 'requests_reconciled'] as $key) {
            $this->assertMatchesRegularExpression('/data-testid="headline-'.$key.'".*?supply-stat-value[^>]*>\s*'.$expected['headline'][$key].'\s/s', $html, "{$key} on screen is the report's number.");
        }
        foreach ($expected['attention'] as $key => $value) {
            $this->assertMatchesRegularExpression('/data-testid="attention-'.$key.'".*?supply-stat-value[^>]*>\s*'.$value.'\s/s', $html, "{$key} on screen is the report's number.");
        }
        foreach ($expected['top_items'] as $row) {
            $page->assertSee($row['name']);
        }
        foreach ($expected['units'] as $row) {
            $page->assertSee($row['label']);
        }
        $this->assertSame($expected['definitions'], app(SupplyDataReport::class)->overview(SupplyDataFilters::fromArray([]))['definitions']);
        $page->assertSee('What these numbers mean');
    }

    public function test_a_counted_balance_reads_counted_and_an_untouched_estimate_reads_estimated(): void
    {
        $this->history();
        $this->actAs($this->viewer);
        $overview = $this->realOverview();
        $bases = collect($overview['stock'])->pluck('basis')->unique()->all();

        $html = $this->page()->html();
        $this->assertContains('counted', $bases);
        $this->assertStringContainsString('Counted', $html);
        foreach ($overview['stock'] as $row) {
            if ($row['estimated']) {
                $this->assertStringContainsString('Estimated', $html);
                $this->assertNull($row['days_of_supply'], 'An estimate never gets days of supply.');
            }
        }
    }

    public function test_the_item_and_unit_drill_downs_open_on_real_data(): void
    {
        $this->history();
        $this->actAs($this->viewer);
        $overview = $this->realOverview();
        $itemId = $overview['top_items'][0]['item_id'];
        $unitKey = 'unit:'.$this->rescue2->id;

        $this->page()->mountAction('itemDetail', ['item' => $itemId])
            ->assertSee($overview['top_items'][0]['name'])->assertSee('On the shelf')->assertSee('Distribution in this period')->assertSee('Stock movements');
        $this->page()->mountAction('unitDetail', ['unit' => $unitKey])
            ->assertSee('In this period')->assertSee('Most requested')->assertSee('MIR-');
        $this->page()->mountAction('unitDetail', ['unit' => 'unit:99999999'])->assertSee('This unit is not in the supply data.');
        $this->page()->mountAction('unitDetail', ['unit' => 'detail'])->assertSee('In this period');
    }

    public function test_reading_the_page_changes_no_record(): void
    {
        $this->history();
        $this->actAs($this->manager);
        $tables = ['ems_stock_movements', 'ems_inventory_requests', 'ems_inventory_request_lines', 'ems_inventory_snapshots', 'ems_inventory_snapshot_lines', 'ems_supply_orders', 'ems_supply_items', 'ems_inventory_request_events'];
        $snapshot = fn (): array => array_map(fn (string $table): int => DB::table($table)->count(), $tables);
        $movements = StockMovement::query()->orderBy('id')->get(['id', 'quantity_base', 'movement_type'])->toArray();
        $before = $snapshot();

        $this->page()->mountAction('itemDetail', ['item' => $this->realOverview()['top_items'][0]['item_id']]);
        $this->page()->set('supplyPeriod', '12m')->call('showMoreStock')->set('stockLowOnly', true);
        $this->page()->mountAction('unitDetail', ['unit' => 'unit:'.$this->rescue2->id]);
        $this->get('/admin/medical-statistics')->assertOk();

        $this->assertSame($before, $snapshot());
        $this->assertSame($movements, StockMovement::query()->orderBy('id')->get(['id', 'quantity_base', 'movement_type'])->toArray());
    }

    public function test_every_filter_the_page_offers_is_accepted_by_the_real_report(): void
    {
        $this->history();
        $this->actAs($this->viewer);
        $overview = $this->realOverview();
        $page = $this->page();
        $units = $page->viewData('supplyUnits');
        $items = $page->viewData('supplyItems');

        $this->assertArrayHasKey('unit:'.$this->rescue2->id, $units);
        $this->assertNotEmpty($items);
        $this->assertSame($overview['filter_options']['categories'], $page->viewData('supplyCategories'));
        $page->set('supplyUnit', 'unit:'.$this->rescue2->id)->assertSee('Needs attention')->assertDontSee('Fix the filters above');
        $page->set('supplyUnit', 'detail')->assertDontSee('Fix the filters above');
        $page->set('supplyUnit', 'unassigned')->assertDontSee('Fix the filters above');
        $page->set('supplyUnit', '')->set('supplyItem', (string) array_key_first($items))->assertDontSee('Fix the filters above');
        $page->set('supplyItem', '')->set('supplyPeriod', 'custom')->assertDontSee('Fix the filters above');
        foreach (['90d', '12m'] as $period) {
            $page->set('supplyPeriod', $period)->assertDontSee('Fix the filters above')->assertSee('Needs attention');
        }
    }

    public function test_the_export_link_downloads_the_real_file_with_the_same_filters(): void
    {
        if (! collect(Route::getRoutes()->getRoutes())->contains(fn ($route): bool => $route->uri() === 'ems/api/supply-data/export.xlsx')) {
            $this->markTestSkipped('The export route is built by the backend agent; this test runs once it is registered.');
        }
        $this->history();
        $this->actAs($this->viewer);
        $page = $this->page()->set('supplyPeriod', '90d')->set('supplyUnit', 'unit:'.$this->rescue2->id);
        $url = $page->instance()->supplyExportUrl();

        $this->assertStringContainsString('/ems/api/supply-data/export.xlsx?', $url);
        $response = $this->get($url)->assertOk();
        $this->assertStringContainsString('spreadsheetml', (string) $response->headers->get('Content-Type'));

        $this->actAs($this->emsAdmin([], 'Plain Admin'));
        $this->get($url)->assertForbidden();
    }

    public function test_the_real_page_stays_inside_the_admin_query_budget(): void
    {
        $this->history();
        $this->actAs($this->emsSuperAdmin());
        $statements = [];
        DB::listen(static function ($query) use (&$statements): void {
            $statements[] = $query->sql;
        });
        $this->get('/admin/medical-statistics')->assertOk();

        $count = count($statements);
        fwrite(STDERR, "SUPPLY_DATA_PAGE queries={$count}\n");
        $repeated = collect($statements)->countBy()->sortDesc()->take(6)->map(fn (int $n, string $sql): string => "{$n}x ".mb_substr($sql, 0, 120))->implode("\n");
        // GoLiveCapacityProbeTest's admin page budget is 33 for the shared chrome. The report is an analytics call, so it
        // is allowed its own reads on top, but it must stay a bounded number that does not grow with rows.
        $this->assertLessThanOrEqual(60, $count, "Medical Supply Data used {$count} queries. Most repeated:\n{$repeated}");
    }

    public function test_a_balance_loaded_as_an_estimate_reads_estimated_in_the_table_and_the_drill_down(): void
    {
        $file = sys_get_temp_dir().DIRECTORY_SEPARATOR.'supply-data-estimates-'.bin2hex(random_bytes(4)).'.json';
        File::put($file, json_encode([
            'note' => 'Test guesses.',
            'locations' => [['code' => 'OFFICE', 'label' => 'Capt 5 Office', 'sort' => 455]],
            'estimates' => [['name' => 'Stethoscope', 'typed' => 3, 'location_code' => 'OFFICE', 'set' => ['section_key' => 'stored-with-captain-5']]],
            'moves' => [],
        ], JSON_THROW_ON_ERROR));
        $this->artisan('ems:apply-supply-estimates', ['--file' => $file, '--apply' => true])->assertSuccessful();
        File::delete($file);
        $this->actAs($this->viewer);
        $stethoscope = $this->supplyItem('sr.t.03');
        $row = collect($this->realOverview()['stock'])->firstWhere('item_id', $stethoscope->id);
        $this->assertSame('estimate', $row['basis']);
        $this->assertNull($row['days_of_supply']);

        $page = $this->page();
        $html = $page->html();
        $this->assertSame(1, preg_match('/<tr[^>]*wire:key="stock-'.$stethoscope->id.'"[^>]*>(?:(?!<\/tr>).)*<\/tr>/s', $html, $match));
        $this->assertStringContainsString('Estimated', $match[0]);
        $this->assertStringContainsString('Not a physical count', $match[0]);
        $page->mountAction('itemDetail', ['item' => $stethoscope->id])->assertSee('Estimated')->assertSee('A best-guess starting figure');
    }
}
