<?php

declare(strict_types=1);

namespace App\Services\Ems\Exports;

use App\Filament\Support\EmsLabels;
use App\Services\Ems\SupplyData\SupplyDataFilters;
use App\Services\Ems\SupplyData\SupplyDataReport;
use Carbon\CarbonInterface;

/**
 * Supply Data as an Excel workbook. Every number comes from the overview array SupplyDataReport returns, so the file
 * equals the JSON; nothing is recalculated here. The About sheet states the interval, filters, generated time, label,
 * definitions and provenance, and every estimated balance is marked "Estimated".
 */
final class SupplyDataExporter
{
    public const TITLE = 'MBFD Supply Data';

    public const PROVENANCE = 'Built from the supply room ledger (ems_stock_movements), the medical inventory requests and their lines, and the finished counts (ems_inventory_snapshots). Read only: producing this file changes nothing. Estimates are best guesses loaded on 2026-11-01 and are never treated as physical counts.';

    private const BASIS = ['counted' => 'Counted', 'calculated' => 'Calculated since count', 'estimate' => 'Estimated', 'none' => 'Not counted yet'];

    public function __construct(private readonly ReportWriter $writer) {}

    /** @param  array<string, mixed>  $overview  SupplyDataReport::overview() */
    public function spec(array $overview, SupplyDataFilters $filters, CarbonInterface $generatedAt): ReportSpec
    {
        $facts = [
            ['Read this first', (string) $overview['label']],
            ['Interval', $filters->describe()],
            ['Compared with', "{$overview['period']['previous']['from']} to {$overview['period']['previous']['to']}"],
            ['Filters', $this->filterText($overview['filters'])],
            ['Generated (ET)', EmsLabels::et($generatedAt, 'M j, Y g:i A T')],
            ['Estimates', 'Rows marked Estimated are best guesses, not physical counts. They are never used for variance or days of supply.'],
            ['Provenance', self::PROVENANCE],
        ];
        foreach ((array) $overview['scope_notes'] as $note) {
            $facts[] = ['Scope', (string) $note];
        }
        foreach ((array) $overview['definitions'] as $name => $sentence) {
            $facts[] = ['Definition: '.str_replace('_', ' ', (string) $name), (string) $sentence];
        }
        foreach ((array) $overview['data_quality']['notes'] as $note) {
            $facts[] = ['Data note', (string) $note];
        }

        return new ReportSpec(self::TITLE, $facts, [
            $this->summary($overview),
            $this->supplies($overview),
            $this->units($overview),
            $this->stock($overview),
            $this->fulfillment($overview),
            $this->accuracy($overview),
        ], 'MBFD - Supply Data');
    }

    /** @param  array<string, mixed>  $overview */
    public function xlsx(array $overview, SupplyDataFilters $filters, CarbonInterface $generatedAt): string
    {
        return $this->writer->xlsx($this->spec($overview, $filters, $generatedAt));
    }

    public function filename(CarbonInterface $generatedAt): string
    {
        return $this->writer->filename(new ReportSpec('', [], [], 'MBFD - Supply Data'), 'xlsx', $generatedAt);
    }

    /** @param  array<string, mixed>  $o */
    private function summary(array $o): ReportSection
    {
        $h = $o['headline'];
        $a = $o['attention'];
        $rows = [
            ['Distribution events', $h['distribution_events'], 'Request-and-item pairs distributed from the supply room in the period.'],
            ['Previous period events', $h['previous_distribution_events'], 'The same count for the days just before.'],
            ['Change (%)', $h['change_pct'], 'Empty when the previous period had none.'],
            ['Items distributed', $h['items_distributed'], 'Different supply items with at least one event.'],
            ['Units supplied', $h['units_supplied'], 'Trucks or details that received supplies.'],
            ['Requests submitted', $h['requests_submitted'], 'Counted requests: not cancelled, sent back or replaced.'],
            ['Requests reconciled', $h['requests_reconciled'], 'Counted requests already taken off the shelf.'],
            ['Below minimum', $a['below_minimum'], 'Items under their order level (estimates included, marked on the Stock sheet).'],
            ['Zero stock', $a['zero_stock'], 'Items with nothing on hand.'],
            ['Estimated balances', $a['estimated'], 'Best guesses, not counts.'],
            ['Not counted recently', $a['not_counted_recently'], 'No physical count recently, or never.'],
            ['Needs reorder', $a['needs_reorder'], 'The advisor recommends an order. A recommendation is not an order.'],
        ];

        return new ReportSection('Summary', ['Measure', 'Value', 'What it means'], $rows, [1], (string) $o['label'], [30, 14, 90]);
    }

