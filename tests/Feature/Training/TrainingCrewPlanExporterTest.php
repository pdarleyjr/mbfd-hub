<?php

declare(strict_types=1);

namespace Tests\Feature\Training;

use App\Services\Training\CrewPlanner\TrainingCrewPlanExporter;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Smalot\PdfParser\Parser;
use Tests\TestCase;

final class TrainingCrewPlanExporterTest extends TestCase
{
    public function test_both_outputs_match_the_independent_list_and_exclude_sensitive_fields_without_database_queries(): void
    {
        $service = app(TrainingCrewPlanExporter::class);
        $projection = $this->proposal();
        DB::listen(static function (): void {
            self::fail('A standalone export must not query or mutate domain records.');
        });
        $report = $service->report($projection);
        self::assertSame(3, $report['total']);
        self::assertSame(1, $report['eligible_total']);
        self::assertSame(1, $report['review_total']);
        self::assertSame(1, $report['unassigned_total']);
        $pdf = $service->pdf($projection);
        self::assertStringStartsWith('%PDF-', $pdf);
        $text = (new Parser)->parseContent($pdf)->getText();
        self::assertStringContainsString('Battalion Chief 300', $text);
        self::assertStringContainsString('Synthetic Alpha', $text);
        self::assertStringContainsString('Synthetic Bravo', $text);
        self::assertStringContainsString('Synthetic Charlie', $text);
        self::assertStringNotContainsString('CONFIDENTIAL_LEAVE_REASON', $text);
        self::assertStringNotContainsString('DO_NOT_EXPORT_NOTE', $text);

        $path = tempnam(sys_get_temp_dir(), 'crew-plan-test-');
        self::assertIsString($path);
        try {
            file_put_contents($path, $service->xlsx($projection));
            $book = IOFactory::load($path);
            try {
                self::assertSame(['Summary', 'Morning', 'Afternoon', 'Unassigned', 'Needs verification'], $book->getSheetNames());
                self::assertSame('3', $book->getSheetByName('Summary')->getCell('B10')->getValue());
                self::assertSame('Synthetic Alpha', $book->getSheetByName('Morning')->getCell('A5')->getValue());
                self::assertSame('Synthetic Bravo', $book->getSheetByName('Unassigned')->getCell('A5')->getValue());
                self::assertSame('Synthetic Charlie', $book->getSheetByName('Needs verification')->getCell('A5')->getValue());
                foreach ($book->getAllSheets() as $sheet) {
                    self::assertStringNotContainsString('CONFIDENTIAL_LEAVE_REASON', json_encode($sheet->toArray(), JSON_THROW_ON_ERROR));
                }
            } finally {
                $book->disconnectWorksheets();
            }
        } finally {
            unlink($path);
        }
    }

    public function test_formula_like_external_values_remain_literal_and_sheet_names_are_safe(): void
    {
        $proposal = $this->proposal();
        $proposal['course_name'] = '=HYPERLINK("https://example.test","course")';
        $proposal['slots'][0]['label'] = 'Summary';
        $proposal['slots'][1]['label'] = 'Unsafe []:*?/\\ slot';
        $proposal['rows'][10]['name'] = '=1+1';
        $proposal['rows'][10]['employee_id'] = '+1234';
        $proposal['rows'][10]['bid_position'] = '@SUM(1,2)';
        $path = tempnam(sys_get_temp_dir(), 'crew-formula-test-');
        self::assertIsString($path);
        try {
            file_put_contents($path, app(TrainingCrewPlanExporter::class)->xlsx($proposal));
            $book = IOFactory::load($path);
            try {
                self::assertSame('=1+1', $book->getSheet(1)->getCell('A5')->getValue());
                self::assertSame('+1234', $book->getSheet(1)->getCell('B5')->getValue());
                foreach ($book->getAllSheets() as $sheet) {
                    self::assertDoesNotMatchRegularExpression('/[\\\\\/\?\*\[\]:]/', $sheet->getTitle());
                    foreach ($sheet->getCoordinates() as $coordinate) {
                        self::assertNotSame(DataType::TYPE_FORMULA, $sheet->getCell($coordinate)->getDataType());
                    }
                }
            } finally {
                $book->disconnectWorksheets();
            }
        } finally {
            unlink($path);
        }
    }

    public function test_empty_proposal_has_no_fabricated_attendees_and_invalid_slots_fail(): void
    {
        $service = app(TrainingCrewPlanExporter::class);
        $proposal = $this->proposal();
        $proposal['rows'] = [];
        self::assertSame(0, $service->report($proposal)['total']);
        self::assertStringContainsString('No proposed attendees', (new Parser)->parseContent($service->pdf($proposal))->getText());
        $proposal['slots'][0]['end_at'] = $proposal['slots'][0]['start_at'];
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $service->report($proposal);
    }

    public function test_blank_times_are_rejected_instead_of_becoming_the_current_time(): void
    {
        $proposal = $this->proposal();
        $proposal['slots'][0]['start_at'] = '';
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(TrainingCrewPlanExporter::class)->report($proposal);
    }

    public function test_colliding_custom_sheet_labels_are_unique(): void
    {
        $proposal = $this->proposal();
        $proposal['slots'][0]['label'] = 'Summary 2';
        $proposal['slots'][1]['label'] = 'Summary';
        $path = tempnam(sys_get_temp_dir(), 'crew-collision-test-');
        try {
            file_put_contents($path, app(TrainingCrewPlanExporter::class)->xlsx($proposal));
            $book = IOFactory::load($path);
            self::assertSame(['Summary', 'Summary 2', 'Summary 3', 'Unassigned', 'Needs verification'], $book->getSheetNames());
            $book->disconnectWorksheets();
        } finally {
            unlink($path);
        }
    }

    private function proposal(): array
    {
        return [
            'course_name' => 'Synthetic new course', 'scope_label' => 'Entire course - never attended',
            'schedule_date' => '2026-10-21', 'shift' => 'B', 'printed_at' => null,
            'generated_at' => '2026-10-21 07:30 EDT', 'author' => 'Synthetic Training Administrator',
            'slots' => [
                ['id' => 'morning', 'label' => 'Morning', 'start_at' => '2026-10-21T08:30:00-04:00', 'end_at' => '2026-10-21T11:30:00-04:00'],
                ['id' => 'afternoon', 'label' => 'Afternoon', 'start_at' => '2026-10-21T13:00:00-04:00', 'end_at' => '2026-10-21T16:00:00-04:00'],
            ],
            'rows' => [
                10 => ['name' => 'Synthetic Alpha', 'employee_id' => 'SYN-001', 'rank' => 'Captain', 'bid_position' => 'Engine officer', 'station' => 'Station 1', 'apparatus' => 'Engine 1', 'slot_id' => 'morning', 'verification_required' => false, 'raw_code' => 'CONFIDENTIAL_LEAVE_REASON', 'note' => 'DO_NOT_EXPORT_NOTE'],
                20 => ['name' => 'Synthetic Bravo', 'employee_id' => 'SYN-002', 'rank' => 'Firefighter', 'slot_id' => null, 'verification_required' => false],
                30 => ['name' => 'Synthetic Charlie', 'employee_id' => 'SYN-003', 'rank' => 'Lieutenant', 'slot_id' => 'afternoon', 'verification_required' => true],
            ],
        ];
    }
}
