<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Models\Apparatus;
use App\Models\ApparatusInventoryChange;
use App\Services\DailyCheckoutChecklistResolver;

/**
 * Approved inventory changes that no longer apply to their unit's checklist (an "overlay conflict":
 * the template changed under them, docs/apparatus-inventory-corrections.md 2.3). The resolver skips
 * them so checkout keeps working; this class makes them visible to Logistics so each one can be
 * resolved (retired, or reversed with its correction) instead of silently staying "in effect".
 */
final class InventoryOverlayHealth
{
    /** @var array<string, string> overlay reason code => explanation for Logistics */
    public const REASONS = [
        'item_not_found' => 'The item is no longer on this unit\'s checklist',
        'destination_missing' => 'The destination compartment is no longer on this unit\'s checklist',
        'identity_mismatch' => 'The checklist now uses this item id for different equipment',
        'invalid_result' => 'Applying it would leave the checklist invalid (for example two items with the same name)',
        'item_exists' => 'The checklist already lists this item',
        'invalid_quantity' => 'The quantity is no longer valid for this item',
        'invalid_name' => 'The name is no longer valid for this item',
        'unknown_operation' => 'The change cannot be read',
    ];

    public function __construct(private readonly DailyCheckoutChecklistResolver $resolver) {}

    public static function reasonLabel(string $reason): string
    {
        return self::REASONS[$reason] ?? 'It no longer applies to this unit\'s checklist';
    }

    /**
     * Conflicts for the given active changes, resolving each affected apparatus once.
     *
     * @param  iterable<ApparatusInventoryChange>  $changes
     * @return array<int, string> change id => reason code, only for changes that do not apply
     */
    public function conflictsFor(iterable $changes): array
    {
        $apparatusIds = [];
        foreach ($changes as $change) {
            if ($change->status === ApparatusInventoryChange::STATUS_ACTIVE) {
                $apparatusIds[(int) $change->apparatus_id] = true;
            }
        }
        if ($apparatusIds === []) {
            return [];
        }

        return $this->conflictsOn(array_keys($apparatusIds));
    }

    /**
     * Every active change in the fleet that does not apply, with its apparatus and correction.
     *
     * @return list<array{change_id: int, reason: string, reason_label: string, apparatus_id: int, apparatus: string, correction_id: int}>
     */
    public function fleetConflicts(): array
    {
        $apparatusIds = ApparatusInventoryChange::query()->active()->distinct()->orderBy('apparatus_id')->pluck('apparatus_id')
            ->map(static fn ($id): int => (int) $id)->all();
        $conflicts = $this->conflictsOn($apparatusIds);
        if ($conflicts === []) {
            return [];
        }
        $changes = ApparatusInventoryChange::query()->whereKey(array_keys($conflicts))->with('apparatus:id,designation,name,unit_id')->orderBy('id')->get();

        return $changes->map(static fn (ApparatusInventoryChange $change): array => [
            'change_id' => (int) $change->getKey(),
            'reason' => $conflicts[(int) $change->getKey()],
            'reason_label' => self::reasonLabel($conflicts[(int) $change->getKey()]),
            'apparatus_id' => (int) $change->apparatus_id,
            'apparatus' => trim((string) ($change->apparatus?->designation ?: $change->apparatus?->name ?: $change->apparatus?->getAttribute('unit_id'))) ?: 'Apparatus #'.$change->apparatus_id,
            'correction_id' => (int) $change->apparatus_inventory_correction_id,
        ])->values()->all();
    }

    /**
     * @param  list<int>  $apparatusIds
     * @return array<int, string>
     */
    private function conflictsOn(array $apparatusIds): array
    {
        $conflicts = [];
        foreach (Apparatus::query()->whereKey($apparatusIds)->orderBy('id')->get() as $apparatus) {
            foreach ($this->resolver->resolve($apparatus)['overlay_conflicts'] as $conflict) {
                $conflicts[(int) $conflict['change_id']] = (string) $conflict['reason'];
            }
        }

        return $conflicts;
    }
}
