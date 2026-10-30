<?php

declare(strict_types=1);

namespace App\Services\DailyRoster;

use App\Models\DailyRoster\RosterActiveVersion;
use App\Models\DailyRoster\RosterSnapshot;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Lets an administrator show an earlier version of a duty date and keep it there. Nothing is deleted
 * and the effective export time never moves back, so a stale email still cannot take over; while the
 * date is pinned, every new export for it waits in review.
 */
final class RosterRollbackService
{
    public function __construct(private readonly RosterStateRecorder $recorder) {}

    /**
     * @throws AuthorizationException
     * @throws RosterPipelineException
     */
    public function activate(User $actor, string $dutyDate, int $snapshotId, string $reason): void
    {
        $this->authorize($actor);
        $reason = $this->requireReason($reason);

        $snapshot = RosterSnapshot::query()->whereKey($snapshotId)->whereDate('duty_date', $dutyDate)->first();
        if ($snapshot === null) {
            throw new RosterPipelineException('rollback_snapshot_not_for_date');
        }

        DB::transaction(function () use ($actor, $dutyDate, $snapshot, $reason): void {
            $now = CarbonImmutable::now('UTC');
            RosterActiveVersion::query()->insertOrIgnore(['duty_date' => $dutyDate, 'created_at' => $now, 'updated_at' => $now]);
            $pointer = RosterActiveVersion::query()->whereDate('duty_date', $dutyDate)->lockForUpdate()->firstOrFail();
            $fromSnapshotId = $pointer->roster_snapshot_id;

            $pointer->forceFill([
                'roster_snapshot_id' => $snapshot->getKey(),
                'pinned_at' => $now,
                'pinned_by_user_id' => $actor->getKey(),
            ])->save();

            $this->recorder->appendEvent(null, RosterStateRecorder::ACTION_ROLLBACK_ACTIVATED, reason: $reason, context: [
                'from_snapshot_id' => $fromSnapshotId,
                'to_snapshot_id' => $snapshot->getKey(),
                'version' => $snapshot->version,
            ], actorId: (int) $actor->getKey(), snapshotId: (int) $snapshot->getKey(), dutyDate: $dutyDate);
        });
    }

    /**
     * Ends the pin. The next new export publishes normally; a held one is not applied automatically.
     *
     * @throws AuthorizationException
     * @throws RosterPipelineException
     */
    public function release(User $actor, string $dutyDate, string $reason): void
    {
        $this->authorize($actor);
        $reason = $this->requireReason($reason);

        DB::transaction(function () use ($actor, $dutyDate, $reason): void {
            $pointer = RosterActiveVersion::query()->whereDate('duty_date', $dutyDate)->lockForUpdate()->first();
            if ($pointer === null || $pointer->pinned_at === null) {
                return;
            }

            $pointer->forceFill(['pinned_at' => null, 'pinned_by_user_id' => null])->save();
            $this->recorder->appendEvent(null, RosterStateRecorder::ACTION_ROLLBACK_RELEASED, reason: $reason, actorId: (int) $actor->getKey(), snapshotId: $pointer->roster_snapshot_id === null ? null : (int) $pointer->roster_snapshot_id, dutyDate: $dutyDate);
        });
    }

    private function authorize(User $actor): void
    {
        if (! $actor->can('admin.system.manage')) {
            throw new AuthorizationException('roster_rollback_forbidden');
        }
    }

    private function requireReason(string $reason): string
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new RosterPipelineException('rollback_reason_required');
        }

        return $reason;
    }
}
