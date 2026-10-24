<?php

declare(strict_types=1);

namespace App\Services\Ems\Exports;

use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonInterface;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;

/**
 * Writes a ReportSpec as an Excel workbook or a landscape PDF. The workbook uses explicit cell
 * types only: text is always a string cell (so "002-12" stays text and nothing is ever parsed as a
 * formula), numbers are numeric cells. Text that starts with a formula character or a control
 * character gets a leading apostrophe so a spreadsheet opened elsewhere cannot run it.
 */
final class ReportWriter
{
    public const TIMEZONE = 'America/New_York';

    private const LOGO_PATH = 'images/mbfd_logo-256.png';

    public function xlsx(ReportSpec $spec): string
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->removeSheetByIndex(0);
        $used = [];

        foreach ($spec->sections as $section) {
            $sheet = $spreadsheet->createSheet();
            $sheet->setTitle($this->sheetTitle($section->heading, $used));
            foreach ($section->headers as $index => $header) {
                $sheet->setCellValueExplicit([$index + 1, 1], $header, DataType::TYPE_STRING);
            }
            foreach ($section->rows as $rowIndex => $row) {
                foreach (array_values($row) as $index => $value) {
                    $this->writeCell($sheet, [$index + 1, $rowIndex + 2], $value, in_array($index, $section->numericColumns, true));
                }
            }
            $columns = max(1, count($section->headers));
            $lastColumn = $sheet->getHighestColumn();
            $sheet->getStyle('A1:'.$lastColumn.'1')->getFont()->setBold(true);
            $sheet->getStyle('A1:'.$lastColumn.'1')->getAlignment()->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
            $sheet->freezePane('A2');
            if ($section->rows !== []) {
                $sheet->setAutoFilter('A1:'.$lastColumn.(count($section->rows) + 1));
            }
            for ($index = 0; $index < $columns; $index++) {
                $sheet->getColumnDimensionByColumn($index + 1)->setWidth((float) ($section->widths[$index] ?? 18));
            }
        }

        $about = $spreadsheet->createSheet();
        $about->setTitle($this->sheetTitle('About this export', $used));
        $about->setCellValueExplicit([1, 1], 'Title', DataType::TYPE_STRING);
        $about->setCellValueExplicit([2, 1], self::neutralize($spec->title), DataType::TYPE_STRING);
        foreach ($spec->facts as $index => [$label, $value]) {
            $about->setCellValueExplicit([1, $index + 2], self::neutralize($label), DataType::TYPE_STRING);
            $about->setCellValueExplicit([2, $index + 2], self::neutralize($value), DataType::TYPE_STRING);
        }
        $about->getStyle('A1:A'.(count($spec->facts) + 1))->getFont()->setBold(true);
        $about->getColumnDimension('A')->setWidth(24);
        $about->getColumnDimension('B')->setWidth(90);
        $spreadsheet->setActiveSheetIndex(0);

        return $this->save($spreadsheet);
    }

    public function pdf(ReportSpec $spec): string
    {
        $pdf = Pdf::loadView('pdf.ems-report', [
            'logo' => $this->logo(),
            'title' => $spec->title,
            'facts' => $spec->facts,
            'sections' => $spec->sections,
        ]);
        $pdf->setPaper('letter', 'landscape');
        $pdf->render();
        $dompdf = $pdf->getDomPDF();
        $font = $dompdf->getFontMetrics()->getFont('DejaVu Sans');
        $dompdf->getCanvas()->page_text(680, 590, 'Page {PAGE_NUM} of {PAGE_COUNT}', $font, 8, [0.35, 0.35, 0.35]);

        return $pdf->output();
    }

    public function filename(ReportSpec $spec, string $extension, CarbonInterface $at): string
    {
        return $spec->filenameStem.' - '.$at->copy()->timezone(self::TIMEZONE)->format('Y-m-d').'.'.$extension;
    }

    /**
     * Make a text value safe to open in a spreadsheet: formula starters (also the fullwidth forms)
     * and control characters at the start get a leading apostrophe.
     */
    public static function neutralize(string $value): string
    {
        $value = mb_scrub($value);
        if ($value === '') {
            return $value;
        }
        if (preg_match('/^[\x00-\x1F\x7F\x{0085}\x{2028}\x{2029}]/u', $value) === 1) {
            return "'".$value;
        }
        $trimmed = ltrim($value, " \u{00A0}\u{3000}");
        if (preg_match('/^[=+\-@\x{2212}\x{FF1D}\x{FF0B}\x{FF0D}\x{FF20}]/u', $trimmed) === 1) {
            return "'".$value;
        }

        return $value;
    }

    /** @param  array{0: int, 1: int}  $coordinate */
    private function writeCell(Worksheet $sheet, array $coordinate, mixed $value, bool $numeric): void
    {
        if ($numeric) {
            if ($value === null || $value === '') {
                return; // an empty number cell stays empty (never 0)
            }
            if (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))) {
                $sheet->setCellValueExplicit($coordinate, $value + 0, DataType::TYPE_NUMERIC);

                return;
            }
        }
        $sheet->setCellValueExplicit($coordinate, self::neutralize((string) ($value ?? '')), DataType::TYPE_STRING);
    }

    /** @param  array<string, true>  $used */
    private function sheetTitle(string $heading, array &$used): string
    {
        $base = trim((string) preg_replace('/[\\\\\/\?\*\[\]:]+/', ' ', $heading));
        $base = mb_substr($base === '' ? 'Sheet' : $base, 0, 28);
        $title = $base;
        for ($n = 2; isset($used[mb_strtolower($title)]); $n++) {
            $title = mb_substr($base, 0, 28 - strlen((string) $n) - 1).' '.$n;
        }
        $used[mb_strtolower($title)] = true;

        return $title;
    }

    private function logo(): ?string
    {
        $path = public_path(self::LOGO_PATH);

        return is_file($path) ? 'data:image/png;base64,'.base64_encode((string) file_get_contents($path)) : null;
    }

    private function save(Spreadsheet $spreadsheet): string
    {
        $path = tempnam(sys_get_temp_dir(), 'mbfd-ems-');
        if ($path === false) {
            throw new RuntimeException('Unable to create a temporary file for the export.');
        }

        try {
            (new Xlsx($spreadsheet))->save($path);

            return (string) file_get_contents($path);
        } finally {
            @unlink($path);
            $spreadsheet->disconnectWorksheets();
        }
    }
}
