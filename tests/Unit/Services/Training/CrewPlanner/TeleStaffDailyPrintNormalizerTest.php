<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Training\CrewPlanner;

use App\Services\Training\CrewPlanner\TeleStaffDailyPdfParser;
use App\Services\Training\CrewPlanner\TeleStaffDutyResolver;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class TeleStaffDailyPrintNormalizerTest extends TestCase
{
    private function item(float $x, float $y, string $text, bool $raw = false): array
    {
        return [[0.61693545, 0, 0, 0.61693545, $raw ? $x : $x * 1.13 - 40, $y], $text];
    }

    private function row(float $y, string $id, string $code, string $rank = 'Firefighter'): array
    {
        return [$this->item(51, $y, $rank), $this->item(173, $y, 'SYNTHETIC, EXAMPLE'),
            $this->item(311, $y, $id), $this->item(351, $y, $code),
            $this->item(494.7, $y, '08:00'), $this->item(515.9, $y, '08:00'), $this->item(548, $y, '24')];
    }

    private function pages(string $secondCode = '2WXEON', int $headcount = 2): array
    {
        $summary = [$this->item(14, -6000, '{Headcounts}', true),
            $this->item(21, -6020, 'Rank', true), $this->item(91, -6020, (string) $headcount, true), $this->item(352, -6020, (string) $headcount, true),
            $this->item(21, -6040, 'Can Act As', true), $this->item(91, -6040, '2', true), $this->item(352, -6040, '2', true)];
        foreach (['Captain', 'Firefighter', 'Firefighter DE', 'Lieutenant'] as $index => $label) {
            $y = -6060 - 20 * $index;
            array_push($summary, $this->item(29, $y, $label, true), $this->item(91, $y, '1', true), $this->item(352, $y, '1', true));
        }

        return [[$this->item(48, 740, 'Thursday, October 22, 2026'), $this->item(48, 720, 'C Shift'),
            $this->item(48, 700, 'Station 9'), $this->item(48, 680, 'Engine 9'), ...$this->row(650, '90001', 'SP'),
            $this->item(351, 650, 'SP')], $this->row(-1400, '90002', $secondCode, 'Lieutenant'), $summary];
    }

    public function test_tagged_print_reconciles_rows_summary_and_page_sequence_without_inventing_a_print_time(): void
    {
        $snapshot = (new TeleStaffDailyPdfParser)->parsePositionedPages($this->pages(), '', [0, 1, 2]);
        self::assertSame('2026-10-22', $snapshot['schedule_date']);
        self::assertSame('C', $snapshot['shift']);
        self::assertNull($snapshot['printed_at']);
        self::assertFalse($snapshot['definitive_eligibility_blocked']);
        self::assertSame(['Report print time is not available'], $snapshot['warnings']);
        self::assertCount(2, $snapshot['parsed_assignments']);
        self::assertSame('SP', $snapshot['parsed_assignments'][0]['raw_code']);
        self::assertSame('Engine 9', $snapshot['parsed_assignments'][1]['reported_apparatus']);
        self::assertSame('UNKNOWN', $snapshot['parsed_assignments'][1]['code_class']);
        $staff = (new TeleStaffDutyResolver)->resolve($snapshot, [['id' => 'am', 'start_at' => '2026-10-22T09:00:00-04:00', 'end_at' => '2026-10-22T10:00:00-04:00']]);
        self::assertSame(['am'], $staff['90001']['eligible_slots']);
        self::assertSame([], $staff['90002']['eligible_slots']);
    }

    public function test_missing_middle_work_page_is_blocked_even_if_pdf_page_indices_are_rebuilt(): void
    {
        $pages = $this->pages();
        $snapshot = (new TeleStaffDailyPdfParser)->parsePositionedPages([$pages[0], $pages[2]], '', [0, 1]);
        self::assertTrue($snapshot['definitive_eligibility_blocked']);
    }

    public function test_missing_middle_absence_page_is_blocked_even_when_positive_headcount_still_matches(): void
    {
        $pages = $this->pages('VAC', 1);
        $snapshot = (new TeleStaffDailyPdfParser)->parsePositionedPages([$pages[0], $pages[2]], '', [0, 2]);
        self::assertTrue($snapshot['definitive_eligibility_blocked']);
        $staff = (new TeleStaffDutyResolver)->resolve($snapshot, [['id' => 'am', 'start_at' => '2026-10-22T09:00:00-04:00', 'end_at' => '2026-10-22T10:00:00-04:00']]);
        self::assertSame([], $staff['90001']['eligible_slots']);
    }

    public function test_missing_terminal_summary_or_page_sequence_and_reordered_pages_require_verification(): void
    {
        $pages = $this->pages();
        foreach ([[$pages[0], $pages[1]], $pages, [$pages[0], $pages[2], $pages[1]]] as $index => $variant) {
            $snapshot = (new TeleStaffDailyPdfParser)->parsePositionedPages($variant, '', $index === 1 ? [] : range(0, count($variant) - 1));
            self::assertTrue($snapshot['definitive_eligibility_blocked']);
        }
    }

    public function test_missing_identifier_warns_even_if_headcount_and_terminal_summary_are_present(): void
    {
        $pages = $this->pages();
        $pages[0] = [...$pages[0], ...$this->row(620, 'missing', 'SP')];
        $snapshot = (new TeleStaffDailyPdfParser)->parsePositionedPages($pages, '', [0, 1, 2]);
        self::assertTrue($snapshot['definitive_eligibility_blocked']);
        self::assertContains('A staff row has no reliable employee identifier', $snapshot['warnings']);
    }

    public function test_unaccounted_identifier_and_inconsistent_columns_are_not_silently_dropped(): void
    {
        foreach (['unaccounted', 'moved_id', 'shear', 'different_vertical_scale'] as $change) {
            $pages = $this->pages();
            if ($change === 'unaccounted') {
                $pages[0][] = $this->item(311, 650, '90003');
            } elseif ($change === 'moved_id') {
                $pages[1][2][0][4] += 20;
            } elseif ($change === 'shear') {
                $pages[1][2][0][1] = 0.2;
            } else {
                $pages[1][2][0][3] = 0.7;
            }
            try {
                (new TeleStaffDailyPdfParser)->parsePositionedPages($pages, '', [0, 1, 2]);
                self::fail('Corrupt print accepted: '.$change);
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function test_off_roster_captain_unit_and_float_pool_reset_only_their_actual_context(): void
    {
        $pages = $this->pages();
        $pages[0] = [...$pages[0], $this->item(48, 630, '{off roster}'), ...$this->row(610, '90003', 'SP'),
            $this->item(48, 590, 'Captain 9'), ...$this->row(570, '90004', 'SP'),
            $this->item(48, 550, 'Rescue Float Pool'), ...$this->row(530, '90005', 'SP')];
        $snapshot = (new TeleStaffDailyPdfParser)->parsePositionedPages($pages, '', [0, 1, 2]);
        $rows = array_column($snapshot['parsed_assignments'], null, 'raw_employee_id');
        self::assertSame('Station 9', $rows['90003']['reported_station']);
        self::assertNull($rows['90003']['reported_apparatus']);
        self::assertSame('Captain 9', $rows['90004']['reported_apparatus']);
        self::assertNull($rows['90005']['reported_station']);
        self::assertSame('Rescue Float Pool', $rows['90005']['reported_apparatus']);
    }

    public function test_actual_tagged_pdf_allows_bounded_accessibility_objects_but_not_extra_nonstructure_objects(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'synthetic-tagged-daily-');
        try {
            file_put_contents($path, $this->pdf());
            $snapshot = (new TeleStaffDailyPdfParser)->parse($path);
            self::assertCount(2, $snapshot['parsed_assignments']);
            self::assertFalse($snapshot['definitive_eligibility_blocked']);
            file_put_contents($path, '%PDF-1.4 '.str_repeat('1 0 obj << /Type /Page >> endobj ', 801));
            $this->expectException(InvalidArgumentException::class);
            (new TeleStaffDailyPdfParser)->parse($path);
        } finally {
            unlink($path);
        }
    }

    private function pdf(): string
    {
        $pages = $this->pages();
        $objects = ['<< /Type /Catalog /Pages 2 0 R >>', '<< /Type /Pages /Kids [4 0 R 6 0 R 8 0 R] /Count 3 >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>'];
        foreach ($pages as $index => $items) {
            $content = '';
            foreach ($items as [$matrix, $text]) {
                $text = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
                $content .= sprintf("q %.8F 0 0 %.8F 0 0 cm BT /F1 18 Tf 1 0 0 1 %.8F %.8F Tm (%s) Tj ET Q\n", $matrix[0], $matrix[3], $matrix[4] / $matrix[0], $matrix[5] / $matrix[3], $text);
            }
            $objects[] = '<< /Type /Page /Parent 2 0 R /StructParents '.$index.' /MediaBox [0 0 612 792] /Resources << /Font << /F1 3 0 R >> >> /Contents '.(5 + $index * 2).' 0 R >>';
            $objects[] = '<< /Length '.strlen($content).">>\nstream\n".$content.'endstream';
        }
        for ($index = 0; $index < 820; $index++) {
            $objects[] = '<< /Type /StructElem /S /P /Pg 4 0 R >>';
        }
        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1)." 0 obj\n".$object."\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= 'xref'."\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf."trailer\n<< /Size ".(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF";
    }
}
