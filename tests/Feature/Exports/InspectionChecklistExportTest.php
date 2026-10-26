<?php

declare(strict_types=1);

namespace Tests\Feature\Exports;

use App\Filament\Resources\InspectionResource\Pages\ViewInspection;
use App\Models\Apparatus;
use App\Models\ApparatusInspection;
use App\Services\Exports\TableExportService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use Smalot\PdfParser\Parser;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class InspectionChecklistExportTest extends ExportTestCase
{
    private function inspection(): ApparatusInspection
    {
        $apparatus = Apparatus::query()->create(['unit_id' => 'EXPORT1', 'designation' => 'E1', 'status' => 'In Service']);

        return ApparatusInspection::query()->create([
            'apparatus_id' => $apparatus->id, 'client_submission_id' => (string) Str::uuid(),
            'operator_name' => 'Synthetic Operator', 'rank' => 'Firefighter', 'shift' => 'A-Day', 'unit_number' => 'EXPORT1',
            'review_status' => 'pending_review', 'processing_status' => 'held_for_review',
            'completed_at' => CarbonImmutable::parse('2026-03-08T06:30:00Z'),
            'results' => [['name' => 'Cab', 'items' => [
                ['name' => 'Radio', 'status' => 'Missing', 'notes' => '=1+1'],
                ['name' => 'Helmet', 'status' => 'Present'],
            ]]],
            'pending_effects' => ['checklist_v2' => ['field_values' => [
                ['name' => 'Oxygen pressure', 'value' => 1500, 'input_type' => 'number'],
            ], 'scheduled_tasks' => []]],
        ]);
    }

    public function test_excel_exports_submitted_items_and_visible_evidence_as_safe_cells(): void
    {
        $inspection = $this->inspection();
        $original = $inspection->fresh()->getRawOriginal();
        $page = Livewire::test(ViewInspection::class, ['record' => $inspection->getKey()])
            ->callAction('exportData', data: ['format' => 'xlsx']);
        $book = $this->workbook($page);
        $sheet = $book->getSheet(0);
        $rows = $this->dataRows($book);
        self::assertSame(['Submitted checklist', 'Cab', 'Radio', 'Missing', null, "'=1+1"], $rows[1]);
        self::assertSame('Pass', $rows[2][3]);
        self::assertSame(['Submitted checklist evidence', null, 'Oxygen pressure', 'Number', '1500', null], $rows[3]);
        self::assertSame(DataType::TYPE_STRING, $sheet->getCell('F2')->getDataType());
        self::assertSame($original, $inspection->fresh()->getRawOriginal());
    }

    public function test_pdf_exports_the_same_checklist_with_eastern_submission_time(): void
    {
        $inspection = $this->inspection();
        $page = Livewire::test(ViewInspection::class, ['record' => $inspection->getKey()])
            ->callAction('exportData', data: ['format' => 'pdf']);
        $text = (new Parser)->parseContent($this->downloadedBytes($page))->getText();
        foreach (['Radio', 'Missing', 'Helmet', 'Pass', 'Oxygen pressure', '1500', 'Mar 8, 2026 1:30 AM EST'] as $label) {
            self::assertStringContainsString($label, $text);
        }
    }

    public function test_revoked_inspection_permissions_prevent_either_export_format(): void
    {
        $inspection = $this->inspection();
        $page = Livewire::test(ViewInspection::class, ['record' => $inspection->getKey()])->instance();
        $limited = $this->userWith('admin.access');
        $this->actingAs($limited);
        self::assertFalse($page->canExportData($limited));
        foreach (['xlsx', 'pdf'] as $format) {
            try {
                app(TableExportService::class)->downloadDataset($limited, ViewInspection::class, $format,
                    fn (): bool => $page->canExportData($limited),
                    static fn () => throw new \LogicException('Denied data must not be read.'));
                self::fail('An unauthorized inspection export was allowed.');
            } catch (HttpException $exception) {
                self::assertSame(403, $exception->getStatusCode());
            }
        }
    }
}
