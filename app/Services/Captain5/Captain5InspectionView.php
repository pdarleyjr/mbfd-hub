<?php

declare(strict_types=1);

namespace App\Services\Captain5;

use App\Models\Apparatus;
use App\Models\ApparatusInspection;

/**
 * What Captain 5 may see of one submitted Daily Checkout: the whole checklist of a Rescue,
 * only the medical items of an Engine/Ladder, nothing of any other unit. No photos, signature,
 * personnel numbers or meter readings (Fleet data) are included.
 */
final class Captain5InspectionView
{
    public function __construct(
        private readonly Captain5IssueScope $scope,
        private readonly Captain5IssuePresenter $presenter,
    ) {}

    /** @return array<string, mixed>|null null when the inspection is outside Captain 5 scope */
    public function present(ApparatusInspection $inspection): ?array
    {
        $kind = $this->scope->inspectionKind($inspection);
        if ($kind === null || $inspection->test_excluded_at !== null) { // test submissions are not operational records
            return null;
        }

        $compartments = [];
        foreach ((array) ($inspection->results ?? []) as $compartment) {
            if (! is_array($compartment)) {
                continue;
            }
            $items = [];
            foreach ((array) ($compartment['items'] ?? []) as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $itemId = isset($item['id']) ? (string) $item['id'] : null;
                if ($kind === Captain5IssueScope::KIND_MEDICAL && ! $this->scope->itemIsMedical($inspection, $itemId)) {
                    continue;
                }
                $items[] = [
                    'id' => $itemId,
                    'name' => (string) ($item['name'] ?? ''),
                    'status' => (string) ($item['status'] ?? ''),
                    'notes' => isset($item['notes']) && is_string($item['notes']) ? mb_substr($item['notes'], 0, 2000) : null,
                ];
            }
            if ($items !== []) {
                $compartments[] = [
                    'id' => isset($compartment['id']) ? (string) $compartment['id'] : null,
                    'name' => (string) ($compartment['name'] ?? $compartment['title'] ?? ''),
                    'items' => $items,
                ];
            }
        }

        $apparatus = Apparatus::query()->find($inspection->processed_apparatus_id ?? $inspection->apparatus_id);

        return [
            'id' => (int) $inspection->getKey(),
            'kind' => $kind,
            'unit' => $this->presenter->unit($apparatus instanceof Apparatus ? $apparatus : null),
            'submitted_by' => trim($inspection->rank.' '.$inspection->operator_name) ?: null,
            'shift' => $inspection->shift,
            'submitted_at' => $this->presenter->iso($inspection->completed_at ?? $inspection->created_at),
            'acceptance' => (string) ($inspection->processing_status ?? $inspection->review_status ?? ''),
            'scope_note' => $kind === Captain5IssueScope::KIND_MEDICAL ? 'Medical equipment only. Fleet manages the rest of this checklist.' : null,
            'compartments' => $compartments,
        ];
    }
}
