<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\AuditEquipmentAfterInspection;
use App\Jobs\PmAlertNotificationJob;
use App\Models\Apparatus;
use App\Models\ApparatusDefect;
use App\Models\ApparatusInspection;
use App\Models\ApparatusInspectionReviewEvent;
use App\Models\User;
use App\Services\Display\DisplaySnapshotService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class ApparatusInspectionApprovalService
{
    public function approve(int $inspectionId, User $reviewer): ApparatusInspection
    {
        return DB::transaction(function () use ($inspectionId, $reviewer): ApparatusInspection {
            $inspection = ApparatusInspection::query()
                ->lockForUpdate()
                ->findOrFail($inspectionId);

            if ($inspection->review_status !== 'pending_review') {
                return $inspection;
            }

            $apparatus = Apparatus::query()
                ->lockForUpdate()
                ->find($inspection->apparatus_id);
            $pendingEffects = $inspection->pending_effects;
            $isLegacyPendingInspection = $pendingEffects === null;
            $previousHealth = 'green';
            $shouldDispatchPmAlert = false;
            $hasSnipeItAsset = false;

            if ($apparatus !== null) {
                if (! $isLegacyPendingInspection) {
                    foreach ($pendingEffects['defects'] ?? [] as $defectData) {
                        ApparatusDefect::recordDefect(
                            $apparatus->id,
                            $defectData['compartment'],
                            $defectData['item'],
                            $defectData['status'],
                            $defectData['notes'] ?? null,
                            $defectData['photo_path'] ?? null,
                            $inspection->id,
                        );
                    }

                    [
                        'previous_health' => $previousHealth,
                        'should_dispatch_pm_alert' => $shouldDispatchPmAlert,
                    ] = $this->applyApprovedInspectionMeters($apparatus, $inspection);
                }

                $hasCriticalDefects = $isLegacyPendingInspection
                    || (bool) ($pendingEffects['has_critical_defects'] ?? false);
                if ($hasCriticalDefects && $apparatus->status !== 'Out of Service') {
                    $apparatus->update(['status' => 'Out of Service']);
                }

                $hasSnipeItAsset = filled($apparatus->snipeit_asset_id);
            }

            $this->recordReviewEvent($inspection, $reviewer, 'approved');
            $inspection->update([
                'review_status' => 'approved',
                'pending_effects' => null,
            ]);

            $apparatusId = (int) $inspection->apparatus_id;
            DB::afterCommit(function () use ($inspectionId, $apparatusId, $previousHealth, $shouldDispatchPmAlert, $hasSnipeItAsset): void {
                Cache::forget(DisplaySnapshotService::SNAPSHOT_CACHE_KEY);
                Cache::forget(DisplaySnapshotService::STATIONS_CACHE_KEY);

                if ($shouldDispatchPmAlert) {
                    PmAlertNotificationJob::dispatch($apparatusId, $previousHealth);
                }

                if ($hasSnipeItAsset) {
                    AuditEquipmentAfterInspection::dispatch($inspectionId, $apparatusId)
                        ->delay(now()->addSeconds(5));
                }
            });

            return $inspection;
        });
    }

    public function reject(int $inspectionId, User $reviewer, string $reviewNotes): ApparatusInspection
    {
        return DB::transaction(function () use ($inspectionId, $reviewer, $reviewNotes): ApparatusInspection {
            $inspection = ApparatusInspection::query()
                ->lockForUpdate()
                ->findOrFail($inspectionId);

            if ($inspection->review_status !== 'pending_review') {
                return $inspection;
            }

            // Keep pending_effects on rejected rows: before approval it is the
            // sole persisted location for prepared defect photo paths. The
            // terminal review status prevents these effects from being applied.
            $this->recordReviewEvent($inspection, $reviewer, 'rejected', $reviewNotes);
            $inspection->update(['review_status' => 'rejected']);

            DB::afterCommit(static function (): void {
                Cache::forget(DisplaySnapshotService::SNAPSHOT_CACHE_KEY);
                Cache::forget(DisplaySnapshotService::STATIONS_CACHE_KEY);
            });

            return $inspection;
        });
    }

    private function recordReviewEvent(
        ApparatusInspection $inspection,
        User $reviewer,
        string $status,
        ?string $reviewNotes = null,
    ): void {
        ApparatusInspectionReviewEvent::query()->create([
            'apparatus_inspection_id' => $inspection->getKey(),
            'previous_status' => $inspection->review_status,
            'status' => $status,
            'internal_note' => $reviewNotes,
            'changed_by_user_id' => $reviewer->getKey(),
            // Keep the original operational-evidence payload with the
            // append-only decision record even when approval clears it from
            // the inspection after downstream records are created.
            'metadata' => [
                'reviewer_name' => $reviewer->name,
                'submitted_effects' => $inspection->pending_effects,
                'reported_engine_hours' => $inspection->engine_hours,
                'reported_miles' => $inspection->miles,
            ],
        ]);
    }

    /**
     * @return array{previous_health: string, should_dispatch_pm_alert: bool}
     */
    private function applyApprovedInspectionMeters(Apparatus $apparatus, ApparatusInspection $inspection): array
    {
        if ($inspection->engine_hours !== null) {
            $newHours = (float) $inspection->engine_hours;
            if ($newHours > (float) ($apparatus->current_engine_hours ?? 0)) {
                $apparatus->current_engine_hours = $newHours;
            }
        }

        if ($inspection->miles !== null) {
            $newMiles = (int) $inspection->miles;
            if ($newMiles > (int) ($apparatus->current_miles ?? 0)) {
                $apparatus->current_miles = $newMiles;
            }
        }

        if (! $apparatus->isDirty('current_engine_hours') && ! $apparatus->isDirty('current_miles')) {
            return [
                'previous_health' => 'green',
                'should_dispatch_pm_alert' => false,
            ];
        }

        $previousHealth = $apparatus->getOriginal('current_engine_hours')
            ? (new Apparatus(array_merge($apparatus->getOriginal(), ['current_engine_hours' => $apparatus->getOriginal('current_engine_hours')])))->getPmHealthStatus()['status']
            : 'green';
        $apparatus->reported_at = now();
        $apparatus->save();

        return [
            'previous_health' => $previousHealth,
            'should_dispatch_pm_alert' => true,
        ];
    }
}
