<?php

declare(strict_types=1);

namespace Tests\Feature\Ems\Admin;

use App\Filament\Resources\InventoryRequestResource;
use App\Filament\Resources\InventoryRequestResource\Pages\ListInventoryRequests;
use App\Models\Ems\InventoryRequest;
use App\Models\User;
use App\Services\Ems\EmsStatistics;
use App\Services\Ems\Exports\ReportWriter;
use App\Services\Ems\Exports\RequestListExporter;
use App\Services\Ems\Exports\SnapshotExporter;
use App\Services\Ems\Exports\StatisticsExporter;
use App\Services\Ems\Exports\StatisticsView;
use App\Services\Ems\SnapshotPresenter;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Ems\Concerns\BuildsEmsWorld;
use Tests\TestCase;

final class EmsExportsTest extends TestCase
{
    use BuildsEmsWorld;
    use RefreshDatabase;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->buildEmsWorld();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->manager = $this->emsAdmin(['admin.ems.manage'], 'Manny Manager');
    }

    protected function tearDown(): void
    {
        $this->tearDownEmsWorld();
        parent::tearDown();
    }

    private function read(string $bytes): Spreadsheet
    {
        $path = tempnam(sys_get_temp_dir(), 'ems-read-');
        file_put_contents($path, $bytes);
        try {
            return IOFactory::load($path);
        } finally {
            @unlink($path);
        }
    }

    // ------------------------------------------------------------- neutralizer

    /** @return array<string, array{0: string, 1: string}> */
    public static function neutralizerCases(): array
    {
        return [
            'equals' => ['=1+1', "'=1+1"],
            'plus' => ['+SUM(A1)', "'+SUM(A1)"],
            'minus' => ['-2+3', "'-2+3"],
            'at' => ['@cmd', "'@cmd"],
            'tab' => ["\tcmd", "'\tcmd"],
            'carriage return' => ["\rcmd", "'\rcmd"],
            'line feed' => ["\ncmd", "'\ncmd"],
            'fullwidth equals' => ["\u{FF1D}1+1", "'\u{FF1D}1+1"],
            'fullwidth plus' => ["\u{FF0B}1", "'\u{FF0B}1"],
            'fullwidth minus' => ["\u{FF0D}1", "'\u{FF0D}1"],
            'fullwidth at' => ["\u{FF20}SUM", "'\u{FF20}SUM"],
            'minus sign' => ["\u{2212}1", "'\u{2212}1"],
            'space then equals' => [' =1', "' =1"],
            'plain text' => ['Rescue 2', 'Rescue 2'],
            'number text' => ['002-12', '002-12'],
            'inner equals' => ['a=b', 'a=b'],
            'empty' => ['', ''],
        ];
    }

    #[DataProvider('neutralizerCases')]
    public function test_the_neutralizer_prefixes_formula_starters_and_control_characters(string $input, string $expected): void
    {
        $this->assertSame($expected, ReportWriter::neutralize($input));
    }

    // -------------------------------------------------------------- request list

    public function test_the_request_list_workbook_has_text_cells_typed_numbers_and_neutralized_strings(): void
    {
        $member = $this->emsMember('Paramedic', 'Pat Medic');
        $request = $this->submitRequest($member, [
            ['form' => 'als-med-bag', 'item_key' => 'als.trauma.tape', 'quantity' => 4],
            ['form' => 'als-med-bag', 'item_key' => 'als.trauma.gauze', 'quantity' => 10],
        ], ['target' => ['kind' => 'detail_event', 'event_assignment' => '=HYPERLINK("http://example.invalid","x")']]);
        DB::table('ems_inventory_requests')->where('uuid', $request['uuid'])->update(['submitter_name' => '@SUM(1+1)', 'close_reason' => '-cmd']);

        $this->actingAs($this->manager);
        $rows = InventoryRequestResource::getEloquentQuery()->get();
        $bytes = app(RequestListExporter::class)->xlsx($rows, 'Status: Submitted; Search: "tape"', CarbonImmutable::parse('2026-10-23 14:30:00', 'America/New_York'));

        $book = $this->read($bytes);
        $this->assertSame(['Requests', 'About this export'], $book->getSheetNames());
        $sheet = $book->getSheetByName('Requests');
        $this->assertSame('Number', $sheet->getCell('A1')->getValue());

        $this->assertSame($request['number'], $sheet->getCell('A2')->getValue());
        $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('A2')->getDataType());
        $this->assertSame("'@SUM(1+1)", $sheet->getCell('C2')->getValue(), 'A member name starting with @ is neutralized.');
        $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('C2')->getDataType());
        $this->assertSame("'=HYPERLINK(\"http://example.invalid\",\"x\")", $sheet->getCell('F2')->getValue());
        $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('F2')->getDataType());
        $this->assertSame("'-cmd", $sheet->getCell('K2')->getValue());

        foreach (['G2' => 2, 'H2' => 14, 'I2' => 0] as $coordinate => $value) {
            $this->assertSame(DataType::TYPE_NUMERIC, $sheet->getCell($coordinate)->getDataType(), $coordinate.' is a real number.');
            $this->assertEquals($value, $sheet->getCell($coordinate)->getValue());
        }
        $this->assertSame('Submitted', $sheet->getCell('J2')->getValue());

        $about = $book->getSheetByName('About this export');
        $facts = collect(range(1, 6))->mapWithKeys(fn (int $r): array => [(string) $about->getCell('A'.$r)->getValue() => (string) $about->getCell('B'.$r)->getValue()]);
        $this->assertSame('Status: Submitted; Search: "tape"', $facts['Filters and search']);
        $this->assertSame('1', $facts['Requests']);
        $this->assertStringContainsString('Oct 23, 2026', $facts['Generated (ET)']);
    }

    public function test_the_request_list_pdf_renders_landscape_with_repeating_headers_and_a_cap_note(): void
    {
        $member = $this->emsMember('Paramedic', 'Pat Medic');
        $this->submitRequest($member, [['form' => 'als-med-bag', 'item_key' => 'als.trauma.tape', 'quantity' => 1]]);
        $this->actingAs($this->manager);
        $rows = InventoryRequestResource::getEloquentQuery()->get();
        $exporter = app(RequestListExporter::class);
        $at = CarbonImmutable::now('America/New_York');

        $pdf = $exporter->pdf($rows, 'None (all requests)', $at);
        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertGreaterThan(1500, strlen($pdf));

        $spec = $exporter->spec($rows->concat($rows->all())->concat(array_fill(0, RequestListExporter::PDF_ROW_CAP, $rows->first())), 'x', $at, forPdf: true);
        $this->assertCount(RequestListExporter::PDF_ROW_CAP, $spec->sections[0]->rows);
        $this->assertStringContainsString('Use the Excel export for the full list', (string) $spec->sections[0]->note);
        $html = view('pdf.ems-report', ['logo' => null, 'title' => $spec->title, 'facts' => $spec->facts, 'sections' => $spec->sections])->render();
        $this->assertStringContainsString('display: table-header-group', $html, 'Header rows repeat on every page.');

        $this->assertSame('MBFD - Rescue Inventory Requests - 2026-10-23.xlsx', $exporter->filename('xlsx', CarbonImmutable::parse('2026-10-23 23:30:00', 'America/New_York')));
    }

    public function test_the_list_page_export_buttons_download_the_filtered_list(): void
    {
        $member = $this->emsMember('Paramedic', 'Pat Medic');
        $this->submitRequest($member, [['form' => 'als-med-bag', 'item_key' => 'als.trauma.tape', 'quantity' => 1]]);
        $this->actingAs($this->manager);

        Livewire::test(ListInventoryRequests::class)->call('loadTable')
            ->callAction('exportExcel')->assertFileDownloaded()
            ->callAction('exportPdf')->assertFileDownloaded();
    }

    public function test_the_list_exports_still_work_for_returned_reconciled_and_superseded_forms(): void
    {
        $member = $this->emsMember('Paramedic', 'Pat Medic');
        $uuids = [];
        foreach (['returned', 'reconciled', 'superseded'] as $status) {
            $uuids[$status] = $this->submitRequest($member, [['form' => 'als-med-bag', 'item_key' => 'als.trauma.tape', 'quantity' => 1]])['uuid'];
            InventoryRequest::query()->where('uuid', $uuids[$status])->update(['status' => $status]);
        }
        $this->actingAs($this->manager);

        $rows = InventoryRequestResource::getEloquentQuery()->orderBy('id')->get();
        $exporter = app(RequestListExporter::class);
        $at = CarbonImmutable::parse('2026-11-02 09:00:00', 'America/New_York');
        $sheet = $this->read($exporter->xlsx($rows, 'None (all requests)', $at))->getSheetByName('Requests');
        $statuses = collect(range(2, $sheet->getHighestRow()))->map(fn (int $r): string => (string) $sheet->getCell('J'.$r)->getValue())->all();
        $this->assertSame(['Needs correction', 'Reconciled', 'Replaced by a correction'], $statuses);
        $this->assertStringStartsWith('%PDF', $exporter->pdf($rows, 'None (all requests)', $at));

        $page = Livewire::test(ListInventoryRequests::class)->call('loadTable')
            ->filterTable('status', ['returned', 'reconciled', 'superseded']);
        $page->callAction('exportExcel')->assertFileDownloaded()->callAction('exportPdf')->assertFileDownloaded();
    }

    // ----------------------------------------------------------------- snapshot

    public function test_a_snapshot_exports_to_excel_and_pdf_with_its_basis_and_numbers(): void
    {
        $uuid = $this->finalizeCount($this->manager, ['sr.t.02' => 30, 'sr.t.03' => 1]);
        $snapshot = \App\Models\Ems\InventorySnapshot::query()->where('uuid', $uuid)->firstOrFail();
        $detail = app(SnapshotPresenter::class)->detail($snapshot);
        $exporter = app(SnapshotExporter::class);
        $at = CarbonImmutable::parse('2026-10-23 09:00:00', 'America/New_York');

        $book = $this->read($exporter->xlsx($detail, $at));
        $sheet = $book->getSheetByName('Inventory');
        $this->assertSame(['Location', 'Item', 'Quantity (units)', 'Basis', 'What was typed or calculated', 'Notes'], array_map(fn ($c) => $sheet->getCell($c.'1')->getValue(), ['A', 'B', 'C', 'D', 'E', 'F']));
        $byItem = [];
        foreach (range(2, $sheet->getHighestRow()) as $row) {
            $byItem[$sheet->getCell('B'.$row)->getValue()] = [$sheet->getCell('C'.$row)->getValue(), $sheet->getCell('C'.$row)->getDataType(), $sheet->getCell('D'.$row)->getValue(), $sheet->getCell('E'.$row)->getValue()];
        }
        $this->assertEquals([30, DataType::TYPE_NUMERIC, 'Counted', '30 each'], $byItem['Tape 1 inch']);
        $this->assertSame('Counted', $byItem['Stethoscope'][2]);
        $facts = $book->getSheetByName('About this export');
        $this->assertSame('Final', $facts->getCell('B3')->getValue());
        $this->assertSame('Physical count', $facts->getCell('B2')->getValue());

        $this->assertStringStartsWith('%PDF', $exporter->pdf($detail, $at));
        $this->assertSame('MBFD - Supply Room Count - 2026-10-23 v1.xlsx', $exporter->filename($detail, 'xlsx', $at));
    }

    // --------------------------------------------------------------- statistics

    public function test_the_statistics_file_holds_exactly_what_the_page_shows(): void
    {
        $member = $this->emsMember('Paramedic', 'Pat Medic');
        $this->submitRequest($member, [['form' => 'als-med-bag', 'item_key' => 'als.trauma.tape', 'quantity' => 4]]);
        $this->actingAs($this->manager);
        $report = app(EmsStatistics::class)->report(['date_from' => '2026-01-01', 'date_to' => '2036-01-01']);
        $view = StatisticsView::build($report);
        $at = CarbonImmutable::parse('2026-10-23 09:00:00', 'America/New_York');
        $filters = 'Dates (Eastern): Jan 1, 2026 to Jan 1, 2036. Unit: All units. Kind: All kinds of request.';
        $exporter = app(StatisticsExporter::class);

        $book = $this->read($exporter->xlsx($report, $filters, $at));
        $this->assertSame(['Totals', 'Top requested items', 'Monthly trend', 'Requests by unit', 'Detail and special events', 'About this export'], $book->getSheetNames());
        $totals = $book->getSheetByName('Totals');
        foreach ($view['metrics'] as $index => $metric) {
            $this->assertSame($metric['label'], $totals->getCell('A'.($index + 2))->getValue());
            $this->assertSame($metric['value'], (string) $totals->getCell('B'.($index + 2))->getValue());
            $this->assertSame($metric['definition'], $totals->getCell('C'.($index + 2))->getValue(), 'Each metric keeps its written definition.');
        }
        $top = $book->getSheetByName('Top requested items');
        $this->assertSame('Tape 1"', $top->getCell('A2')->getValue());
        $this->assertSame(DataType::TYPE_NUMERIC, $top->getCell('C2')->getDataType());
        $this->assertEquals(4, $top->getCell('C2')->getValue());

        $about = $book->getSheetByName('About this export');
        $this->assertSame('These figures count requests and issuances, not supplies used on calls.', $about->getCell('B2')->getValue());
        $this->assertSame($filters, $about->getCell('B3')->getValue());

        $this->assertStringStartsWith('%PDF', $exporter->pdf($report, $filters, $at));
        $this->assertSame('MBFD - Medical Data Statistics - 2026-10-23.pdf', $exporter->filename('pdf', $at));
        $this->assertSame(1, InventoryRequest::query()->count());
    }
}
