<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Concerns;

use App\Filament\Concerns\HasDatasetExportActions;
use App\Filament\Support\EmsLabels;
use App\Filament\Support\EmsPanelAccess;
use App\Models\User;
use App\Services\Exports\ExportDataset;

/** Read-only exports of the supply room's existing projections; ledger entries remain immutable. */
trait SupplyRoomDataExports
{
    use HasDatasetExportActions;

    protected function getHeaderActions(): array
    {
        return [$this->datasetExportAction()];
    }

    public function canExportData(User $actor): bool
    {
        return $this->normalTab($this->tab) !== 'stock' && EmsPanelAccess::canView($actor);
    }

    protected function exportDataset(User $actor): ExportDataset
    {
        $this->emsActor('view');
        $tab = $this->normalTab($this->tab);
        $data = $this->getViewData();
        [$headers, $rows] = match ($tab) {
            'counts' => $this->countExportRows($data),
            'reconcile' => [
                ['Item', 'Location', 'Previous count', 'Issued', 'Received', 'Adjustments', 'Calculated', 'Threshold', 'Warnings'],
                array_map(static fn (array $row): array => [$row['name'], $row['location_code'], $row['previous_quantity'],
                    $row['issued'], $row['received'], $row['adjusted'], $row['calculated_quantity'], $row['threshold_qty_base'],
                    implode('; ', array_map(EmsLabels::warning(...), $row['warnings']))], $data['previewRows']),
            ],
            'reorder' => $this->reorderExportRows($data),
            'movements' => [
                ['When (Eastern)', 'Item', 'Type', 'Units', 'By', 'Request', 'Reason'],
                array_map(static fn (array $row): array => [$row['at'], $row['item_name'], $row['type_text'],
                    $row['quantity_base'], $row['actor_name'], $row['request_number'], $row['reason']], $data['movements']),
            ],
            'snapshots' => [
                ['Kind', 'When (Eastern)', 'Status', 'Location', 'Started by', 'Finalized by', 'Items', 'Counted', 'Notes'],
                array_map(static fn (array $row): array => [$row['kind_text'], $row['when'], $row['status'], $row['scope_location_code'],
                    $row['started_by'], $row['finalized_name'], $row['line_count'], $row['counted_count'], $row['notes']], $data['snapshots']),
            ],
            default => throw new \LogicException('Use the stock table export for the Stock tab.'),
        };

        return ExportDataset::fromRows($actor, 'Medical Supply Room - '.self::TABS[$tab], $headers, $rows,
            $tab === 'movements' ? 'Currently displayed movement page' : 'Current saved '.$tab.' view',
            array_values(array_filter([
                $tab === 'movements' ? 'Page '.$this->movementsPage : null,
                $tab === 'movements' && $this->movementType !== '' ? 'Movement type: '.$this->movementType : null,
                $tab === 'reconcile' ? ($this->changesOnly ? 'Items with movements or warnings' : 'All reconciliation items') : null,
                $tab === 'reorder' ? 'Order status: '.$this->orderStatus : null,
            ])));
    }

    private function countExportRows(array $data): array
    {
        if ($data['detail'] !== null) {
            return [
                ['Item', 'Location', 'Saved cases', 'Saved boxes', 'Saved each', 'Saved units', 'Warnings'],
                array_map(static fn (array $row): array => [$row['name'], $row['location_code'],
                    $row['entry']['cases'] ?? null, $row['entry']['boxes'] ?? null, $row['entry']['each'] ?? null,
                    $row['quantity_base'], implode('; ', array_map(EmsLabels::warning(...), $row['warnings']))], $data['detail']['items']),
            ];
        }

        return [
            ['Draft', 'Location', 'Started (Eastern)', 'Entered items'],
            array_map(static fn (array $row): array => [$row['id'], $row['scope'], $row['started'], $row['entered']], $data['drafts']),
        ];
    }

    private function reorderExportRows(array $data): array
    {
        $rows = array_map(static fn (array $row): array => ['Reorder recommendation', $row['name'], $row['location_code'],
            $row['current_quantity'], $row['threshold_qty_base'], $row['suggested_packages'], $row['supplier_code'], $row['item_number'],
            'Reorder recommended', null, null, null, null, $row['suggested_basis']], $data['advice']['advisories']);
        foreach ($data['orders'] as $row) {
            $rows[] = ['Order', $row['item_name'], null, $row['quantity_base'], null, null, $row['supplier_code'], $row['item_number'],
                $row['status_text'], $row['packages'], $row['placed_text'], $row['received_text'], $row['placed_by_name'], $row['notes']];
        }

        return [
            ['Section', 'Item', 'Location', 'Units', 'Threshold', 'Suggested packages', 'Supplier (historical, unverified)', 'Item number',
                'Status', 'Packages ordered', 'Placed (Eastern)', 'Received (Eastern)', 'Placed by', 'Notes / basis'],
            $rows,
        ];
    }
}
