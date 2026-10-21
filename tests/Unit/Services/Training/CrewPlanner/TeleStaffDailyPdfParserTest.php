<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Training\CrewPlanner;

use App\Services\Training\CrewPlanner\TeleStaffDailyPdfParser;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class TeleStaffDailyPdfParserTest extends TestCase
{
    private function item(float $x, float $y, string $text, float $scale = 0.36040324): array
    {
        return [[$scale, 0, 0, $scale, $x, $y], $text];
    }

    private function page(string $code = 'SP', string $from = '08:00', string $to = '08:00', string $hours = '24'): array
    {
        return [
            $this->item(48, 300, 'Wednesday, October 21, 2026'),
            $this->item(48, 280, 'B Shift'),
            $this->item(48, 260, 'Station 9'),
            $this->item(48, 240, 'Engine 9'),
            $this->item(51, 220, 'Firefighter DE'),
            $this->item(173, 220, 'EXAMPLE JR., ALEX (DE/PM)'),
            $this->item(311, 220, '90001'),
            $this->item(351, 220, $code),
            $this->item(494.7, 220, $from),
            $this->item(515.9, 220, $to),
            $this->item(548, 220, $hours),
            $this->item(173, 200, '-,-'),
            $this->item(0, -394, '1 of 1', 0.5),
            $this->item(525, -394, '10/20/2026, 5:11 AM', 0.5),
        ];
    }

    public function test_geometry_preserves_report_date_separately_from_print_time_and_seat_context(): void
    {
        $snapshot = (new TeleStaffDailyPdfParser)->parsePositionedPages([$this->page()]);
        self::assertSame('2026-10-21', $snapshot['schedule_date']);
        self::assertSame('B', $snapshot['shift']);
        self::assertSame('2026-10-20T05:11:00-04:00', $snapshot['printed_at']);
        self::assertSame([], $snapshot['warnings']);
        self::assertCount(1, $snapshot['parsed_assignments']);
        self::assertCount(1, $snapshot['vacancies']);
        $row = $snapshot['parsed_assignments'][0];
        self::assertSame('90001', $row['raw_employee_id']);
        self::assertSame('EXAMPLE JR., ALEX', $row['raw_name']);
        self::assertSame('Station 9', $row['reported_station']);
        self::assertSame('Engine 9', $row['reported_apparatus']);
        self::assertSame('Firefighter DE', $row['seat_rank']);
        self::assertSame('2026-10-22T08:00:00-04:00', $row['end_at']);
        self::assertSame('VERIFIED_EFFECTIVE', $row['approval_state']);
    }

    public function test_overtime_intervals_include_real_day_and_night_windows(): void
    {
        $parser = new TeleStaffDailyPdfParser;
        $day = $parser->parsePositionedPages([$this->page('+OT', '08:00', '20:00', '12')]);
        $night = $parser->parsePositionedPages([$this->page('OT', '20:00', '08:00', '12')]);
        self::assertSame('2026-10-21T20:00:00-04:00', $day['parsed_assignments'][0]['end_at']);
        self::assertSame('2026-10-22T08:00:00-04:00', $night['parsed_assignments'][0]['end_at']);
    }

    public function test_pending_exchange_modifiers_unknowns_and_annotations_never_assert_approval(): void
    {
        foreach (['2WXON', '2WXEON', '.SP', '.2WXOFF', '^OOC', "\u{e638}SP"] as $code) {
            $snapshot = (new TeleStaffDailyPdfParser)->parsePositionedPages([$this->page($code)]);
            $row = $snapshot['parsed_assignments'][0];
            self::assertSame($code, $row['raw_code']);
            self::assertNotEmpty($row['review_reasons']);
        }
    }

    public function test_missing_pages_block_definitive_eligibility_and_inconsistent_hours_require_review(): void
    {
        $page = $this->page('SP', '08:00', '12:00', '24');
        $page[12] = $this->item(0, -394, '1 of 2', 0.5);
        $snapshot = (new TeleStaffDailyPdfParser)->parsePositionedPages([$page]);
        self::assertTrue($snapshot['definitive_eligibility_blocked']);
        self::assertNull($snapshot['parsed_assignments'][0]['end_at']);
    }

    public function test_secondary_shift_section_is_preserved_and_reviewed(): void
    {
        $page = $this->page();
        $page[] = $this->item(48, 230, 'A Shift');
        $snapshot = (new TeleStaffDailyPdfParser)->parsePositionedPages([$page]);
        self::assertSame('B', $snapshot['shift']);
        self::assertSame('A', $snapshot['parsed_assignments'][0]['observed_shift']);
        self::assertNotEmpty($snapshot['parsed_assignments'][0]['review_reasons']);
    }

    public function test_unrecognized_layout_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new TeleStaffDailyPdfParser)->parsePositionedPages([[$this->item(1, 1, 'unknown', 1)]]);
    }

    public function test_independently_positioned_modifier_glyph_is_preserved_despite_baseline_difference(): void
    {
        $page = $this->page('+OT', '08:00', '20:00', '12');
        $page[] = $this->item(342.1, 217.05, "\u{e638}");
        $snapshot = (new TeleStaffDailyPdfParser)->parsePositionedPages([$page]);
        self::assertStringContainsString("\u{e638}", $snapshot['parsed_assignments'][0]['raw_code']);
        self::assertNotEmpty($snapshot['parsed_assignments'][0]['review_reasons']);
    }

    public function test_missing_identifier_staff_row_warns_and_blocks_definitive_recommendations(): void
    {
        $page = $this->page();
        $page[] = $this->item(173, 180, 'SYNTHETIC, UNIDENTIFIED');
        $page[] = $this->item(351, 180, 'SP');
        $page[] = $this->item(494.7, 180, '08:00');
        $page[] = $this->item(515.9, 180, '20:00');
        $page[] = $this->item(548, 180, '12');
        $snapshot = (new TeleStaffDailyPdfParser)->parsePositionedPages([$page]);
        self::assertTrue($snapshot['definitive_eligibility_blocked']);
        self::assertContains('A staff row has no reliable employee identifier', $snapshot['warnings']);
        self::assertCount(1, $snapshot['parsed_assignments']);
    }

    public function test_bad_magic_encryption_encoded_actions_and_object_streams_are_rejected_without_raw_errors(): void
    {
        foreach (['not a pdf', '%PDF-1.7 /Encrypt', '%PDF-1.7 /J#61vaScript', '%PDF-1.7 /ObjStm'] as $bytes) {
            $path = tempnam(sys_get_temp_dir(), 'synthetic-crew-');
            try {
                file_put_contents($path, $bytes);
                try {
                    (new TeleStaffDailyPdfParser)->parse($path);
                    self::fail('Unsafe PDF accepted');
                } catch (InvalidArgumentException $exception) {
                    self::assertStringNotContainsString($bytes, $exception->getMessage());
                }
            } finally {
                unlink($path);
            }
        }
    }

    public function test_real_pdf_decode_uses_the_positioned_template_without_retaining_or_deleting_the_callers_file(): void
    {
        $content = '';
        foreach ($this->page() as [$matrix, $text]) {
            $scale = $matrix[0];
            $text = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
            $content .= sprintf("q %.8F 0 0 %.8F 0 0 cm BT /F1 18 Tf 1 0 0 1 %.8F %.8F Tm (%s) Tj ET Q\n", $scale, $scale, $matrix[4] / $scale, $matrix[5] / $scale, $text);
        }
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [4 0 R] /Count 1 >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 3 0 R >> >> /Contents 5 0 R >>',
            '<< /Length '.strlen($content).">>\nstream\n".$content.'endstream',
        ];
        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1)." 0 obj\n".$object."\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 6\n0000000000 65535 f \n";
        foreach (array_slice($offsets, 1) as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }
        $pdf .= "trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF";
        $path = tempnam(sys_get_temp_dir(), 'synthetic-crew-');
        try {
            file_put_contents($path, $pdf);
            $snapshot = (new TeleStaffDailyPdfParser)->parse($path);
            self::assertSame('2026-10-21', $snapshot['schedule_date']);
            self::assertSame(hash('sha256', $pdf), $snapshot['source_sha256']);
            self::assertCount(1, $snapshot['parsed_assignments']);
            self::assertSame($pdf, file_get_contents($path));
        } finally {
            unlink($path);
        }
    }
}