    /** @param  array<string, mixed>  $o */
    private function supplies(array $o): ReportSection
    {
        $rows = array_map(fn (array $r): array => [
            $r['name'], $r['category'] ?? '', $r['count_unit'], $r['distribution_events'], $r['distributed'], $r['units_supplied'],
            $r['previous_distribution_events'], $r['direction'], (string) $r['last_distributed_at'],
        ], $o['top_items']);

        return new ReportSection('Supplies', ['Item', 'Category', 'Unit', 'Events', 'Distributed', 'Units supplied', 'Previous events', 'Direction', 'Last distributed'], $rows, [3, 4, 5, 6], 'Distributed from the supply room, not patient use. Ranked by events.', [40, 16, 10, 10, 12, 14, 14, 12, 28]);
    }

    /** @param  array<string, mixed>  $o */
    private function units(array $o): ReportSection
    {
        $rows = array_map(fn (array $r): array => [$r['label'], $r['requests'], $r['distribution_events'], $r['items_distributed'], (string) $r['last_replenished_at']], $o['units']);

        return new ReportSection('Units', ['Unit', 'Requests', 'Events', 'Items', 'Last replenished'], $rows, [1, 2, 3], null, [34, 10, 10, 10, 28]);
    }

    /** @param  array<string, mixed>  $o */
    private function stock(array $o): ReportSection
    {
        $rows = array_map(fn (array $r): array => [
            $r['name'], (string) ($r['category'] ?? ''), (string) ($r['location'] ?? ''), $r['count_unit'], $r['on_hand'],
            self::BASIS[$r['basis']] ?? $r['basis'], (string) $r['last_counted_at'], $r['deductions_since_count'], $r['minimum'],
            $r['status'], $r['needs_reorder'] ? 'Yes' : 'No', $r['recommended_order'], $r['avg_daily_distributed'], $r['days_of_supply'],
        ], $o['stock']);

        return new ReportSection('Stock', ['Item', 'Category', 'Shelf', 'Unit', 'On hand', 'Basis', 'Last counted', 'Since count', 'Minimum', 'Status', 'Reorder', 'Order', 'Avg per day', 'Days of supply'], $rows, [4, 7, 8, 11, 12, 13], 'Estimated rows are best guesses, not counts, and have no days of supply.', [40, 14, 12, 8, 10, 22, 26, 11, 10, 14, 9, 8, 12, 14]);
    }

    /** @param  array<string, mixed>  $o */
    private function fulfillment(array $o): ReportSection
    {
        $f = $o['fulfillment'];
        $rows = [
            ['Submitted (counted)', $f['submitted']], ['Acknowledged', $f['acknowledged']], ['Sent back at least once', $f['rejected']],
            ['Corrected (revisions)', $f['corrected']], ['Reconciled', $f['reconciled']], ['Open', $f['open']],
            ['Completed under the older process', $f['completed_other_workflow']], ['All submissions, any status', $f['all_submissions']],
            ['Median hours to reconcile', $f['median_hours_to_reconcile']], ['Quantity requested (request units)', $f['qty_requested']],
            ['Quantity issued on requests (request units)', $f['qty_distributed']],
        ];

        return new ReportSection('Fulfillment', ['Measure', 'Value'], $rows, [1], 'Quantities add up different units of measure; use the per-item figures to decide.', [44, 14]);
    }

    /** @param  array<string, mixed>  $o */
    private function accuracy(array $o): ReportSection
    {
        $c = $o['count_accuracy'];
        $rows = array_map(fn (array $r): array => [$r['name'], (string) $r['counted_at'], $r['expected'], $r['counted'], $r['variance'], $r['variance_pct']], $c['items']);

        return new ReportSection('Count accuracy', ['Item', 'Counted at', 'Expected', 'Counted', 'Variance', 'Variance (%)'], $rows, [2, 3, 4, 5], $c['note'] ?? 'Counts compared with what the books expected at the count.', [40, 28, 10, 10, 10, 12]);
    }

    /** @param  array{unit: string|null, item: int|null, category: string|null}  $filters */
    private function filterText(array $filters): string
    {
        $parts = array_filter([
            $filters['unit'] !== null ? 'unit '.$filters['unit'] : null,
            $filters['item'] !== null ? 'item #'.$filters['item'] : null,
            $filters['category'] !== null ? 'category '.$filters['category'] : null,
        ]);

        return $parts === [] ? 'None (whole supply room, all units)' : implode('; ', $parts);
    }
}
