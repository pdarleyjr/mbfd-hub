<?php

declare(strict_types=1);

namespace App\Services\Inspections;

use App\Models\Apparatus;
use App\Models\ApparatusInspection;
use App\Models\ApparatusInspectionException;
use App\Models\ApparatusInspectionReviewEvent;
use App\Models\ApparatusIssueMessage;
use App\Models\User;
use App\Services\ApparatusInspectionApprovalService;
use App\Services\ApparatusInspectionProcessingService;
use App\Services\Display\DisplaySnapshotService;
use App\Services\Fleet\VehicleSwapDecisionException;
use App\Services\Fleet\VehicleSwapService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Reviewer decisions on an inspection that automatic processing held
 * (docs/daily-inspection-continuity.md sections 2.3 and 4.2).
 *
 * Lock order everywhere: inspection -> apparatus -> exceptions -> defects.
 * Every decision is idempotent: a decided inspection is returned unchanged.
 * Approving never changes operational status; Out of Service is a separate,
 * explicitly requested and separately authorized option.
 */
final class InspectionReviewService
{
    private const POSTGRES_LOCK_TIMEOUT_ATTEMPTS = 4;

    private const POSTGRES_LOCK_TIMEOUT_BACKOFF_MICROSECONDS = 125_000;

    /**
     * Approve a held inspection: resolve its member-flag and significant-damage
     * holds. A meter rollback must be decided first from its own exception.
     * Legacy pending inspections (no processing status) use the legacy path.
     */
    public function approve(int $inspectionId, User $reviewer, ?string $note = null, bool $markOutOfService = false): ApparatusInspection
    {
        $note = $this->cleanText($note);
        $probe = ApparatusInspection::query()->findOrFail($inspectionId);
        Gate::forUser($reviewer)->authorize('approve', $probe);
        if ($probe->processing_status === null) {
            return app(ApparatusInspectionApprovalService::class)->approve($inspectionId, $reviewer, $markOutOfService, $note);
        }

        return $this->retryPostgresLockTimeout(fn (): ApparatusInspection => DB::transaction(
            fn (): ApparatusInspection => $this->approveLocked($inspectionId, $reviewer, $note, $markOutOfService),
            3,
        ));
    }

    /** Reject a held inspection. Evidence, defects and observations are retained; every open hold is dismissed. */
    public function reject(int $inspectionId, User $reviewer, string $reason): ApparatusInspection
    {
        $reason = $this->cleanText($reason);
        if ($reason === null) {
            throw ValidationException::withMessages(['reason' => 'Enter the reason for rejecting this inspection.']);
        }
        $probe = ApparatusInspection::query()->findOrFail($inspectionId);
        Gate::forUser($reviewer)->authorize('reject', $probe);
        if ($probe->processing_status === null) {
            return app(ApparatusInspectionApprovalService::class)->reject($inspectionId, $reviewer, $reason);
        }

        return $this->retryPostgresLockTimeout(fn (): ApparatusInspection => DB::transaction(
            fn (): ApparatusInspection => $this->rejectLocked($inspectionId, $reviewer, $reason),
            3,
        ));
    }

    /**
     * Ask the submitting member for more information. The inspection keeps its
     * status; the question is recorded in the review history and posted as a
     * member-visible message. A repeated client message id is a no-op.
     */
    public function requestClarification(int $inspectionId, User $reviewer, string $message, ?string $clientMessageId = null): ApparatusInspection
    {
        $message = $this->cleanText($message);
        if ($message === null || mb_strlen($message) > 2000) {
            throw ValidationException::withMessages(['message' => 'Enter a message for the member (up to 2000 characters).']);
        }
        $probe = ApparatusInspection::query()->findOrFail($inspectionId);
        Gate::forUser($reviewer)->authorize('approve', $probe);

        return $this->retryPostgresLockTimeout(fn (): ApparatusInspection => DB::transaction(function () use ($inspectionId, $reviewer, $message, $clientMessageId): ApparatusInspection {
            $inspection = ApparatusInspection::query()->lockForUpdate()->findOrFail($inspectionId);
            if ($clientMessageId !== null && ApparatusIssueMessage::query()->where('client_message_id', $clientMessageId)->exists()) {
                return $inspection;
            }
            $this->recordEvent($inspection, 'clarification_requested', $reviewer, $message, ['action' => 'request_clarification']);
            $this->postMemberMessage($inspection, $reviewer, $message, $clientMessageId);

            return $inspection;
        }, 3));
    }

