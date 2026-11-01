<?php

declare(strict_types=1);

namespace App\Services\Ems\Exports;

use App\Filament\Support\EmsLabels;
use Carbon\CarbonInterface;

/**
 * A submitted Monday count (SupplyCountReport::report()) as Excel or PDF. The rows are the ones on
 * screen, so the file shows exactly what the report shows. Quantities are recommendations, and the
 * file says so; it never claims that anything was ordered.
 */
final class SupplyCountReportExporter
{
    public const TITLE = 'MBFD Submitted Medical Supply Room Inventory';

    private const HEADERS = ['Shelf', 'Item', 'Counted', 'Unit', 'Required', 'Order', 'Note'];

    public function __construct(private readonly ReportWriter $writer) {}

    /** @param  array<string, mixed>  $report  SupplyCountReport::report() */
    public function spec(array $report, CarbonInterface $generatedAt): ReportSpec
    {
        $rows = [];
        foreach ((array) $report['shelves'] as $shelf) {
            foreach ((array) $shelf['rows'] as $row) {
                $rows[] = [
                    $shelf['code'] ?? SupplyCountReport::NO_SHELF,
                    (string) $row['item'],
                    $row['counted_value'],
                    (string) $row['unit'],
                    $row['required'],
                    // A number when something is to be ordered, otherwise the words shown on screen.
                    $row['order_kind'] === SupplyCountReport::ORDER ? $row['order_value'] : (string) $row['order_text'],
                    (string) ($row['note'] ?? ''),
                ];
            }
        }

        $facts = [
            ['Read this first', SupplyCountReport::NOT_A_PURCHASE_ORDER],
            ['Counted by', (string) $report['counted_by']],
            ['Counted (ET)', (string) $report['counted_at']],
            ['Coverage', (string) $report['coverage']],
            ['Items to order', (string) ($report['to_order'] ?? 0)],
        ];
        if (! empty($report['only_to_order'])) {
            $facts[] = ['Showing', 'Only items to order'];
        }
        $facts[] = ['Generated (ET)', EmsLabels::et($generatedAt, 'M j, Y g:i A T')];

        return new ReportSpec(
            self::TITLE,
            $facts,
            [new ReportSection('Inventory', self::HEADERS, $rows, [2, 4, 5], SupplyCountReport::NOT_A_PURCHASE_ORDER, [10, 44, 10, 10, 10, 18, 26])],
            'MBFD - Submitted Medical Supply Room Inventory',
        );
    }

    /** @param  array<string, mixed>  $report */
    public function xlsx(array $report, CarbonInterface $generatedAt): string
    {
        return $this->writer->xlsx($this->spec($report, $generatedAt));
    }

    /** @param  array<string, mixed>  $report */
    public function pdf(array $report, CarbonInterface $generatedAt): string
    {
        return $this->writer->pdf($this->spec($report, $generatedAt));
    }

    /** Named for the day of the count, so two downloads of one count carry the same name. */
    public function filename(array $report, string $extension): string
    {
        return 'MBFD - Submitted Medical Supply Room Inventory - '.($report['counted_date'] ?: 'undated').'.'.$extension;
    }
}
