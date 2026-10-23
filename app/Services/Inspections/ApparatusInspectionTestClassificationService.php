<?php

declare(strict_types=1);

namespace App\Services\Inspections;

use App\Models\Apparatus;
use App\Models\ApparatusDefect;
use App\Models\ApparatusInspection;
use App\Models\ApparatusInspectionTestEvent;
use App\Models\User;
use App\Services\Display\DisplaySnapshotService;
use App\Support\Fleet\InspectionReviewers;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Reversible classification only; never deletes evidence or compensates operational effects. */
final class ApparatusInspectionTestClassificationService
{
    public function exclude(int $inspectionId, User $actor, string $reason, ?string $idempotencyKey = null): ApparatusInspection
    {
        return $this->change($inspectionId, $actor, $reason, true, $idempotencyKey);
    }

    public function restore(int $inspectionId, User $actor, string $reason, ?string $idempotencyKey = null): ApparatusInspection
    {
        return $this->change($inspectionId, $actor, $reason, false, $idempotencyKey);
    }

    private function change(int $inspectionId, User $actor, string $reason, bool $exclude, ?string $idempotencyKey): ApparatusInspection
    {
        abort_unless(InspectionReviewers::canReview($actor), 403);
        $reason = trim($reason);
        Validator::make(['reason' => $reason, 'idempotency_key' => $idempotencyKey], [
            'reason' => ['required', 'string', 'max:2000'],
            'idempotency_key' => ['nullable', 'uuid'],
        ])->validate();
        $action = $exclude ? 'excluded' : 'restored';
        $storedKey = $idempotencyKey;

        return DB::transaction(function () use ($inspectionId, $actor, $reason, $exclude, $action, $storedKey): ApparatusInspection {
            $inspection = ApparatusInspection::query()->lockForUpdate()->findOrFail($inspectionId);
            $existing = $storedKey === null ? null : ApparatusInspectionTestEvent::query()->where('idempotency_key', $storedKey)->first();
            if ($existing !== null) {
                if ((int) $existing->apparatus_inspection_id !== $inspectionId || (int) $existing->actor_user_id !== (int) $actor->getKey()
                    || $existing->action !== $action || $existing->reason !== $reason) {
                    throw ValidationException::withMessages(['inspection' => 'This action identifier was already used for a different classification.']);
                }

                return $inspection;
            }
            if (($inspection->test_excluded_at !== null) === $exclude) {
                return $inspection;
            }
            if ($exclude) {
                $this->assertNoOperationalEffects($inspection);
            }

            $inspection->forceFill([
                'test_excluded_at' => $exclude ? now() : null,
                'test_excluded_by_user_id' => $exclude ? $actor->getKey() : null,
                'test_exclusion_reason' => $exclude ? $reason : null,
            ])->save();
            $inspection->testClassificationEvents()->create([
                'actor_user_id' => $actor->getKey(), 'action' => $action,
                'reason' => $reason, 'idempotency_key' => $storedKey,
            ]);
            $stationId = Apparatus::query()->whereKey($inspection->apparatus_id)->value('station_id');
            DB::afterCommit(static function () use ($stationId): void {
                Cache::forget(DisplaySnapshotService::SNAPSHOT_CACHE_KEY);
                Cache::forget(DisplaySnapshotService::STATIONS_CACHE_KEY);
                Cache::forget("station.{$stationId}.detail");
                Cache::forget("station.{$stationId}.activity");
            });

            return $inspection->refresh();
        }, 3);
    }

    private function assertNoOperationalEffects(ApparatusInspection $inspection): void
    {
        $id = (int) $inspection->getKey();
        $hasEffects = $inspection->engine_hours !== null || $inspection->miles !== null
            || $inspection->review_status !== 'approved'
            || $inspection->processed_apparatus_id !== null || $inspection->processing_status !== null
            || ! empty($inspection->pending_effects['defects']) || ! empty($inspection->pending_effects['has_critical_defects']);
        $hasDefect = ApparatusDefect::query()->where(function ($query) use ($id): void {
            $query->where('apparatus_inspection_id', $id)->orWhere('first_reported_inspection_id', $id)->orWhere('last_observed_inspection_id', $id);
        })->exists();
        $hasEffects = $hasEffects || DB::table('apparatus_defect_participants')
            ->where(fn ($query) => $query->where('first_inspection_id', $id)->orWhere('last_inspection_id', $id))->exists();
        foreach ([
            'apparatus_inspection_review_events' => 'apparatus_inspection_id',
            'apparatus_inspection_exceptions' => 'apparatus_inspection_id',
            'apparatus_defect_observations' => 'apparatus_inspection_id',
            'apparatus_defect_events' => 'apparatus_inspection_id',
            'apparatus_issue_messages' => 'apparatus_inspection_id',
            'apparatus_vehicle_swap_requests' => 'apparatus_inspection_id',
            'daily_checkout_inspection_sessions' => 'submitted_inspection_id',
            'apparatus_inventory_corrections' => 'apparatus_inspection_id',
        ] as $table => $column) {
            $hasEffects = $hasEffects || DB::table($table)->where($column, $id)->exists();
        }
        if (is_string($inspection->inventory_draft_id) && $inspection->inventory_draft_id !== '' && $inspection->actor_user_id !== null) {
            $hasEffects = $hasEffects || DB::table('apparatus_inventory_corrections')
                ->where('inspection_draft_id', strtolower($inspection->inventory_draft_id))
                ->where('apparatus_id', $inspection->apparatus_id)
                ->where('reporter_user_id', $inspection->actor_user_id)
                ->exists();
        }
        if ($hasEffects || $hasDefect) {
            throw ValidationException::withMessages([
                'inspection' => 'Only an approved legacy inspection without meter readings or linked operational history can be excluded here. Review any operational effects separately.',
            ]);
        }
    }
}