    /**
     * Release an inspection once its last open hold is decided. The caller holds
     * the inspection row lock. Returns true only when this call released it.
     */
    public function releaseIfClear(ApparatusInspection $lockedInspection, ?User $actor = null): bool
    {
        if ($lockedInspection->review_status !== 'pending_review'
            || $lockedInspection->processing_status !== ApparatusInspectionProcessingService::STATUS_HELD
            || InspectionHold::openQuery((int) $lockedInspection->id)->exists()) {
            return false;
        }
        $this->release($lockedInspection, 'accepted', $actor, null, [
            'released_by' => $actor?->getKey(),
            'released_by_name' => $actor?->name,
        ]);
        if ($actor !== null) {
            // A human hold decision accepted the inspection; automatic acceptance stays silent.
            app(InspectionNotificationDispatcher::class)->inspectionDecided($lockedInspection, 'approved');
        }

        return true;
    }

    /**
     * Post a member-visible message on the inspection's general thread. Used by
     * callers that already hold the inspection lock (e.g. a meter revision request).
     */
    public function sendMemberMessage(ApparatusInspection $lockedInspection, User $author, string $body, ?string $clientMessageId = null): ApparatusIssueMessage
    {
        return $this->postMemberMessage($lockedInspection, $author, $body, $clientMessageId);
    }

    /** Member-visible clarification on the general thread; the message service notifies the inspection owner. */
    protected function postMemberMessage(ApparatusInspection $inspection, User $author, string $body, ?string $clientMessageId = null): ApparatusIssueMessage
    {
        return app(IssueMessageService::class)->post($author, [
            'inspection_id' => $inspection->getKey(),
            'visibility' => ApparatusIssueMessage::VISIBILITY_MEMBER,
            'body' => $body,
            'client_message_id' => $clientMessageId,
            'kind' => 'clarification_requested',
        ]);
    }

    private function approveLocked(int $inspectionId, User $reviewer, ?string $note, bool $markOutOfService): ApparatusInspection
    {
        $inspection = ApparatusInspection::query()->lockForUpdate()->findOrFail($inspectionId);
        if ($inspection->review_status !== 'pending_review') {
            return $inspection;
        }
        $this->refuseWhileHeldForVehicleSwap($inspection);
        if ($inspection->processing_status === null) {
            return app(ApparatusInspectionApprovalService::class)->approve($inspectionId, $reviewer, $markOutOfService, $note);
        }

        $apparatus = Apparatus::query()->lockForUpdate()->find($inspection->processed_apparatus_id ?? $inspection->apparatus_id);
        $holds = InspectionHold::openQuery($inspectionId)->orderBy('id')->lockForUpdate()->get();
        if ($holds->contains(static fn (ApparatusInspectionException $hold): bool => InspectionHold::isMeter($hold))) {
            throw ValidationException::withMessages(['inspection' => 'Decide the meter reading first. Review the meter exception, then approve.']);
        }

        $markedOutOfService = false;
        if ($markOutOfService && $apparatus !== null) {
            Gate::forUser($reviewer)->authorize('update', $apparatus);
            if ($apparatus->getAttribute('status') !== 'Out of Service') {
                // The model's status observer records the ledger event.
                $apparatus->update(['status' => 'Out of Service']);
                $markedOutOfService = true;
            }
        }
        foreach ($holds as $hold) {
            $hold->update(['status' => 'resolved']);
        }
        $this->release($inspection, 'approved', $reviewer, $note, [
            'action' => 'approve',
            'resolved_exception_ids' => $holds->pluck('id')->all(),
            'resolved_holds' => $holds->pluck('field')->all(),
            'out_of_service_requested' => $markOutOfService,
            'marked_out_of_service' => $markedOutOfService,
        ]);
        app(InspectionNotificationDispatcher::class)->inspectionDecided($inspection, 'approved');

        return $inspection;
    }

