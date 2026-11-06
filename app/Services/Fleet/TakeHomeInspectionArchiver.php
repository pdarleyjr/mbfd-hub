<?php

declare(strict_types=1);

namespace App\Services\Fleet;

use App\Models\TakeHomeVehicleInspection;
use App\Models\User;
use App\Support\Fleet\TakeHomeInspectionAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Clears (archives) a handled Take Home Vehicle Inspection and restores it.
 *
 * A cleared inspection leaves the Fleet list's active view and the dashboards, stays
 * findable under Archived, and can no longer be corrected by the member. Only the
 * archive columns change (the model's update guard allows nothing else); each clear
 * and restore is appended to archive_history, the same pattern as
 * OperationalEvidenceArchiveService. Both are idempotent.
 */
final class TakeHomeInspectionArchiver
{
    public const MAX_REASON = 2000;

    public function archive(TakeHomeVehicleInspection $inspection, User $actor, ?string $reason = null): TakeHomeVehicleInspection
    {
        abort_unless(TakeHomeInspectionAccess::canClear($actor), 403);
        $reason = trim((string) $reason);
        Validator::make(['archive_reason' => $reason], ['archive_reason' => ['nullable', 'string', 'max:'.self::MAX_REASON]])->validate();

        return DB::transaction(function () use ($inspection, $actor, $reason): TakeHomeVehicleInspection {
            $locked = $this->lock($inspection);
            if ($locked->isArchived()) {
                return $locked;
            }

            $now = now();
            $locked->forceFill([
                'archived_at' => $now,
                'archived_by' => $actor->getKey(),
                'archive_reason' => $reason === '' ? null : $reason,
                'archive_history' => $this->withEvent($locked, $actor, 'cleared', [
                    'archived_at' => $now->toISOString(),
                    'archive_reason' => $reason === '' ? null : $reason,
                ]),
            ])->save();

            return $locked;
        }, 3);
    }

    public function restore(TakeHomeVehicleInspection $inspection, User $actor): TakeHomeVehicleInspection
    {
        abort_unless(TakeHomeInspectionAccess::canClear($actor), 403);

        return DB::transaction(function () use ($inspection, $actor): TakeHomeVehicleInspection {
            $locked = $this->lock($inspection);
            if (! $locked->isArchived()) {
                return $locked;
            }

            $locked->forceFill([
                'archive_history' => $this->withEvent($locked, $actor, 'restored', [
                    'archived_at' => $locked->archived_at?->toISOString(),
                    'archived_by' => $locked->archived_by,
                    'archive_reason' => $locked->archive_reason,
                ]),
                'archived_at' => null,
                'archived_by' => null,
                'archive_reason' => null,
            ])->save();

            return $locked;
        }, 3);
    }

    private function lock(TakeHomeVehicleInspection $inspection): TakeHomeVehicleInspection
    {
        /** @var TakeHomeVehicleInspection $locked */
        $locked = TakeHomeVehicleInspection::query()->lockForUpdate()->findOrFail($inspection->getKey());

        return $locked;
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return list<array<string, mixed>>
     */
    private function withEvent(TakeHomeVehicleInspection $inspection, User $actor, string $event, array $metadata): array
    {
        $history = array_values((array) ($inspection->archive_history ?? []));
        $history[] = [
            'event_type' => $event,
            'user_id' => $actor->getKey(),
            'actor_name' => (string) ($actor->display_name ?: $actor->name),
            'created_at' => now()->toISOString(),
            'metadata' => $metadata,
        ];

        return $history;
    }
}
