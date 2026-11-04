<?php

declare(strict_types=1);

namespace App\Console\Commands\Defects;

use App\Models\ApparatusDefect;
use App\Services\Captain5\Captain5IssueScope;
use App\Services\Defects\DefectOwnership;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Release reconciliation for one-queue ownership (docs/unified-activity-and-ownership.md section 1). The dry run
 * compares the old routing (every Rescue issue to Captain 5, cleared ones out) with the per-equipment owner for every
 * open issue and lists what would move; nothing is written and nobody is told. The real run writes owners
 * idempotently: first projections are quiet, a cleared Captain 5 issue keeps the person's choice when it is medical.
 */
final class ProjectDefectOwnership extends Command
{
    protected $signature = 'defects:project-ownership
        {--dry-run : Report old versus new owner without writing anything}
        {--pending : Only issues with no owner yet or an unadopted clear (scheduled; quiet when there are none)}';

    protected $description = 'Give every open equipment issue exactly one owner (Captain 5 or Logistics) from its equipment';

    public function handle(DefectOwnership $ownership, Captain5IssueScope $scope): int
    {
        if ($this->option('pending') && ! $this->option('dry-run')) {
            return $this->projectPending($ownership);
        }
        $dryRun = (bool) $this->option('dry-run');
        $counts = ['open' => 0, 'rescue_to_logistics' => 0, 'medical_off_rescue' => 0, 'cleared_open' => 0, 'unclassified' => 0, 'no_item_key' => 0, 'already_projected' => 0, 'written' => 0];
        $rows = [];

        ApparatusDefect::query()->where('resolved', false)->orderBy('id')->chunkById(200, function ($chunk) use ($ownership, $scope, $dryRun, &$counts, &$rows): void {
            foreach ($chunk as $defect) {
                $counts['open']++;
                $result = $scope->classify($defect);
                $decision = $ownership->decide($defect);
                $cleared = $defect->captain5_cleared_at !== null;
                $old = $this->oldOwner($result->checklistType, $result->inScope, $cleared);
                $new = $cleared && $decision->owner === ApparatusDefect::OWNER_CAPTAIN5 ? ApparatusDefect::OWNER_LOGISTICS : $decision->owner;

                $counts['rescue_to_logistics'] += (int) ($old === ApparatusDefect::OWNER_CAPTAIN5 && $new === ApparatusDefect::OWNER_LOGISTICS && $result->checklistType === 'rescue');
                $counts['medical_off_rescue'] += (int) ($decision->owner === ApparatusDefect::OWNER_CAPTAIN5 && $result->checklistType !== 'rescue');
                $counts['cleared_open'] += (int) $cleared;
                $counts['unclassified'] += (int) ($decision->basis === DefectOwnership::BASIS_UNCLASSIFIED);
                $counts['no_item_key'] += (int) ($defect->item_key === null || $defect->item_key === '');
                $counts['already_projected'] += (int) ($defect->responsible_owner !== null);
                if ($old !== $new) {
                    $rows[] = [$defect->getKey(), $defect->apparatus_id, $result->checklistType, mb_substr((string) $defect->item, 0, 40), $old, $new, $cleared ? 'cleared' : $decision->basis];
                }
                if ($dryRun) {
                    continue;
                }
                $written = DB::transaction(function () use ($ownership, $defect): bool {
                    $locked = ApparatusDefect::query()->lockForUpdate()->findOrFail($defect->getKey());
                    if ($locked->responsible_owner === null && $locked->captain5_cleared_at !== null) {
                        $ownership->adoptLegacyClear($locked);

                        return true;
                    }

                    return $ownership->project($locked, 'release_backfill');
                });
                $counts['written'] += (int) $written;
            }
        });

        $this->info(($dryRun ? 'DRY RUN, nothing written. ' : '').'Open issues: '.$counts['open']);
        $this->table(['Measure', 'Count'], collect($counts)->map(fn (int $value, string $key): array => [$key, $value])->values()->all());
        if ($rows !== []) {
            $this->info('Issues whose queue changes (old routing -> per-equipment owner):');
            $this->table(['Issue', 'Apparatus', 'Checklist', 'Item', 'Old', 'New', 'Basis'], $rows);
        }

        return self::SUCCESS;
    }

    /**
     * Rows the previous image wrote (a rollback window), a projection that failed, or a clear made by the previous image:
     * owned now, so no issue waits in both queues or in neither. Idempotent and silent when nothing is pending.
     */
    private function projectPending(DefectOwnership $ownership): int
    {
        $written = 0;
        DefectOwnership::pendingQuery()->orderBy('id')->limit(2000)->pluck('id')->each(function ($id) use ($ownership, &$written): void {
            $written += (int) DB::transaction(fn (): bool => $ownership->project(ApparatusDefect::query()->lockForUpdate()->findOrFail($id), 'pending'));
        });
        if ($written > 0) {
            $this->info("Owners written: {$written}");
        }

        return self::SUCCESS;
    }

    /** The pre-release routing: a Rescue's every issue, an Engine/Ladder's medical ones; cleared ones left Captain 5. */
    private function oldOwner(string $checklistType, bool $medical, bool $cleared): string
    {
        $wasCaptain5 = $checklistType === 'rescue' || ($medical && in_array($checklistType, ['engine', 'engine2', 'ladder1', 'ladder3'], true));

        return $wasCaptain5 && ! $cleared ? ApparatusDefect::OWNER_CAPTAIN5 : ApparatusDefect::OWNER_LOGISTICS;
    }
}
