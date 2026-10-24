<?php

declare(strict_types=1);

namespace App\Services\Ems\Exports;

use App\Filament\Support\EmsLabels;
use App\Models\Ems\InventoryRequest;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * The Rescue Inventory list as Excel or PDF. The caller decides which requests (the admin table's
 * current filters and search); each request needs the lines_count, qty_requested and qty_issued
 * attributes that InventoryRequestResource::withTotals() adds.
 */
final class RequestListExporter
{
    public const TITLE = 'MBFD Rescue Inventory Requests';

    /** A PDF page list longer than this is cut, and says so; the Excel file always has every row. */
    public const PDF_ROW_CAP = 1500;

    private const HEADERS = [
        'Number', 'Submitted (ET)', 'Member', 'Rank', 'Unit or Detail / Special Event', 'Event or assignment',
        'Items', 'Quantity requested', 'Quantity issued', 'Status', 'Close reason', 'Last activity (ET)',
    ];

    private const NUMERIC = [6, 7, 8];

    private const WIDTHS = [14, 20, 24, 14, 26, 28, 8, 12, 12, 14, 28, 20];

    public function __construct(private readonly ReportWriter $writer) {}

    /** @param  Collection<int, InventoryRequest>  $requests */
    public function spec(Collection $requests, string $filters, CarbonInterface $generatedAt, bool $forPdf = false): ReportSpec
    {
        $total = $requests->count();
        $shown = $forPdf ? $requests->take(self::PDF_ROW_CAP) : $requests;
        $note = $forPdf && $total > self::PDF_ROW_CAP
            ? 'Showing the first '.number_format(self::PDF_ROW_CAP).' of '.number_format($total).' requests. Use the Excel export for the full list.'
            : null;

        return new ReportSpec(
            self::TITLE,
            [
                ['Generated (ET)', EmsLabels::et($generatedAt, 'M j, Y g:i A T')],
                ['Filters and search', $filters],
                ['Requests', number_format($total)],
                ['Note', 'Quantities are requests and issuances against requests, not supplies used on calls.'],
            ],
            [new ReportSection('Requests', self::HEADERS, $shown->map(fn (InventoryRequest $request): array => $this->row($request))->values()->all(), self::NUMERIC, $note, self::WIDTHS)],
            'MBFD - Rescue Inventory Requests',
        );
    }

    /** @param  Collection<int, InventoryRequest>  $requests */
    public function xlsx(Collection $requests, string $filters, CarbonInterface $generatedAt): string
    {
        return $this->writer->xlsx($this->spec($requests, $filters, $generatedAt));
    }

    /** @param  Collection<int, InventoryRequest>  $requests */
    public function pdf(Collection $requests, string $filters, CarbonInterface $generatedAt): string
    {
        return $this->writer->pdf($this->spec($requests, $filters, $generatedAt, forPdf: true));
    }

    public function filename(string $extension, CarbonInterface $generatedAt): string
    {
        return $this->writer->filename($this->spec(collect(), '', $generatedAt), $extension, $generatedAt);
    }

    /** @return list<scalar|null> */
    private function row(InventoryRequest $request): array
    {
        return [
            (string) $request->number,
            EmsLabels::et($request->submitted_at),
            (string) $request->submitter_name,
            (string) $request->submitter_rank,
            (string) $request->target_label,
            (string) $request->event_assignment,
            (int) ($request->lines_count ?? 0),
            (int) ($request->qty_requested ?? 0),
            (int) ($request->qty_issued ?? 0),
            EmsLabels::status($request->status),
            (string) $request->close_reason,
            EmsLabels::et($request->last_activity_at ?? $request->submitted_at),
        ];
    }
}
