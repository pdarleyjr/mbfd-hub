<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\StationInspection;
use App\Models\User;
use App\Services\Display\DisplaySnapshotService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class StationInspectionReviewService
{
    /** @var list<string> */
    private const REVIEW_STATUSES = ['reviewed', 'needs_follow_up'];

    public function review(int $inspectionId, User $reviewer, string $status, ?string $note = null): StationInspection
    {
        if (! in_array($status, self::REVIEW_STATUSES, true)) {
            throw new InvalidArgumentException('Unsupported station inspection review status.');
        }

        $note = filled($note) ? trim((string) $note) : null;
        if ($status === 'needs_follow_up' && $note === null) {
            throw new InvalidArgumentException('A follow-up note is required.');
        }

        return DB::transaction(function () use ($inspectionId, $reviewer, $status, $note): StationInspection {
            $inspection = StationInspection::query()->lockForUpdate()->findOrFail($inspectionId);
            if ($inspection->review_status !== 'pending_review') {
                return $inspection;
            }

            $inspection->update([
                'review_status' => $status,
                'reviewed_by' => $reviewer->getKey(),
                'reviewed_at' => now(),
                'review_note' => $note,
            ]);

            $stationId = (int) $inspection->station_id;
            DB::afterCommit(static function () use ($stationId): void {
                Cache::forget(DisplaySnapshotService::SNAPSHOT_CACHE_KEY);
                Cache::forget(DisplaySnapshotService::STATIONS_CACHE_KEY);
                Cache::forget("station.{$stationId}.detail");
                Cache::forget("station.{$stationId}.activity");
            });

            return $inspection;
        }, 3);
    }
}
