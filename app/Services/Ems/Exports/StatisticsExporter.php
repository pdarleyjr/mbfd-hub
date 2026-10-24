<?php

declare(strict_types=1);

namespace App\Services\Ems\Exports;

use App\Filament\Support\EmsLabels;
use Carbon\CarbonInterface;

/** The Medical Data / Statistics page as Excel or PDF, with the filters printed on it. */
final class StatisticsExporter
{
    public function __construct(private readonly ReportWriter $writer) {}

    /**
     * @param  array<string, mixed>  $report  EmsStatistics::report()
     * @param  string  $filters  human text of the filters on screen
     */
    public function spec(array $report, string $filters, CarbonInterface $generatedAt): ReportSpec
    {
        $view = StatisticsView::build($report);
        $metrics = array_map(fn (array $metric): array => [$metric['label'], $metric['value'], $metric['definition']], $view['metrics']);

        $sections = [
            new ReportSection('Totals', ['Measure', 'Value', 'What it means'], $metrics, [], StatisticsView::HEADER_TEXT, [30, 14, 100]),
            ...$view['sections'],
        ];

        return new ReportSpec(
            'MBFD Medical Data / Statistics',
            [
                ['Read this first', StatisticsView::HEADER_TEXT],
                ['Filters', $filters],
                ['Weeks in the date range', (string) $view['weeks']],
                ['Generated (ET)', EmsLabels::et($generatedAt, 'M j, Y g:i A T')],
            ],
            $sections,
            'MBFD - Medical Data Statistics',
        );
    }

    /** @param  array<string, mixed>  $report */
    public function xlsx(array $report, string $filters, CarbonInterface $generatedAt): string
    {
        return $this->writer->xlsx($this->spec($report, $filters, $generatedAt));
    }

    /** @param  array<string, mixed>  $report */
    public function pdf(array $report, string $filters, CarbonInterface $generatedAt): string
    {
        return $this->writer->pdf($this->spec($report, $filters, $generatedAt));
    }

    public function filename(string $extension, CarbonInterface $generatedAt): string
    {
        return 'MBFD - Medical Data Statistics - '.$generatedAt->copy()->timezone(ReportWriter::TIMEZONE)->format('Y-m-d').'.'.$extension;
    }
}
