<?php

declare(strict_types=1);

namespace App\Services\Ems\Exports;

use App\Filament\Support\EmsLabels;
use App\Models\Ems\InventorySnapshot;
use Carbon\CarbonInterface;

/**
 * One inventory snapshot (a physical count or a calculated reconciliation) as Excel or PDF.
 * Built from SnapshotPresenter::detail() so the file shows exactly what the screen shows.
 */
final class SnapshotExporter
{
    private const HEADERS = ['Location', 'Item', 'Quantity (units)', 'Basis', 'What was typed or calculated', 'Notes'];

    public function __construct(private readonly ReportWriter $writer) {}

    /** @param  array<string, mixed>  $snapshot  SnapshotPresenter::detail() */
    public function spec(array $snapshot, CarbonInterface $generatedAt): ReportSpec
    {
        $kind = (string) ($snapshot['kind'] ?? '');
        $rows = collect((array) ($snapshot['lines'] ?? []))->map(fn (array $line): array => [
            (string) ($line['location_code'] ?? ''),
            (string) ($line['item_name'] ?? ''),
            $line['quantity_base'] ?? null,
            EmsLabels::VALUE_SOURCE[$line['value_source'] ?? ''] ?? (string) ($line['value_source'] ?? ''),
            $this->entryText((array) ($line['entry'] ?? [])),
            collect((array) ($line['warnings'] ?? []))->map(fn ($code): string => EmsLabels::warning((string) $code))->implode('; '),
        ])->values()->all();

        $facts = [
            ['Snapshot type', EmsLabels::SNAPSHOT_KIND[$kind] ?? $kind],
            ['Status', ($snapshot['status'] ?? '') === InventorySnapshot::FINAL ? 'Final' : 'Draft'],
            ['Version', isset($snapshot['version']) ? (string) $snapshot['version'] : 'Not final yet'],
            ['Location', (string) ($snapshot['scope_location_code'] ?? 'All locations')],
            ['Finalized by', (string) ($snapshot['finalized_name'] ?? '')],
            ['Finalized (ET)', EmsLabels::et($snapshot['finalized_at'] ?? null)],
            ['Generated (ET)', EmsLabels::et($generatedAt, 'M j, Y g:i A T')],
            ['Lines', number_format(count($rows))],
        ];
        if ($kind === InventorySnapshot::KIND_RECONCILIATION) {
            $facts[] = ['Note', 'Calculated from the last counts and the stock movements recorded since. This is not a physical count.'];
        }
        if (! empty($snapshot['notes']) && $kind !== InventorySnapshot::KIND_RECONCILIATION) {
            $facts[] = ['Notes', (string) $snapshot['notes']];
        }

        $title = 'MBFD Medical Supply Room - '.(EmsLabels::SNAPSHOT_KIND[$kind] ?? 'Inventory snapshot');

        return new ReportSpec($title, $facts, [
            new ReportSection('Inventory', self::HEADERS, $rows, [2], null, [10, 40, 16, 14, 44, 30]),
        ], 'MBFD - Supply Room '.($kind === InventorySnapshot::KIND_RECONCILIATION ? 'Reconciliation' : 'Count'));
    }

    /** @param  array<string, mixed>  $snapshot */
    public function xlsx(array $snapshot, CarbonInterface $generatedAt): string
    {
        return $this->writer->xlsx($this->spec($snapshot, $generatedAt));
    }

    /** @param  array<string, mixed>  $snapshot */
    public function pdf(array $snapshot, CarbonInterface $generatedAt): string
    {
        return $this->writer->pdf($this->spec($snapshot, $generatedAt));
    }

    /** @param  array<string, mixed>  $snapshot */
    public function filename(array $snapshot, string $extension, CarbonInterface $generatedAt): string
    {
        $version = isset($snapshot['version']) ? ' v'.$snapshot['version'] : ' draft';

        return str_replace('.'.$extension, $version.'.'.$extension, $this->writer->filename($this->spec($snapshot, $generatedAt), $extension, $generatedAt));
    }

    /** @param  array<string, mixed>  $entry */
    private function entryText(array $entry): string
    {
        if (array_key_exists('previous_quantity', $entry)) {
            return sprintf(
                'Earlier %s, issued %d, received %d, adjusted %d',
                $entry['previous_quantity'] ?? 'none', (int) ($entry['issued'] ?? 0), (int) ($entry['received'] ?? 0), (int) ($entry['adjusted'] ?? 0),
            );
        }
        $parts = [];
        foreach (['cases' => 'cases', 'boxes' => 'boxes or packs', 'each' => 'each'] as $key => $label) {
            if (isset($entry[$key])) {
                $parts[] = $entry[$key].' '.$label;
            }
        }

        return implode(', ', $parts);
    }
}
