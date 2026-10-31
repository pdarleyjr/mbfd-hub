<?php

declare(strict_types=1);

namespace App\Services\DailyRoster;

use App\Models\DailyRoster\RosterActiveVersion;
use App\Models\DailyRoster\RosterAssignment;
use App\Models\DailyRoster\RosterEvent;
use App\Models\DailyRoster\RosterImport;
use App\Models\DailyRoster\RosterSection;
use App\Models\DailyRoster\RosterSnapshot;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Keeps the roster tables a steady size once exports arrive every few minutes. It removes two things:
 * finished exports that changed nothing, and versions of a roster that were replaced and are past
 * their rollback window. What was shown for each date, and the record that a roster changed, stay.
 */
final class RosterRetention
{
    /** Exports that took no effect of their own. A published one is the record of a change, so it stays. */
    private const NO_EFFECT_OUTCOMES = [
        RosterImport::OUTCOME_CONFIRMED, RosterImport::OUTCOME_DUPLICATE, RosterImport::OUTCOME_SUPERSEDED,
    ];

    /** @return array{unchanged_exports: int, replaced_snapshots: int} */
    public function run(bool $dryRun = false): array
    {
        // Snapshots first: an export that stored one cannot go until that snapshot has gone.
        $snapshots = $dryRun ? $this->replacedSnapshots()->count() : $this->pruneSnapshots();
        $exports = $dryRun ? $this->unchangedExports()->count() : $this->pruneExports();

        return ['unchanged_exports' => $exports, 'replaced_snapshots' => $snapshots];
    }

    /** @return Builder<RosterImport> */
    private function unchangedExports(): Builder
    {
        return RosterImport::query()
            ->where('state', RosterImport::STATE_CLEANED)
            ->whereIn('outcome', self::NO_EFFECT_OUTCOMES)
            ->where('state_changed_at', '<=', now()->subDays(max(1, (int) config('daily_roster.retention.unchanged_export_days', 30))))
            ->whereNotIn('id', RosterSnapshot::query()->select('roster_import_id'));
    }

    /** @return Builder<RosterSnapshot> */
    private function replacedSnapshots(): Builder
    {
        return RosterSnapshot::query()
            ->where('created_at', '<=', now()->subDays(max(1, (int) config('daily_roster.retention.replaced_snapshot_days', 30))))
            ->whereNotIn('id', RosterActiveVersion::query()->whereNotNull('roster_snapshot_id')->select('roster_snapshot_id'));
    }

    private function pruneExports(): int
    {
        $removed = 0;
        foreach ($this->batches() as $_) {
            $ids = $this->unchangedExports()->orderBy('id')->limit($this->batchSize())->pluck('id');
            if ($ids->isEmpty()) {
                break;
            }

            DB::transaction(static function () use ($ids): void {
                RosterEvent::query()->whereIn('roster_import_id', $ids)->delete();
                RosterImport::query()->whereIn('id', $ids)->delete();
            });
            $removed += $ids->count();
        }

        return $removed;
    }

    private function pruneSnapshots(): int
    {
        $removed = 0;
        foreach ($this->batches() as $_) {
            $ids = $this->replacedSnapshots()->orderBy('id')->limit($this->batchSize())->pluck('id');
            if ($ids->isEmpty()) {
                break;
            }

            foreach ($ids as $id) {
                $removed += $this->removeSnapshot((int) $id) ? 1 : 0;
            }
        }

        return $removed;
    }

    /** An administrator rolling back to it at this moment wins: the pointer's foreign key refuses the delete. */
    private function removeSnapshot(int $id): bool
    {
        try {
            DB::transaction(static function () use ($id): void {
                RosterAssignment::query()->where('roster_snapshot_id', $id)->delete();
                RosterSection::query()->where('roster_snapshot_id', $id)->delete();
                RosterImport::query()->where('roster_snapshot_id', $id)->update(['roster_snapshot_id' => null]);
                RosterSnapshot::query()->whereKey($id)->delete();
            });

            return true;
        } catch (QueryException) {
            return false;
        }
    }

    /** @return list<int> */
    private function batches(): array
    {
        return range(1, max(1, (int) config('daily_roster.retention.max_batches', 20)));
    }

    private function batchSize(): int
    {
        return max(1, (int) config('daily_roster.retention.batch', 500));
    }
}