    private function rejectLocked(int $inspectionId, User $reviewer, string $reason): ApparatusInspection
    {
        $inspection = ApparatusInspection::query()->lockForUpdate()->findOrFail($inspectionId);
        if ($inspection->review_status !== 'pending_review') {
            return $inspection;
        }
        $this->refuseWhileHeldForVehicleSwap($inspection);
        if ($inspection->processing_status === null) {
            return app(ApparatusInspectionApprovalService::class)->reject($inspectionId, $reviewer, $reason);
        }

        $holds = InspectionHold::openQuery($inspectionId)->orderBy('id')->lockForUpdate()->get();
        foreach ($holds as $hold) {
            $hold->update(['status' => 'dismissed']);
        }
        $this->recordEvent($inspection, 'rejected', $reviewer, $reason, [
            'action' => 'reject',
            'dismissed_exception_ids' => $holds->pluck('id')->all(),
        ]);
        $inspection->update(['review_status' => 'rejected', 'processing_status' => 'rejected']);
        $this->forgetDisplayReadModels();
        app(InspectionNotificationDispatcher::class)->inspectionDecided($inspection, 'rejected');

        return $inspection;
    }

    /**
     * Mark the inspection accepted. Non-blocking follow-up exceptions that
     * remain open (historical rows only) keep the follow-up marker.
     *
     * @param  array<string, mixed>  $metadata
     */
    private function release(ApparatusInspection $inspection, string $eventStatus, ?User $actor, ?string $note, array $metadata): void
    {
        $followUp = ApparatusInspectionException::query()
            ->where('apparatus_inspection_id', $inspection->id)
            ->whereNotIn('status', InspectionHold::CLOSED_STATUSES)
            ->exists();
        $this->recordEvent($inspection, $eventStatus, $actor, $note, $metadata);
        $inspection->update([
            'review_status' => 'approved',
            'processing_status' => $followUp ? 'accepted_with_exception' : ApparatusInspectionProcessingService::STATUS_ACCEPTED,
        ]);
        $this->forgetDisplayReadModels();
    }

    /** @param array<string, mixed> $metadata */
    private function recordEvent(ApparatusInspection $inspection, string $status, ?User $actor, ?string $note, array $metadata): void
    {
        ApparatusInspectionReviewEvent::query()->create([
            'apparatus_inspection_id' => $inspection->getKey(),
            'previous_status' => (string) $inspection->review_status,
            'status' => $status,
            'internal_note' => $note,
            'changed_by_user_id' => $actor?->getKey(),
            'metadata' => [
                ...$metadata,
                'reviewer_name' => $actor?->name,
                'processing_status_before' => $inspection->processing_status,
            ],
        ]);
    }

    /**
     * A checkout held for a possible truck swap is released only by the Fleet
     * swap decision, which applies its findings to the vehicle Fleet confirms.
     */
    private function refuseWhileHeldForVehicleSwap(ApparatusInspection $inspection): void
    {
        if ($inspection->processing_status === VehicleSwapService::HELD_PROCESSING_STATUS) {
            throw new VehicleSwapDecisionException('This checkout is held for a possible vehicle swap. Decide it from Pending vehicle swaps.');
        }
    }

    private function forgetDisplayReadModels(): void
    {
        DB::afterCommit(static function (): void {
            Cache::forget(DisplaySnapshotService::SNAPSHOT_CACHE_KEY);
            Cache::forget(DisplaySnapshotService::STATIONS_CACHE_KEY);
        });
    }

    private function cleanText(?string $text): ?string
    {
        $text = $text === null ? null : trim($text);

        return $text === null || $text === '' ? null : $text;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $operation
     * @return T
     */
    private function retryPostgresLockTimeout(callable $operation): mixed
    {
        for ($attempt = 1; $attempt <= self::POSTGRES_LOCK_TIMEOUT_ATTEMPTS; $attempt++) {
            try {
                return $operation();
            } catch (QueryException $exception) {
                if (! $this->isPostgresLockTimeout($exception) || $attempt === self::POSTGRES_LOCK_TIMEOUT_ATTEMPTS) {
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
}
