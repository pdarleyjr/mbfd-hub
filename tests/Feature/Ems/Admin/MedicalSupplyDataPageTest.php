<?php

declare(strict_types=1);

namespace Tests\Feature\Ems\Admin;

use App\Filament\Admin\Pages\MedicalDataStatistics;
use App\Models\Captain5\Captain5AccessOverride;
use App\Models\Ems\StockMovement;
use App\Models\User;
use App\Services\Ems\SupplyData\SupplyDataReport;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Feature\Ems\Admin\Fakes\FakeSupplyDataReport;
use Tests\Feature\Ems\Concerns\BuildsEmsWorld;
use Tests\TestCase;

/**
 * Admin -> EMS -> Medical Supply Data, the Supply data section. The report is a stand-in that returns the
 * shapes in docs/ems/supply-data-contract.md, so these tests prove what the PAGE does with the report's answer:
 * which filters it asks for, how it words nulls and zeros, who may open it, and that it never writes.
 * MedicalSupplyDataRealReportTest runs the same page against the real report.
 */
final class MedicalSupplyDataPageTest extends TestCase
{
    use BuildsEmsWorld;
    use RefreshDatabase;

    private User $manager;

    private User $viewer;

    private FakeSupplyDataReport $report;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->buildEmsWorld();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->manager = $this->emsAdmin(['admin.ems.manage'], 'Manny Manager');
        $this->viewer = $this->emsAdmin(['admin.ems.view'], 'Vera Viewer');
        $this->useReport(new FakeSupplyDataReport);
    }

    protected function tearDown(): void
    {
        $this->tearDownEmsWorld();
        parent::tearDown();
    }

    private function useReport(FakeSupplyDataReport $report): void
    {
        $this->report = $report;
        $this->app->instance(SupplyDataReport::class, $report);
    }

    /** @param array<string, mixed> $changes top-level keys to replace in the fixture */
    private function useOverview(array $changes): void
    {
        $this->useReport(new FakeSupplyDataReport($changes + FakeSupplyDataReport::overviewFixture()));
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

    /** @return list<string> */
    private function emsNavigationLabels(): array
    {
        $group = collect(Filament::getNavigation())->first(fn ($group): bool => $group->getLabel() === 'EMS');

        return $group === null ? [] : collect($group->getItems())->map(fn ($item): string => $item->getLabel())->all();
    }

    // ------------------------------------------------------------------ access

    public function test_the_page_renders_for_an_ems_viewer_and_a_manager_through_the_legacy_route(): void
    {
        foreach ([$this->viewer, $this->manager, $this->emsSuperAdmin()] as $user) {
            $this->actAs($user);
            $this->get('/admin/medical-statistics')->assertOk()
                ->assertSee('Distributed from the supply room — not patient use')
                ->assertSee('Needs attention')
                ->assertSee('Supply data')
                ->assertSee('Requests');
        }
    }

    public function test_it_is_closed_to_members_without_ems_access_and_to_a_captain_5_deny_override(): void
    {
        $this->actAs($this->emsAdmin([], 'Plain Admin'));
        $this->assertFalse(MedicalDataStatistics::canAccess());
        $this->get('/admin/medical-statistics')->assertForbidden();
        $this->page()->assertForbidden();

        $this->actAs($this->emsMember('Firefighter', 'Fran Fighter'));
        $this->assertNotSame(200, $this->get('/admin/medical-statistics')->getStatusCode());

        // A viewer with a live page: once a Captain 5 deny override lands, every interaction is refused.
        $this->actAs($this->viewer);
        $pages = ['showTab' => $this->page(), 'mount' => $this->page(), 'reset' => $this->page(), 'more' => $this->page()];
        $this->assertTrue(MedicalDataStatistics::canAccess());

        Captain5AccessOverride::query()->create([
            'employee_profile_id' => $this->viewer->employee_profile_id, 'effect' => 'deny', 'reason' => 'Under review', 'set_by_user_id' => $this->manager->id,
        ]);
        $this->actAs($this->viewer);

        $this->assertFalse(MedicalDataStatistics::canAccess());
        $this->get('/admin/medical-statistics')->assertForbidden();
        $pages['showTab']->call('showTab', 'requests')->assertForbidden();
        $pages['mount']->call('mountAction', 'itemDetail', ['item' => 1])->assertForbidden();
        $pages['reset']->call('resetSupplyFilters')->assertForbidden();
        $pages['more']->call('showMoreStock')->assertForbidden();
        $this->page()->assertForbidden();
        $this->assertSame([], $this->report->callsTo('item'), 'A refused page never asks the report for anything.');
    }

    public function test_the_navigation_has_one_medical_supply_data_entry_and_no_statistics_entry(): void
    {
        $this->actAs($this->viewer);
        $labels = $this->emsNavigationLabels();

        $this->assertContains('Medical Supply Data', $labels);
        $this->assertNotContains('Medical Data / Statistics', $labels);
        $this->assertSame([], array_values(array_filter($labels, fn (string $label): bool => stripos($label, 'statistic') !== false)));
        $this->assertCount(1, array_filter($labels, fn (string $label): bool => $label === 'Medical Supply Data'));
        $this->assertCount(4, $labels, 'Submitted forms, submitted supply room inventory, supply room and Medical Supply Data: one entry each.');
        $this->assertSame('/admin/medical-statistics', parse_url(MedicalDataStatistics::getUrl(), PHP_URL_PATH));
    }

    // -------------------------------------------------- what the page asks for

    public function test_the_default_view_asks_the_report_for_the_last_30_days_with_no_other_filter(): void
    {
        $this->actAs($this->viewer);
        $this->page()->assertOk()->assertSet('tab', 'supply')->assertSet('supplyPeriod', '30d');

        $calls = $this->report->callsTo('overview');
        $this->assertCount(1, $calls, 'One report call renders the whole screen.');
        $filters = $calls[0]['filters'];
        $this->assertSame('30d', $filters->period);
        $this->assertNull($filters->unit);
        $this->assertNull($filters->item);
        $this->assertNull($filters->category);
    }

    public function test_the_filters_are_passed_to_the_report_exactly_as_chosen(): void
    {
        $item = $this->supplyItem('sr.t.01');
        $this->actAs($this->viewer);

        $this->page()->set('supplyPeriod', '90d')->set('supplyUnit', 'unit:45')->set('supplyCategory', 'PPE')->set('supplyItem', (string) $item->id);
        $last = end($this->report->calls)['filters'];
        $this->assertSame('90d', $last->period);
        $this->assertSame('unit:45', $last->unit);
        $this->assertSame($item->id, $last->item);
        $this->assertSame('PPE', $last->category);

        $this->page()->set('supplyPeriod', '12m');
        $this->assertSame('12m', end($this->report->calls)['filters']->period);

        $this->page()->set('supplyPeriod', 'custom')->set('supplyFrom', '2026-10-01')->set('supplyTo', '2026-10-31');
        $custom = end($this->report->calls)['filters'];
        $this->assertSame('custom', $custom->period);
        $this->assertSame('2026-10-01', $custom->from->toDateString());
        $this->assertSame('2026-10-31', $custom->to->toDateString());
    }

    public function test_choosing_custom_dates_starts_from_the_last_30_days_and_changing_the_category_clears_the_supply(): void
    {
        $this->actAs($this->viewer);
        $page = $this->page()->set('supplyPeriod', 'custom');
        $this->assertNotSame('', $page->get('supplyFrom'));
        $this->assertNotSame('', $page->get('supplyTo'));
        $page->assertSee('Until');

        $this->assertSame('', $this->page()->set('supplyItem', '5')->set('supplyCategory', 'PPE')->get('supplyItem'));
    }

    public function test_bad_filters_get_a_plain_sentence_and_the_report_is_not_asked_for_figures(): void
    {
        $this->actAs($this->viewer);
        $today = now('America/New_York')->format('Y-m-d');

        // The last value in each case is the one that makes the range wrong.
        $cases = [
            'start missing' => [['supplyTo' => '2026-10-31', 'supplyFrom' => ''], 'Choose a start date for the custom range.'],
            'end missing' => [['supplyFrom' => '2026-10-01', 'supplyTo' => ''], 'Choose an end date for the custom range.'],
            'not a date' => [['supplyTo' => '2026-10-31', 'supplyFrom' => 'last tuesday'], 'The start date was not understood.'],
            'backwards' => [['supplyFrom' => '2026-10-31', 'supplyTo' => '2026-10-01'], 'The start date is after the end date.'],
            'future' => [['supplyFrom' => '2026-10-01', 'supplyTo' => now('America/New_York')->addDays(3)->format('Y-m-d')], 'The end date cannot be in the future.'],
            'too long' => [['supplyTo' => $today, 'supplyFrom' => '2024-01-01'], 'A custom range can cover at most 400 days.'],
        ];
        foreach ($cases as $name => [$state, $sentence]) {
            $page = $this->page()->set('supplyPeriod', 'custom');
            $last = array_key_last($state);
            foreach (array_slice($state, 0, -1, true) as $property => $value) {
                $page->set($property, $value);
            }
            $asked = count($this->report->callsTo('overview'));
            $page->set($last, $state[$last]);
            $page->assertSee($sentence)->assertSee('Fix the filters above to see supply data')->assertDontSee('Needs attention');
            $this->assertCount($asked, $this->report->callsTo('overview'), "{$name}: once the filters were wrong, no figures were asked for.");
        }
    }

    public function test_a_tampered_filter_the_report_refuses_shows_the_reports_friendly_sentence(): void
    {
        $this->actAs($this->viewer);
        $asked = count($this->report->callsTo('overview'));

        $this->page()->set('supplyUnit', 'rescue-two')->assertSee('Choose a unit from the list')->assertSee('Fix the filters above');
        $this->page()->set('supplyItem', '99999999')->assertSee('Choose a supply room item from the list');
        $this->page()->set('supplyPeriod', 'forever')->assertSee('That time period is not one of the choices');
        $this->assertCount($asked + 3, $this->report->callsTo('overview'), 'Only each fresh page load (default filters) asked for figures.');
    }

    public function test_the_filter_lists_offer_units_categories_and_supplies_but_never_a_controlled_item(): void
    {
        $this->actAs($this->viewer);
        $page = $this->page();
        $options = $page->viewData('supplyItems');

        $this->assertContains('PPE', $page->viewData('supplyCategories'));
        $this->assertArrayHasKey('detail', $page->viewData('supplyUnits'));
        $this->assertArrayHasKey($this->supplyItem('sr.t.01')->id, $options);
        $this->assertArrayNotHasKey($this->supplyItem('sr.t.06')->id, $options, 'Fentanyl is a controlled item: supply data never lists it.');
    }

    // ------------------------------------------------------------ what it shows

    public function test_the_figures_on_screen_are_the_reports_figures(): void
    {
        $this->actAs($this->viewer);
        $page = $this->page()->assertOk();

        $html = $page->html();
        $this->assertMatchesRegularExpression('/data-testid="attention-below_minimum".*?supply-stat-value[^>]*>\s*3\s/s', $html);
        $this->assertMatchesRegularExpression('/data-testid="attention-estimated".*?supply-stat-value[^>]*>\s*44\s/s', $html);
        $this->assertMatchesRegularExpression('/data-testid="headline-distribution_events".*?supply-stat-value[^>]*>\s*12\s/s', $html);
        $page->assertSee('+33.3% compared with 9 in the previous period.')
            ->assertSee('Gloves, nitrile (L)')->assertSee('RESCUE R2')->assertSee('Detail / Special Event')
            ->assertSee('Oct 4, 2026 to Nov 2, 2026')->assertSee('Compared with Sep 4, 2026 to Oct 3, 2026')
            ->assertSee('Typical time to reconcile')->assertSee('70.5 hours')
            ->assertSee('What these numbers mean')->assertSee('Net quantity that left the supply room for a unit.');
    }

    public function test_the_not_patient_use_label_sits_beside_every_distribution_figure(): void
    {
        $this->actAs($this->viewer);
        $html = $this->page()->html();

        $this->assertGreaterThanOrEqual(5, substr_count($html, 'Distributed from the supply room — not patient use'));
        foreach (['data-testid="supply-label"', 'data-testid="supplies-note"', 'data-testid="supply-headline"'] as $marker) {
            $this->assertStringContainsString($marker, $html);
        }
    }

    public function test_an_estimated_balance_is_labelled_estimated_and_never_gets_days_of_supply(): void
    {
        $this->actAs($this->viewer);
        $page = $this->page();

        $page->assertSee('Estimated')->assertSee('Not a physical count');
        $row = $this->stockRowHtml($page->html(), 'Tape 1 inch');
        $this->assertStringContainsString('Estimated', $row);
        $this->assertStringContainsString('Cannot be worked out', $row, 'Days of supply for an estimate is a dash with a reason, never a number.');
        $this->assertStringNotContainsString('>Counted<', $row);

        $counted = $this->stockRowHtml($page->html(), 'Gloves, nitrile (L)');
        $this->assertStringContainsString('Counted', $counted);
        $this->assertStringNotContainsString('Not a physical count', $counted);
    }

    public function test_null_is_a_dash_with_a_reason_and_zero_is_a_real_zero(): void
    {
        $overview = FakeSupplyDataReport::overviewFixture();
        $overview['attention'] = ['below_minimum' => 0, 'zero_stock' => null, 'estimated' => 1, 'not_counted_recently' => 0, 'needs_reorder' => 0];
        $overview['headline']['change_pct'] = null;
        $overview['headline']['previous_distribution_events'] = 0;
        $overview['fulfillment']['median_hours_to_reconcile'] = null;
        $this->useOverview($overview);
        $this->actAs($this->viewer);
        $page = $this->page();
        $html = $page->html();

        $this->assertMatchesRegularExpression('/data-testid="attention-below_minimum".*?supply-stat-value[^>]*>\s*0\s/s', $html, 'A real zero reads 0.');
        $this->assertMatchesRegularExpression('/data-testid="attention-zero_stock".*?supply-stat-value[^>]*>\s*—/su', $html, 'null reads as a dash.');
        $page->assertSee('Cannot be worked out yet, so no number is shown.')
            ->assertSee('Nothing was distributed in the previous period, so there is no percentage to show.')
            ->assertSee('No request was reconciled in this period, so there is nothing to measure.')
            ->assertSee('No physical count has a verified baseline to compare against yet.')
            ->assertDontSee('NaN')->assertDontSee('INF');
        $this->assertStringNotContainsString('+0%', $html);
    }

    public function test_review_fix_wording_for_never_counted_reorder_and_the_request_side_quantity(): void
    {
        $this->useOverview(FakeSupplyDataReport::overviewFixture());
        $this->actAs($this->viewer);

        $this->page()
            ->assertSee('Cannot tell (not counted)')
            ->assertSee('Recorded as issued on requests')
            ->assertDontSee('Total issued for those requests')
            ->assertSee('below their order level');
    }

    public function test_every_empty_state_explains_itself(): void
    {
        $overview = FakeSupplyDataReport::overviewFixture();
        foreach (['top_items', 'units', 'stock'] as $key) {
            $overview[$key] = [];
        }
        $overview['trend'] = [['week_start' => '2026-10-04', 'distribution_events' => 0, 'requests' => 0]];
        $overview['data_quality']['insufficient_data'] = [['section' => 'distribution', 'message' => 'Nothing was distributed from the supply room in this period. This is a real zero, not missing data.']];
        $this->useOverview($overview);
        $this->actAs($this->viewer);

        $this->page()->assertSee('No supplies were distributed in this period with these filters.')
            ->assertSee('No unit requested or received supplies in this period with these filters.')
            ->assertSee('No stocked supplies match these filters.')
            ->assertSee('Nothing was distributed or requested in this period, so there is no trend to draw.')
            ->assertSee('This is a real zero, not missing data.')
            ->assertDontSee('Show more supplies');
    }

    public function test_the_trend_chart_has_a_text_alternative_and_a_table_of_the_same_numbers(): void
    {
        $this->actAs($this->viewer);
        $html = $this->page()->html();

        $this->assertStringContainsString('role="img"', $html);
        $this->assertStringContainsString('aria-labelledby="supply-trend-title supply-trend-desc"', $html);
        $this->assertStringContainsString('<title id="supply-trend-title">Distribution events and requests by week</title>', $html);
        $this->assertStringContainsString('Show the numbers behind the chart', $html);
        $this->assertMatchesRegularExpression('/Week starting.*?Oct 25.*?>\s*5\s*<.*?>\s*1\s*</s', $html);
        $this->assertStringContainsString('Week of Oct 25', str_replace('Week starting', 'Week of', $html), 'Each bar carries its own title text.');
        $this->assertStringContainsString('Week starting Oct 25: 5 distribution events', $html);
    }

    public function test_a_monthly_trend_is_labelled_by_month(): void
    {
        $overview = FakeSupplyDataReport::overviewFixture();
        $overview['trend'] = [['month_start' => '2026-09-01', 'distribution_events' => 2, 'requests' => 1], ['month_start' => '2026-10-01', 'distribution_events' => 4, 'requests' => 3]];
        $this->useOverview($overview);
        $this->actAs($this->viewer);

        $this->page()->assertSee('Month by month')->assertSee('Sep 2026')->assertSee('Oct 2026')->assertSee('Month starting');
    }

    public function test_stock_is_paged_and_can_be_narrowed_to_what_is_low(): void
    {
        $overview = FakeSupplyDataReport::overviewFixture();
        $overview['stock'] = array_map(fn (int $n): array => FakeSupplyDataReport::stockRow(100 + $n, "Supply {$n}", 'counted', 20, 'ok'), range(1, 30));
        $overview['stock'][] = FakeSupplyDataReport::stockRow(200, 'Low supply', 'counted', 2, 'below_minimum', reorder: true);
        $this->useOverview($overview);
        $this->actAs($this->viewer);

        $page = $this->page()->assertSee('Showing 25 of 31 supplies.')->assertSee('Show more supplies')->assertDontSee('Supply 30');
        $page->call('showMoreStock')->assertSee('Showing 31 of 31 supplies.')->assertSee('Supply 30')->assertDontSee('Show more supplies');
        $page->set('stockLowOnly', true)->assertSee('Showing 1 of 1 supply.')->assertSee('Low supply')->assertSee('Reorder')->assertSee('Suggested: 6 case')->assertDontSee('Supply 2<');
    }

    // ------------------------------------------------------------ drill-downs

    public function test_the_item_drill_down_shows_the_reports_detail_with_the_estimated_badge(): void
    {
        $item = $this->supplyItem('sr.t.01');
        $this->actAs($this->viewer);

        $page = $this->page()->mountAction('itemDetail', ['item' => $item->id]);
        $page->assertSee($item->name)
            ->assertSee('On the shelf')->assertSee('Estimated')->assertSee('A best-guess starting figure')
            ->assertSee('Distribution in this period')->assertSee('RESCUE R2')->assertSee('55.6%')
            ->assertSee('Order MIR-ORD-7')->assertSee('MIR-000004')->assertSee('Physical counts')
            ->assertSee('Cannot be worked out: it needs a counted balance');
        $call = $this->report->callsTo('item')[0];
        $this->assertSame([$item->id], $call['args']);
        $this->assertSame('30d', $call['filters']->period, 'The drill-down uses the same filters as the page.');
        $this->assertNull($call['filters']->item, 'As on the API, the supply comes from the click and the item and category filters are left out.');
        $this->assertNull($call['filters']->category);
    }

    public function test_a_controlled_or_unknown_supply_never_opens(): void
    {
        $this->actAs($this->viewer);

        $this->page()->mountAction('itemDetail', ['item' => $this->supplyItem('sr.t.06')->id])->assertSee('This supply is not available.');
        $this->page()->mountAction('itemDetail', ['item' => '999999'])->assertSee('This supply is not available.');
        $this->page()->mountAction('itemDetail', ['item' => 'abc; drop table'])->assertSee('This supply is not available.');
        $this->assertSame([], $this->report->callsTo('item'), 'Nothing the page could not vouch for reaches the report.');
    }

    public function test_the_unit_drill_down_shows_the_reports_detail_and_links_to_the_request(): void
    {
        $this->actAs($this->viewer);

        $page = $this->page()->mountAction('unitDetail', ['unit' => 'unit:45']);
        $page->assertSee('RESCUE R2')->assertSee('Most requested')->assertSee('Gauze 4x4')
            ->assertSee('Most distributed to this unit')->assertSee('Gloves, nitrile (L)')
            ->assertSee('MIR-000004')->assertSee('Reconciled')
            ->assertSeeHtml('/admin/rescue-inventory/5f0c1f0e-6d0e-4c33-8f43-1d1a5c3b9a10');
        $this->assertSame(['unit:45'], $this->report->callsTo('unit')[0]['args']);

        $this->page()->mountAction('unitDetail', ['unit' => 'detail']);
        $this->assertSame('detail', $this->report->callsTo('unit')[1]['args'][0]);
    }

    public function test_an_unknown_or_malformed_unit_says_so_instead_of_failing(): void
    {
        $this->actAs($this->viewer);

        $this->page()->mountAction('unitDetail', ['unit' => 'unit:999'])->assertSee('This unit is not in the supply data.');
        $this->page()->mountAction('unitDetail', ['unit' => "unit:1' OR 1=1"])->assertSee('This unit is not in the supply data.');
        $this->assertCount(1, $this->report->callsTo('unit'), 'A malformed key never reaches the report.');
    }

    // ------------------------------------------------------------------ export

    public function test_the_export_button_opens_the_reports_own_file_with_the_filters_on_screen(): void
    {
        $this->actAs($this->viewer);
        $page = $this->page();
        $page->assertActionVisible('exportSupply')
            ->assertActionHasUrl('exportSupply', url('/ems/api/supply-data/export.xlsx').'?period=30d');

        $page->set('supplyPeriod', 'custom')->set('supplyFrom', '2026-10-01')->set('supplyTo', '2026-10-31')->set('supplyUnit', 'detail')->set('supplyCategory', 'PPE')
            ->assertActionHasUrl('exportSupply', url('/ems/api/supply-data/export.xlsx').'?'.http_build_query([
                'period' => 'custom', 'from' => '2026-10-01', 'to' => '2026-10-31', 'unit' => 'detail', 'category' => 'PPE',
            ]));

        $page->set('supplyFrom', '')->assertActionHidden('exportSupply');
    }

    // ------------------------------------------------------------- sections

    public function test_requests_stay_available_in_their_own_section_with_their_exports(): void
    {
        $this->actAs($this->viewer);

        $supply = $this->page()->assertActionHidden('exportExcel')->assertActionHidden('exportPdf');
        $asked = count($this->report->calls);
        $requests = $supply->call('showTab', 'requests')
            ->assertSee('These figures count requests and issuances, not supplies used on calls.')
            ->assertActionVisible('exportExcel')->assertActionVisible('exportPdf')->assertActionHidden('exportSupply')
            ->assertDontSee('Needs attention');
        $this->assertTrue($requests->viewData('view')['empty']);
        $this->assertCount($asked, $this->report->calls, 'The Requests section never asks the supply report for anything.');

        $this->assertSame('supply', $this->page()->call('showTab', 'bogus')->get('tab'));
    }

    public function test_the_page_can_only_read(): void
    {
        $this->actAs($this->manager);
        $before = [StockMovement::query()->count(), DB::table('ems_inventory_requests')->count(), DB::table('ems_supply_orders')->count(), DB::table('ems_inventory_snapshots')->count()];

        $this->page()->mountAction('itemDetail', ['item' => $this->supplyItem('sr.t.01')->id]);
        $this->page()->mountAction('unitDetail', ['unit' => 'detail']);
        $this->page()->call('showMoreStock')->call('showTab', 'requests')->call('showTab', 'supply')->call('resetSupplyFilters');

        $this->assertSame($before, [StockMovement::query()->count(), DB::table('ems_inventory_requests')->count(), DB::table('ems_supply_orders')->count(), DB::table('ems_inventory_snapshots')->count()]);

        $own = array_values(array_filter(
            array_map(static fn (\ReflectionMethod $m): string => $m->getName(), (new \ReflectionClass(MedicalDataStatistics::class))->getMethods(\ReflectionMethod::IS_PUBLIC)),
            static fn (string $method): bool => ! (new \ReflectionMethod(MedicalDataStatistics::class, $method))->isStatic()
                && ! in_array((new \ReflectionMethod(MedicalDataStatistics::class, $method))->getDeclaringClass()->getName(), [\Filament\Pages\Page::class, \Livewire\Component::class], true)
                && (new \ReflectionMethod(MedicalDataStatistics::class, $method))->getDeclaringClass()->getName() !== \Filament\Pages\BasePage::class,
        ));
        foreach ($own as $method) {
            $this->assertDoesNotMatchRegularExpression('/(create|save|store|issue|reconcile|order|delete|update(?!d)|count|record)/i', $method, "{$method} must not look like a write.");
        }
    }

    // ------------------------------------------------------------ query budget

    public function test_the_page_stays_inside_the_admin_query_budget(): void
    {
        // The same measure GoLiveCapacityProbeTest uses: a super administrator, whose sidebar checks are free.
        $this->actAs($this->emsSuperAdmin());
        $statements = $this->statementsFor('/admin/medical-statistics');
        $repeated = collect($statements)->countBy()->sortDesc()->take(5)->map(fn (int $n, string $sql): string => "{$n}x ".mb_substr($sql, 0, 110))->implode('
');

        // Admin pages carry about 30 queries of shared chrome; the probe allows 33 with the three shared badge counts.
        $this->assertLessThanOrEqual(33, count($statements), 'The Supply data page used '.count($statements)." queries. Most repeated:
{$repeated}");
    }

    public function test_the_supply_data_adds_few_queries_of_its_own_for_an_ems_viewer(): void
    {
        $this->actAs($this->viewer);
        $baseline = count($this->statementsFor('/admin/medical-supply-room'));
        $mine = count($this->statementsFor('/admin/medical-statistics'));

        // A viewer's sidebar costs more than a super administrator's (every page asks EmsAccess); this page must add little on top of its neighbour.
        $this->assertLessThanOrEqual($baseline + 12, $mine, "Supply data used {$mine} queries against {$baseline} for the Medical Supply Room page.");
    }

    /** @return list<string> */
    private function statementsFor(string $uri): array
    {
        $statements = [];
        DB::listen(static function ($query) use (&$statements): void {
            $statements[] = $query->sql;
        });
        $this->get($uri)->assertOk();

        return $statements;
    }

    private function stockRowHtml(string $html, string $name): string
    {
        $this->assertSame(1, preg_match('/<tr[^>]*wire:key="stock-\d+"[^>]*>(?:(?!<\/tr>).)*'.preg_quote($name, '/').'(?:(?!<\/tr>).)*<\/tr>/s', $html, $match), "No stock row for {$name}.");

        return $match[0];
    }
}
