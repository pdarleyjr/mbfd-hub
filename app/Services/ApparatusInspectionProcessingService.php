<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\AuditEquipmentAfterInspection;
use App\Jobs\PmAlertNotificationJob;
use App\Models\Apparatus;
use App\Models\ApparatusDefect;
use App\Models\ApparatusInspection;
use App\Models\ApparatusInspectionException;
use App\Models\ApparatusInspectionReviewEvent;
use App\Services\Captain5\Captain5Notifier;
use App\Services\Defects\DefectContinuityService;
use App\Services\Defects\FindingsResult;
use App\Services\Defects\InspectionConcernRecorder;
use App\Services\Inspections\InspectionHold;
use App\Services\Inspections\InspectionNotificationDispatcher;
use Illuminate\Support\Facades\DB;

/**
 * Applies one submitted inspection to its physical apparatus
 * (docs/daily-inspection-continuity.md section 2).
 *
 * Findings are facts and are always recorded. A routine inspection is accepted
 * automatically; it is held for a reviewer only for a meter rollback, a member
 * review flag, or new significant damage.
 */
final class ApparatusInspectionProcessingService
{
    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_HELD = 'held_for_review';

    /**
     * Called inside the submission transaction with the apparatus locked.
     * Legacy queued payloads retain their existing review contract.
     */
    public function process(ApparatusInspection $inspection, Apparatus $apparatus, ?string $baselineToken, array $checklist = []): void
    {
        $inspection->assertOperational();
        if ($inspection->processing_status !== null) {
            return;
        }
        $evidence = $inspection->pending_effects ?? [];
        $baseline = app(InspectionMeterBaseline::class)->read($baselineToken, (int) $apparatus->id);
        $previousHealth = $apparatus->getPmHealthStatus()['status'];
        $definitions = app(InspectionPaperFields::class)->equipmentDefinitions($checklist);

        ['changed' => $meterChanged, 'outcomes' => $meterOutcomes] = $this->applyMeters($inspection, $apparatus, $baseline);
        if ($meterChanged) {
            $apparatus->reported_at = now();
            $apparatus->save();
        }

        $observations = $inspection->results ?? [];
        // A duty done or not done is recorded on the inspection. It says nothing about equipment. Only a queued older
        // payload, which answered a duty with the equipment words, still feeds the defect history.
        $legacyDuties = array_values(array_filter(
            $evidence['checklist_v2']['scheduled_tasks'] ?? [],
            static fn (array $task): bool => ! in_array($task['status'] ?? null, ['Completed', 'Not Completed'], true),
        ));
        if ($legacyDuties !== []) {
            $observations[] = ['id' => 'scheduled_duties', 'name' => 'Scheduled duties', 'items' => $legacyDuties];
        }
        // Member concerns ride the same pass: a new issue is reported like a checklist finding, a note is only its row,
        // and a report about an issue already on record is added to it afterwards. All on the physical apparatus.
        $concerns = app(InspectionConcernRecorder::class);
        $batch = $concerns->begin($inspection, $apparatus, $evidence['concerns'] ?? []);
        $findings = app(DefectContinuityService::class)->recordFindings($inspection, $apparatus, [...$observations, ...$batch->compartments], $definitions, $evidence);
        $findings = $concerns->finish($batch, $inspection, $apparatus, $findings);

        $this->recordNonMeterHolds($inspection, $apparatus, $findings);
        $this->settle($inspection, $apparatus, $evidence, $meterOutcomes);
        $this->notifyReviewers($inspection, $findings);

        DB::afterCommit(function () use ($inspection, $apparatus, $meterChanged, $previousHealth): void {
            if ($meterChanged) {
                PmAlertNotificationJob::dispatch((int) $apparatus->id, $previousHealth);
            }
            if (filled($apparatus->snipeit_asset_id)) {
                AuditEquipmentAfterInspection::dispatch((int) $inspection->id, (int) $apparatus->id)->delay(now()->addSeconds(5));
            }
        });
    }

    /** Reviewers hear about a held inspection and about new significant damage; routine acceptance is silent. */
    private function notifyReviewers(ApparatusInspection $inspection, FindingsResult $findings): void
    {
        $notifications = app(InspectionNotificationDispatcher::class);
        $held = $inspection->processing_status === self::STATUS_HELD;
        if ($held) {
            // New significant damage always holds the checkout, and this one message already names the
            // damaged items, so reviewers are not sent a second "significant damage" alert for the same event.
            $notifications->reviewRequired($inspection);
        }
        if (! $held) {
            foreach ($findings->significantNewOrEscalatedDefectIds as $defectId) {
                $defect = ApparatusDefect::query()->find($defectId);
                if ($defect !== null) {
                    $notifications->issueEscalated($defect, $inspection);
                }
            }
        }
        // The on-duty Captain 5 hears about new Rescue and medical findings (never a repeat alert).
        app(Captain5Notifier::class)->newFindings($inspection, $findings);
    }

    /**
     * Record the member-flag and significant-damage holds. Public so a hold can
     * be unit-tested with an explicit FindingsResult.
     */
    public function recordNonMeterHolds(ApparatusInspection $inspection, Apparatus $apparatus, FindingsResult $findings): void
    {
        if (filled($inspection->review_flag_explanation)) {
            $this->createHold($inspection, $apparatus, InspectionHold::FIELD_REVIEW_FLAG, InspectionHold::REASON_MEMBER_FLAGGED, [
                'explanation' => $inspection->review_flag_explanation,
                'items' => $inspection->review_flag_items ?? [],
            ]);
        }
        if ($findings->significantNewOrEscalatedDefectIds !== []) {
            $this->createHold($inspection, $apparatus, InspectionHold::FIELD_SIGNIFICANT_DAMAGE, InspectionHold::REASON_SIGNIFICANT_DAMAGE, [
                'defect_ids' => $findings->significantNewOrEscalatedDefectIds,
            ]);
        }
    }

