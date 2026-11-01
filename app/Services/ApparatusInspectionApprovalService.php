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
use App\Services\Inspections\InspectionNotificationDispatcher;
use App\Services\Inspections\InspectionReviewService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class ApparatusInspectionApprovalService
{
    private const POSTGRES_LOCK_TIMEOUT_ATTEMPTS = 4;

    private const POSTGRES_LOCK_TIMEOUT_BACKOFF_MICROSECONDS = 125_000;

    /**
     * Approving evidence never changes the unit's operational status by itself.
     * Taking the unit Out of Service is an explicit reviewer decision passed in
     * $markOutOfService; reported critical defects only inform that decision.
     */
    public function approve(int $inspectionId, User $reviewer, bool $markOutOfService = false, ?string $note = null): ApparatusInspection
    {
        if ($this->isProcessedInspection($inspectionId)) {
            return app(InspectionReviewService::class)->approve($inspectionId, $reviewer, $note, $markOutOfService);
        }

        return $this->retryPostgresLockTimeout(function () use ($inspectionId, $reviewer, $markOutOfService, $note): ApparatusInspection {
            return DB::transaction(function () use ($inspectionId, $reviewer, $markOutOfService, $note): ApparatusInspection {
                $inspection = ApparatusInspection::query()
                    ->lockForUpdate()
                    ->findOrFail($inspectionId);

                $inspection->assertOperational();

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
                $criticalDefectsReported = $isLegacyPendingInspection
                    || (bool) ($pendingEffects['has_critical_defects'] ?? false);
                $markedOutOfService = false;

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

                    if ($markOutOfService && $apparatus->getAttribute('status') !== 'Out of Service') {
                        // The model's status observer records the ledger event.
                        $apparatus->update(['status' => 'Out of Service']);
                        $markedOutOfService = true;
                    }

                    $hasSnipeItAsset = filled($apparatus->snipeit_asset_id);
                }

                $this->recordReviewEvent($inspection, $reviewer, 'approved', $note, [
                    'out_of_service_requested' => $markOutOfService,
                    'marked_out_of_service' => $markedOutOfService,
                    'critical_defects_reported' => $criticalDefectsReported,
                ]);
                $inspection->update([
                    'review_status' => 'approved',
                    'pending_effects' => null,
                ]);
                app(InspectionNotificationDispatcher::class)->inspectionDecided($inspection, 'approved');

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
            }, 3);
        });
    }

    public function reject(int $inspectionId, User $reviewer, string $reviewNotes): ApparatusInspection
    {
        if ($this->isProcessedInspection($inspectionId)) {
            return app(InspectionReviewService::class)->reject($inspectionId, $reviewer, $reviewNotes);
        }

        return $this->retryPostgresLockTimeout(function () use ($inspectionId, $reviewer, $reviewNotes): ApparatusInspection {
            return DB::transaction(function () use ($inspectionId, $reviewer, $reviewNotes): ApparatusInspection {
                $inspection = ApparatusInspection::query()
                    ->lockForUpdate()
                    ->findOrFail($inspectionId);

                $inspection->assertOperational();

                if ($inspection->review_status !== 'pending_review') {
                    return $inspection;
                }

                // Keep pending_effects on rejected rows: before approval it is the
                // sole persisted location for prepared defect photo paths. The
                // terminal review status prevents these effects from being applied.
                $this->recordReviewEvent($inspection, $reviewer, 'rejected', $reviewNotes);
                $inspection->update(['review_status' => 'rejected']);
                app(InspectionNotificationDispatcher::class)->inspectionDecided($inspection, 'rejected');

                DB::afterCommit(static function (): void {
                    Cache::forget(DisplaySnapshotService::SNAPSHOT_CACHE_KEY);
                    Cache::forget(DisplaySnapshotService::STATIONS_CACHE_KEY);
                });

                return $inspection;
            }, 3);
        });
    }

    /**
     * Inspections that automatic processing held for review are decided by the
     * review service, which understands holds. Legacy pending rows keep this
     * service's original behavior.
     */
    private function isProcessedInspection(int $inspectionId): bool
    {
        return ApparatusInspection::query()->whereKey($inspectionId)->value('processing_status') === ApparatusInspectionProcessingService::STATUS_HELD;
    }

    /** @param callable(): ApparatusInspection $operation */
    private function retryPostgresLockTimeout(callable $operation): ApparatusInspection
    {
        for ($attempt = 1; $attempt <= self::POSTGRES_LOCK_TIMEOUT_ATTEMPTS; $attempt++) {
            try {
                return $operation();
            } catch (QueryException $exception) {
                if (! $this->isPostgresLockTimeout($exception)
                    || $attempt === self::POSTGRES_LOCK_TIMEOUT_ATTEMPTS) {
                    throw $exception;
                }

                usleep(self::POSTGRES_LOCK_TIMEOUT_BACKOFF_MICROSECONDS * $attempt);
            }
        }

        throw new \LogicException('The PostgreSQL lock retry loop exited unexpectedly.');
    }

    private function isPostgresLockTimeout(QueryException $exception): bool
    {
        return DB::connection()->getDriverName() === 'pgsql'
            && (string) $exception->getCode() === '55P03';
    }

    private function recordReviewEvent(
        ApparatusInspection $inspection,
        User $reviewer,
        string $status,
        ?string $reviewNotes = null,
        array $decision = [],
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
                ...$decision,
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
