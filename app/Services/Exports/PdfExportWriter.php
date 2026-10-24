<?php

declare(strict_types=1);

namespace App\Services\Exports;

use Barryvdh\DomPDF\Facade\Pdf;

/** Letter-landscape PDF: repeated header row, wrapped cells, "Page x of y"; wide tables fall back to one block per record. */
final class PdfExportWriter
{
    /** More columns than this cannot be printed legibly side by side on letter landscape. */
    public const MAX_TABLE_COLUMNS = 9;

    private const WIDTH_SAMPLE_ROWS = 200;

    /** DomPDF slows badly on very long cells; the Excel export always has the full text. */
    public const MAX_CELL_CHARS = 1500;

    public function layoutFor(ExportDataset $dataset): string
    {
        return $dataset->columnCount() > self::MAX_TABLE_COLUMNS ? 'blocks' : 'table';
    }

    /** The HTML that DomPDF renders; exposed so layout rules can be tested without rasterizing. */
    public function html(ExportDataset $dataset): string
    {
        return view('pdf.table-export', $this->viewData($dataset))->render();
    }

    public function write(ExportDataset $dataset): string
    {
        $pdf = Pdf::loadView('pdf.table-export', $this->viewData($dataset))->setPaper('letter', 'landscape');
        $pdf->render();

        $dompdf = $pdf->getDomPDF();
        $canvas = $dompdf->getCanvas();
        $metrics = $dompdf->getFontMetrics();
        $font = $metrics->getFont('Helvetica', 'normal');
        $y = $canvas->get_height() - 24;
        $label = 'Page {PAGE_NUM} of {PAGE_COUNT}';
        $x = $canvas->get_width() - 36 - $metrics->getTextWidth('Page 000 of 000', $font, 8);
        $canvas->page_text($x, $y, $label, $font, 8, [0.35, 0.35, 0.35]);
        $canvas->page_text(36, $y, 'Miami Beach Fire Department - '.mb_convert_encoding($dataset->title, 'ISO-8859-1', 'UTF-8'), $font, 8, [0.35, 0.35, 0.35]);

        return $pdf->output();
    }

    /** @return array<string, mixed> */
    private function viewData(ExportDataset $dataset): array
    {
        $shortened = false;
        $rows = array_map(
            static function (array $row) use (&$shortened): array {
                return array_map(static function (ExportCell $cell) use (&$shortened): string {
                    $text = mb_scrub($cell->display(), 'UTF-8');
                    if (mb_strlen($text) > self::MAX_CELL_CHARS) {
                        $shortened = true;
                        $text = mb_substr($text, 0, self::MAX_CELL_CHARS).' ... [shortened]';
                    }

                    return $text;
                }, $row);
            },
            $dataset->rows,
        );

        return [
            'layout' => $this->layoutFor($dataset),
            'title' => $dataset->title,
            'generated' => $dataset->generatedAt->format('M j, Y g:i A T'),
            'generatedBy' => $dataset->generatedBy,
            'scopeLabel' => $dataset->scopeLabel,
            'filterSummary' => $dataset->filterSummary,
            'sortSummary' => $dataset->sortSummary,
            'rowCount' => $dataset->rowCount(),
            'shortened' => $shortened,
            'headers' => $dataset->headers,
            'rows' => $rows,
            'widths' => $this->widths($dataset->headers, $rows),
        ];
    }

    /**
     * Column widths in percent, weighted by header and typical (not worst-case) content length.
     *
     * @param  list<string>  $headers
     * @param  list<list<string>>  $rows
     * @return list<float>
     */
    private function widths(array $headers, array $rows): array
    {
        $weights = [];
        foreach ($headers as $index => $header) {
            $lengths = [];
            foreach (array_slice($rows, 0, self::WIDTH_SAMPLE_ROWS) as $row) {
                $lengths[] = mb_strlen($row[$index] ?? '');
            }
            sort($lengths);
            $typical = $lengths === [] ? 0 : $lengths[(int) floor((count($lengths) - 1) * 0.9)];
            $weights[] = (float) min(40, max(7, $typical, (int) ceil(mb_strlen($header) * 0.8)));
        }

        $sum = array_sum($weights) ?: 1.0;

        return array_map(static fn (float $weight): float => round($weight / $sum * 100, 2), $weights);
    }
}
