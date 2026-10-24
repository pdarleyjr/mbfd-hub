<?php

declare(strict_types=1);

namespace App\Services\Ems\Exports;

use App\Filament\Support\EmsLabels;
use Carbon\CarbonImmutable;

/**
 * Turns an EmsStatistics::report() into the metrics and tables shown on the Medical Data page and
 * written to its Excel and PDF files, so the file always holds exactly what is on screen. Nothing
 * here reads the database; the only arithmetic is requests per week per unit, from the report's
 * own weeks_in_range.
 */
final class StatisticsView
{
    public const HEADER_TEXT = 'These figures count requests and issuances, not supplies used on calls.';

    private const METRICS = [
        'requests_submitted' => 'Requests submitted',
        'requests_cancelled' => 'Cancelled by the member',
        'requests_counted' => 'Requests counted',
        'lines_requested' => 'Items asked for',
        'qty_requested' => 'Quantity requested',
        'yes_answers' => 'Yes answers',
        'qty_issued' => 'Quantity issued',
        'qty_outstanding' => 'Still outstanding',
        'requests_per_week' => 'Requests per week',
    ];

    /**
     * @param  array<string, mixed>  $report  EmsStatistics::report()
     * @return array{metrics: list<array{label: string, value: string, definition: string}>, sections: list<ReportSection>, empty: bool, weeks: float}
     */
    public static function build(array $report): array
    {
        $totals = (array) $report['totals'];
        $definitions = (array) $report['definitions'];
        $weeks = max(0.0001, (float) ($totals['weeks_in_range'] ?? 1));

        $metrics = [];
        foreach (self::METRICS as $key => $label) {
            $value = $totals[$key] ?? 0;
            $metrics[] = [
                'label' => $label,
                'value' => is_float($value) ? rtrim(rtrim(number_format($value, 2), '0'), '.') : number_format((int) $value),
                'definition' => (string) ($definitions[$key] ?? ''),
            ];
        }

        $top = collect((array) $report['top_requested_items'])->map(fn (array $row): array => [
            (string) $row['label'], EmsLabels::BAG[$row['bag_key'] ?? ''] ?? (string) ($row['bag_key'] ?? ''),
            (int) $row['qty_requested'], (int) $row['qty_issued'], (int) $row['requests'],
        ])->all();

        $monthly = collect((array) $report['monthly_trend'])->map(fn (array $row): array => [
            CarbonImmutable::createFromFormat('Y-m', (string) $row['month'])->format('M Y'),
            (int) $row['requests'], (int) $row['qty_requested'], (int) $row['qty_issued'],
        ])->all();

        $byUnit = collect((array) $report['by_unit'])->map(fn (array $row): array => [
            (string) ($row['label'] ?? 'Unit'), (int) $row['requests'], round((int) $row['requests'] / $weeks, 2),
            (int) $row['qty_requested'], (int) $row['qty_issued'],
        ])->all();

        $detail = (array) $report['detail_special_event'];
        $detailRows = [
            ['Detail / Special Event requests counted', (int) ($detail['requests'] ?? 0)],
            ['Of those, with no event or assignment typed', (int) ($detail['without_event_text'] ?? 0)],
        ];
        $events = collect((array) ($detail['events'] ?? []))->map(fn (array $row): array => [(string) $row['event_assignment'], (int) $row['requests']])->all();

        $sections = [
            new ReportSection('Top requested items', ['Item', 'Bag', 'Quantity requested', 'Quantity issued', 'Requests'], $top, [2, 3, 4], (string) ($definitions['top_requested_items'] ?? ''), [40, 22, 14, 14, 10]),
            new ReportSection('Monthly trend', ['Month', 'Requests', 'Quantity requested', 'Quantity issued'], $monthly, [1, 2, 3], (string) ($definitions['monthly_trend'] ?? ''), [14, 12, 16, 16]),
            new ReportSection('Requests by unit', ['Unit', 'Requests', 'Requests per week', 'Quantity requested', 'Quantity issued'], $byUnit, [1, 2, 3, 4],
                trim(($definitions['by_unit'] ?? '').' Requests per week is the unit\'s requests divided by the weeks in the date range.'), [32, 12, 16, 16, 16]),
            new ReportSection('Detail and special events', ['Measure', 'Count'], $detailRows, [1], (string) ($definitions['detail_special_event'] ?? ''), [48, 12]),
        ];
        if ($events !== []) {
            $sections[] = new ReportSection('Typed events', ['Event or assignment', 'Requests'], $events, [1], 'Typed text is grouped without regard to upper or lower case.', [48, 12]);
        }

        return [
            'metrics' => $metrics,
            'sections' => $sections,
            'empty' => (int) ($totals['requests_submitted'] ?? 0) === 0,
            'weeks' => round($weeks, 2),
        ];
    }
}
