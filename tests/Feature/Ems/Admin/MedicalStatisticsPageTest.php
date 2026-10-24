<?php

declare(strict_types=1);

namespace Tests\Feature\Ems\Admin;

use App\Filament\Admin\Pages\MedicalDataStatistics;
use App\Models\Ems\InventoryRequest;
use App\Models\User;
use App\Services\Ems\EmsStatistics;
use App\Services\Ems\Exports\StatisticsView;
use App\Services\Ems\RequestWorkflowService;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Feature\Ems\Concerns\BuildsEmsWorld;
use Tests\TestCase;

final class MedicalStatisticsPageTest extends TestCase
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
    }

    private function page(): Testable
    {
        return Livewire::test(MedicalDataStatistics::class);
    }

    /** Two requests from Rescue 2 (tape 4, gauze 10; tape 2), a Detail request, and one cancelled; the first tape line is issued. */
    private function fixture(): void
    {
        $member = $this->emsMember('Paramedic', 'Pat Medic');
        $first = $this->submitRequest($member, [
            ['form' => 'als-med-bag', 'item_key' => 'als.trauma.tape', 'quantity' => 4],
            ['form' => 'als-med-bag', 'item_key' => 'als.trauma.gauze', 'quantity' => 10],
        ]);
        $this->submitRequest($member, [['form' => 'als-med-bag', 'item_key' => 'als.trauma.tape', 'quantity' => 2]]);
        $this->submitRequest($member, [['form' => 'als-med-bag', 'item_key' => 'als.trauma.gauze', 'quantity' => 6]], [
            'target' => ['kind' => 'detail_event', 'event_assignment' => 'Beach detail'],
        ]);
        $cancelled = $this->submitRequest($member, [['form' => 'als-med-bag', 'item_key' => 'als.trauma.tape', 'quantity' => 9]]);
        $this->emsPost($member, "/employee/forms/api/ems/requests/{$cancelled['uuid']}/cancel")->assertSuccessful();

        $tapeLine = (int) $this->requestModel($first['uuid'])->lines()->where('item_key', 'als.trauma.tape')->value('id');
        app(RequestWorkflowService::class)->issue((int) $this->requestModel($first['uuid'])->id, $this->manager, 'stats-issue-0001', ['issuances' => [['line_id' => $tapeLine, 'quantity' => 3]]]);
    }

    public function test_the_page_shows_the_numbers_from_the_statistics_service_with_each_definition(): void
    {
        $this->fixture();
        $this->actAs($this->manager);
        $today = CarbonImmutable::now('America/New_York');
        $expected = app(EmsStatistics::class)->report([
            'date_from' => $today->subDays(89)->format('Y-m-d'), 'date_to' => $today->format('Y-m-d'),
        ]);

        $page = $this->page()->assertOk()
            ->assertSee('These figures count requests and issuances, not supplies used on calls.')
            ->assertSee('Requests, not consumption');
        $view = $page->viewData('view');

        // The numbers on screen are the service's numbers.
        $this->assertSame(3, $expected['totals']['requests_counted']);
        $this->assertSame(4, $expected['totals']['requests_submitted']);
        $this->assertSame(22, $expected['totals']['qty_requested'], '4 + 10 + 2 + 6, the cancelled request is not counted.');
        $this->assertSame(3, $expected['totals']['qty_issued']);
        $byLabel = collect($view['metrics'])->keyBy('label');
        $this->assertSame('4', $byLabel['Requests submitted']['value']);
        $this->assertSame('1', $byLabel['Cancelled by the member']['value']);
        $this->assertSame('3', $byLabel['Requests counted']['value']);
        $this->assertSame('22', $byLabel['Quantity requested']['value']);
        $this->assertSame('3', $byLabel['Quantity issued']['value']);
        $this->assertSame((string) $expected['totals']['qty_outstanding'], $byLabel['Still outstanding']['value']);
        foreach ($view['metrics'] as $metric) {
            $this->assertNotSame('', $metric['definition'], $metric['label'].' needs its written definition.');
            $page->assertSee($metric['definition']);
        }

        // Tables carry the same data: top items, a monthly trend, per-unit frequency, detail events.
        $sections = collect($view['sections'])->keyBy('heading');
        $top = $sections['Top requested items']->rows;
        $this->assertSame(['Gauze 4x4', 'ALS Med Bag or Box', 16, 0, 2], $top[0]);
        $this->assertSame(['Tape 1"', 'ALS Med Bag or Box', 6, 3, 2], $top[1]);
        $this->assertCount(1, $sections['Monthly trend']->rows);
        $this->assertSame(3, $sections['Monthly trend']->rows[0][1]);
        $unit = collect($sections['Requests by unit']->rows)->firstWhere(0, 'Rescue 2');
        $this->assertSame(2, $unit[1]);
        $this->assertEqualsWithDelta(round(2 / $expected['totals']['weeks_in_range'], 2), $unit[2], 0.001);
        $this->assertSame(1, $sections['Detail and special events']->rows[0][1]);
        $this->assertSame([['Beach detail', 1]], $sections['Typed events']->rows);
        $page->assertSee('Beach detail')->assertSee('Requests per week');
    }

    public function test_filters_narrow_the_figures_and_an_empty_range_explains_itself(): void
    {
        $this->fixture();
        $this->actAs($this->manager);

        $unit = $this->page()->set('apparatusId', (string) $this->rescue2->id);
        $this->assertSame('2', collect($unit->viewData('view')['metrics'])->keyBy('label')['Requests counted']['value']);
        $unit->assertSee('Unit: Rescue 2');

        $detail = $this->page()->set('targetKind', 'detail_event');
        $this->assertSame('1', collect($detail->viewData('view')['metrics'])->keyBy('label')['Requests counted']['value']);

        $empty = $this->page()->set('dateFrom', '2020-01-01')->set('dateTo', '2020-01-31')
            ->assertSee('There are no requests in this date range');
        $this->assertTrue($empty->viewData('view')['empty']);

        $this->page()->set('dateFrom', 'last tuesday')->assertSee('was not understood');
        $this->page()->set('dateFrom', '2026-12-01')->set('dateTo', '2026-01-01')->assertSee('start date is after the end date');
        $this->assertSame(
            CarbonImmutable::now('America/New_York')->subDays(89)->format('Y-m-d'),
            $this->page()->set('dateFrom', '2020-01-01')->call('resetRange')->get('dateFrom'),
        );
    }

    public function test_the_default_range_is_the_last_90_days_in_eastern_time(): void
    {
        $this->actAs($this->manager);
        $page = $this->page();
        $today = CarbonImmutable::now('America/New_York')->format('Y-m-d');
        $this->assertSame($today, $page->get('dateTo'));
        $this->assertSame(CarbonImmutable::now('America/New_York')->subDays(89)->format('Y-m-d'), $page->get('dateFrom'));
        $page->assertSee('There are no requests in this date range');
        $this->assertSame(0, InventoryRequest::query()->count());
    }

    public function test_excel_and_pdf_downloads_work_for_a_viewer_and_the_page_is_closed_to_others(): void
    {
        $this->fixture();
        $this->actAs($this->viewer);
        $this->page()->callAction('exportExcel')->assertFileDownloaded()
            ->callAction('exportPdf')->assertFileDownloaded();

        $this->actAs($this->emsAdmin([], 'Plain Admin'));
        $this->assertFalse(MedicalDataStatistics::canAccess());
        Livewire::test(MedicalDataStatistics::class)->assertForbidden();
        $this->assertSame('These figures count requests and issuances, not supplies used on calls.', StatisticsView::HEADER_TEXT);
    }
}
