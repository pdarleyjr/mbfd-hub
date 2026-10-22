<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\AuditEquipmentAfterInspection;
use App\Jobs\PmAlertNotificationJob;
use App\Models\Apparatus;
use App\Models\ApparatusInspection;
use App\Models\ApparatusInspectionException;
use App\Models\ApparatusInspectionReviewEvent;
use App\Services\Defects\DefectContinuityService;
use Illuminate\Support\Facades\DB;

final class ApparatusInspectionProcessingService
{
    /**
     * Called inside the submission transaction with the apparatus locked.
     * Legacy queued payloads retain their existing review contract.
     */
    public function process(ApparatusInspection $inspection, Apparatus $apparatus, ?string $baselineToken, array $checklist = []): void
    {
        if ($inspection->processing_status !== null) {
            return;
        }
        $evidence = $inspection->pending_effects ?? [];
        $baseline = app(InspectionMeterBaseline::class)->read($baselineToken, (int) $apparatus->id);
        $previousHealth = $apparatus->getPmHealthStatus()['status'];
        $meterChanged = false;
        $definitions = app(InspectionPaperFields::class)->equipmentDefinitions($checklist);

        foreach (['engine_hours' => 'current_engine_hours', 'miles' => 'current_miles'] as $field => $attribute) {
            $submitted = $inspection->getAttribute($field);
            if ($submitted === null) {
                continue;
            }
            $authoritative = $apparatus->getAttribute($attribute);
            $initial = $baseline[$field] ?? null;
            $reason = match (true) {
                $baseline === null => 'baseline_unverified',
                $authoritative === null => 'baseline_missing',
                $initial === null || (float) $initial !== (float) $authoritative => 'stale_baseline',
                (float) $submitted < (float) $authoritative => 'rollback',
                (float) $submitted - (float) $authoritative > (float) config("daily_checkout.maximum_{$field}_increase") => 'implausible_increase',
                default => null,
            };
            if ($reason !== null) {
                ApparatusInspectionException::query()->create([
                    'apparatus_inspection_id' => $inspection->id,
                    'apparatus_id' => $apparatus->id,
                    'field' => $field,
                    'reason' => $reason,
                    'submitted_value' => $submitted,
                    'baseline_value' => $initial,
                    'authoritative_value' => $authoritative,
                ]);

                continue;
            }
            if ((float) $submitted !== (float) $authoritative) {
                $apparatus->setAttribute($attribute, $submitted);
                $meterChanged = true;
            }
        }
        if ($meterChanged) {
            $apparatus->reported_at = now();
            $apparatus->save();
        }

        $observations = $inspection->results ?? [];
        if (! empty($evidence['checklist_v2']['scheduled_tasks'])) {
            $observations[] = ['id' => 'scheduled_duties', 'name' => 'Scheduled duties', 'items' => $evidence['checklist_v2']['scheduled_tasks']];
        }
        app(DefectContinuityService::class)->recordFindings($inspection, $apparatus, $observations, $definitions, $evidence);

        $hasExceptions = ApparatusInspectionException::query()->where('apparatus_inspection_id', $inspection->id)->exists();
        $processingStatus = $hasExceptions ? 'accepted_with_exception' : 'accepted';
        ApparatusInspectionReviewEvent::query()->create([
            'apparatus_inspection_id' => $inspection->id,
            'previous_status' => $inspection->review_status,
            'status' => $processingStatus,
            // Automatic processing is not a human approval or impersonated actor.
            'changed_by_user_id' => null,
            'metadata' => ['processing_version' => 1, 'submitted_effects' => $evidence, 'reported_engine_hours' => $inspection->engine_hours, 'reported_miles' => $inspection->miles],
        ]);
        $inspection->update(['review_status' => 'approved', 'processing_status' => $processingStatus, 'pending_effects' => null, 'processed_apparatus_id' => $apparatus->id]);
        DB::afterCommit(function () use ($inspection, $apparatus, $meterChanged, $previousHealth): void {
            if ($meterChanged) {
                PmAlertNotificationJob::dispatch((int) $apparatus->id, $previousHealth);
            }
            if (filled($apparatus->snipeit_asset_id)) {
                AuditEquipmentAfterInspection::dispatch((int) $inspection->id, (int) $apparatus->id)->delay(now()->addSeconds(5));
            }
        });
    }
}
