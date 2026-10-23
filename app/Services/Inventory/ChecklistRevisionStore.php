<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Models\Apparatus;
use App\Models\ApparatusChecklistRevision;

/**
 * Effective checklists that members may still hold after an approved inventory change
 * (docs/apparatus-inventory-corrections.md section 2.6).
 */
final class ChecklistRevisionStore
{
    /**
     * Records a resolved effective checklist. `$retire` marks it as replaced now; an
     * existing row keeps its first retirement time.
     *
     * @param  array<string, mixed>  $resolution  DailyCheckoutChecklistResolver::resolve() output
     */
    public function remember(Apparatus $apparatus, array $resolution, ?int $changeSetId, bool $retire): void
    {
        if (($resolution['usable'] ?? false) !== true || ! is_string($resolution['checklist_version'] ?? null)) {
            return;
        }

        $revision = ApparatusChecklistRevision::query()->firstOrCreate(
            ['apparatus_id' => $apparatus->getKey(), 'checklist_version' => $resolution['checklist_version']],
            [
                'checklist_type' => (string) $resolution['checklist_type'],
                'checklist' => $resolution['checklist'],
                'apparatus_inventory_change_set_id' => $changeSetId,
            ],
        );

        if ($retire && $revision->retired_at === null) {
            $revision->forceFill(['retired_at' => now()])->save();
        } elseif (! $retire && $revision->retired_at !== null) {
            // The same effective list became current again (for example after a reversal).
            $revision->forceFill(['retired_at' => null])->save();
        }
    }

    /**
     * The checklist a member was given under `$version`, if it was replaced recently enough
     * to still accept work done on it.
     *
     * @return array<string, mixed>|null
     */
    public function recentlyRetired(int $apparatusId, string $version): ?array
    {
        $hours = max(0, (int) config('daily_checkout.inventory_revision_grace_hours', 168));
        $revision = ApparatusChecklistRevision::query()
            ->where('apparatus_id', $apparatusId)
            ->where('checklist_version', strtolower($version))
            ->whereNotNull('retired_at')
            ->where('retired_at', '>=', now()->subHours($hours))
            ->first();

        return $revision?->checklist;
    }
}
