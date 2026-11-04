<?php

declare(strict_types=1);

namespace App\Services\Defects;

use App\Models\ApparatusDefect;
use App\Models\ApparatusDefectEvent;
use App\Models\User;
use App\Services\Captain5\Captain5Notifier;
use App\Services\Inspections\InspectionNotificationDispatcher;
use App\Support\Fleet\DefectActionStatus;
use App\Support\Fleet\PostgresLockRetry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * A member says an equipment issue is fixed (docs/unified-activity-and-ownership.md section 3): the reporter, or
 * anyone who found the item later. One tap; the server decides what it means.
 *
 *  - Routine (routine severity, no impact flag beyond "non blocking", not damaged, no service ticket, not a stretcher
 *    report): resolved now, by the member, with a member_resolved event. Staff can reopen it.
 *  - Anything else: correction_reported, "awaiting verification", and the owner (Captain 5 or Logistics) closes it
 *    with Verify correction. Nothing here ever changes the apparatus' service status.
 *
 * Authorization is the caller's member rule (an active member with an employee profile, the issue-history rule).
 * Retries with the same client action id return the first outcome; two members at once produce one closure.
 */
final class DefectMemberCorrection
{
    public const EVENT_RESOLVED = 'member_resolved';

    public const EVENT_REPORTED = 'correction_reported';

    /** Member choice => resolution type recorded when it closes. */
    public const OUTCOMES = [
        'found' => 'item_located',
        'replaced' => 'replacement_installed',
        'repaired' => 'repair_completed',
        'corrected' => 'other',
        'no_longer_deficient' => 'other',
    ];

    private const OUTCOME_LABELS = [
        'found' => 'Found',
        'replaced' => 'Replaced',
        'repaired' => 'Repaired',
        'corrected' => 'Fixed',
        'no_longer_deficient' => 'No longer a problem',
    ];

    public function __construct(
        private readonly DefectParticipants $participants,
        private readonly InspectionNotificationDispatcher $notifications,
        private readonly Captain5Notifier $captain5Notifier,
    ) {}

    /** Whether a member's "fixed" closes the issue at once, or needs the owner's verification. */
    public static function closesImmediately(ApparatusDefect $defect): bool
    {
        return $defect->severity !== 'significant'
            && in_array((string) ($defect->operational_impact ?: 'unclassified'), ['unclassified', 'non_blocking'], true)
            && $defect->issue_type !== 'damaged'
            && $defect->service_ticket_id === null
            && (string) $defect->compartment_key !== ApparatusDefect::STRETCHER_REPORT_COMPARTMENT;
    }

    /**
     * A member said the item is back, and the owner has not verified it yet: the latest checkout said Present, or a
     * "Report fixed" newer than (or as new as) that observation. The one rule for every Verify correction button.
     */
    public static function awaitingVerification(ApparatusDefect $defect, ?\App\Models\ApparatusDefectObservation $latest): bool
    {
        if (DefectActionStatus::isClosed($defect->action_status)) {
            return false;
        }
        $reported = $defect->correction_reported_at;
        if ($reported !== null && ($latest === null || $reported->greaterThanOrEqualTo($latest->created_at))) {
            return true;
        }

        return $latest?->observation === 'appears_corrected';
    }

    /** @return array{eligible: bool, closes_immediately: bool, pending: array{at: string}|null} */
    public static function stateFor(ApparatusDefect $defect): array
    {
        $open = ! DefectActionStatus::isClosed($defect->action_status);
        $pending = $open && $defect->correction_reported_at !== null
            ? ['at' => $defect->correction_reported_at->toIso8601String()]
            : null;

        return ['eligible' => $open && $pending === null, 'closes_immediately' => self::closesImmediately($defect), 'pending' => $pending];
    }

    /**
     * @param  array{outcome?: mixed, note?: mixed}  $data
     *
     * @throws MemberCorrectionRefused|\Illuminate\Validation\ValidationException
     */
    public function report(User $member, int $defectId, array $data, string $clientActionId): MemberCorrectionResult
    {
        $valid = Validator::make([...$data, 'client_action_id' => $clientActionId], [
            'outcome' => ['required', Rule::in(array_keys(self::OUTCOMES))],
            'note' => ['nullable', 'string', 'max:1000'],
            'client_action_id' => ['required', 'uuid'],
        ], ['outcome.required' => 'Choose what happened.'])->validate();
        $note = trim((string) ($valid['note'] ?? '')) ?: null;
        $key = 'defect:'.$defectId.':member_correction:'.strtolower($clientActionId);

        return PostgresLockRetry::run(fn (): MemberCorrectionResult => DB::transaction(function () use ($member, $defectId, $valid, $note, $key): MemberCorrectionResult {
            $defect = ApparatusDefect::query()->lockForUpdate()->find($defectId);
            if (! $defect instanceof ApparatusDefect) {
                throw MemberCorrectionRefused::notFound();
            }
            $previous = ApparatusDefectEvent::query()->where('idempotency_key', $key)->first();
            if ($previous instanceof ApparatusDefectEvent) {
                if ((int) $previous->actor_user_id !== (int) $member->getKey()) {
                    throw MemberCorrectionRefused::keyConflict();
                }

                return MemberCorrectionResult::fromEvent($previous, $defect); // a retry of what already took effect
            }
            if (DefectActionStatus::isClosed($defect->action_status)) {
                throw MemberCorrectionRefused::closed($defect);
            }

            $outcome = (string) $valid['outcome'];
            $from = (string) ($defect->action_status ?: DefectActionStatus::REPORTED);
            $details = ['outcome' => $outcome, 'note' => $note, 'member_resolution' => true];
            if (self::closesImmediately($defect)) {
                $defect->action_status = DefectActionStatus::RESOLVED;
                $defect->resolved_by_user_id = $member->getKey();
                $defect->resolution_type = self::OUTCOMES[$outcome];
                $defect->resolution_notes = trim('Member reported: '.self::OUTCOME_LABELS[$outcome].'. '.($note ?? ''));
                $defect->save();
                $type = self::EVENT_RESOLVED;
                $to = DefectActionStatus::RESOLVED;
            } else {
                $defect->correction_reported_at = now();
                $defect->correction_reported_by_user_id = $member->getKey();
                $defect->save();
                $type = self::EVENT_REPORTED;
                $to = $from;
            }

            $event = ApparatusDefectEvent::query()->create([
                'apparatus_defect_id' => $defect->getKey(),
                'apparatus_inspection_id' => null,
                'actor_user_id' => $member->getKey(),
                'event_type' => $type,
                'from_status' => $from,
                'to_status' => $to,
                'member_message' => null,
                'internal_note' => null,
                'details' => $details,
                'idempotency_key' => $key,
            ]);
            $this->participants->enroll($defect, $member, 'member_correction');
            $defect->refresh();
            $this->notify($defect, $event);

            return MemberCorrectionResult::fromEvent($event, $defect);
        }, 3));
    }

    /** Closing tells the people following the issue; a "please verify" is the owner's queue, not a page. */
    private function notify(ApparatusDefect $defect, ApparatusDefectEvent $event): void
    {
        if ($event->event_type !== self::EVENT_RESOLVED) {
            return;
        }
        if ($defect->getAttribute('responsible_owner') === ApparatusDefect::OWNER_CAPTAIN5) {
            $this->captain5Notifier->issueUpdated($defect, $event);

            return;
        }
        $this->notifications->issueResolved($defect, $event);
    }
}