    /**
     * Meter rules (contract section 2.2). A reading never produces an exception
     * unless it is below the fleet reading and was not simply overtaken while
     * the member filled in the form.
     *
     * @param  array{engine_hours: mixed, miles: mixed}|null  $baseline  the readings the member's form loaded with
     * @return array{changed: bool, outcomes: array<string, string>}
     */
    private function applyMeters(ApparatusInspection $inspection, Apparatus $apparatus, ?array $baseline): array
    {
        $changed = false;
        $outcomes = [];
        foreach (['engine_hours' => 'current_engine_hours', 'miles' => 'current_miles'] as $field => $attribute) {
            $submitted = $inspection->getAttribute($field);
            if ($submitted === null) {
                continue;
            }
            $authoritative = $apparatus->getAttribute($attribute);
            $initial = $baseline[$field] ?? null;

            if ($authoritative === null || (float) $submitted >= (float) $authoritative) {
                if ($authoritative === null || (float) $submitted > (float) $authoritative) {
                    $apparatus->setAttribute($attribute, $submitted);
                    $changed = true;
                }

                continue;
            }
            if ($initial !== null && (float) $submitted >= (float) $initial) {
                // The fleet reading advanced after this form loaded. The member's
                // reading was right when taken; the newer one stays authoritative.
                $outcomes[$field] = 'superseded';

                continue;
            }
            $this->createRollbackHold($inspection, $apparatus, $field, $submitted, $initial, $authoritative);
        }

        return ['changed' => $changed, 'outcomes' => $outcomes];
    }

    private function createRollbackHold(ApparatusInspection $inspection, Apparatus $apparatus, string $field, mixed $submitted, mixed $initial, mixed $authoritative): void
    {
        $previous = $this->latestAcceptedReading($inspection, $apparatus, $field);
        ApparatusInspectionException::query()->create([
            'apparatus_inspection_id' => $inspection->id,
            'apparatus_id' => $apparatus->id,
            'field' => $field,
            'reason' => InspectionHold::REASON_ROLLBACK,
            'blocks_acceptance' => true,
            'submitted_value' => $submitted,
            'baseline_value' => $initial,
            'authoritative_value' => $authoritative,
            'metadata' => [
                'previous_inspection_id' => $previous?->id,
                'previous_reading_at' => $previous === null ? null : ($previous->completed_at ?? $previous->created_at)?->toIso8601String(),
                'previous_value' => $previous?->getAttribute($field),
            ],
        ]);
    }

    /** The latest accepted inspection of this physical vehicle that recorded the reading. */
    private function latestAcceptedReading(ApparatusInspection $inspection, Apparatus $apparatus, string $field): ?ApparatusInspection
    {
        return ApparatusInspection::query()
            ->operational()
            ->where(static function ($query) use ($apparatus): void {
                $query->where('processed_apparatus_id', $apparatus->id)
                    ->orWhere(static fn ($legacy) => $legacy->whereNull('processed_apparatus_id')->where('apparatus_id', $apparatus->id));
            })
            ->where('id', '!=', $inspection->id)
            ->where('review_status', 'approved')
            ->whereNotNull($field)
            ->orderByDesc('completed_at')
            ->orderByDesc('id')
            ->first();
    }

    /** @param array<string, mixed> $metadata */
    private function createHold(ApparatusInspection $inspection, Apparatus $apparatus, string $field, string $reason, array $metadata): void
    {
        ApparatusInspectionException::query()->create([
            'apparatus_inspection_id' => $inspection->id,
            'apparatus_id' => $apparatus->id,
            'field' => $field,
            'reason' => $reason,
            'blocks_acceptance' => true,
            'metadata' => $metadata,
        ]);
    }

    /**
     * Final status: any open blocking hold keeps the inspection pending review,
     * otherwise it is accepted automatically.
     *
     * @param  array<string, mixed>  $evidence
     * @param  array<string, string>  $meterOutcomes
     */
    private function settle(ApparatusInspection $inspection, Apparatus $apparatus, array $evidence, array $meterOutcomes): void
    {
        $holds = InspectionHold::openQuery((int) $inspection->id)->orderBy('id')->get(['field', 'reason']);
        $held = $holds->isNotEmpty();
        $processingStatus = $held ? self::STATUS_HELD : self::STATUS_ACCEPTED;
        $reviewStatus = $held ? 'pending_review' : 'approved';
        ApparatusInspectionReviewEvent::query()->create([
            'apparatus_inspection_id' => $inspection->id,
            'previous_status' => $inspection->review_status,
            'status' => $processingStatus,
            // Automatic processing is not a human approval or impersonated actor.
            'changed_by_user_id' => null,
            'metadata' => [
                'processing_version' => 1,
                'submitted_effects' => $evidence,
                'reported_engine_hours' => $inspection->engine_hours,
                'reported_miles' => $inspection->miles,
                'meter_outcomes' => $meterOutcomes,
                'holds' => $holds->map(static fn (ApparatusInspectionException $hold): array => ['field' => $hold->field, 'reason' => $hold->reason])->all(),
            ],
        ]);
        $inspection->update([
            'review_status' => $reviewStatus,
            'processing_status' => $processingStatus,
            'pending_effects' => null,
            'processed_apparatus_id' => $apparatus->id,
        ]);
    }
}
