<?php

declare(strict_types=1);

namespace App\Services\Defects;

use App\Models\Apparatus;
use App\Models\ApparatusDefect;
use App\Models\ApparatusDefectEvent;
use App\Models\StretcherIssueReport;
use App\Models\User;
use App\Services\Captain5\Captain5Notifier;
use App\Support\Fleet\DefectActionStatus;
use App\Support\Fleet\PostgresLockRetry;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * "Report stretcher issue" outside a Daily Checkout (docs/unified-activity-and-ownership.md section 4).
 *
 * One stretcher_issue_reports row per report (who, what, photo). The issue is the ordinary apparatus_defects row:
 * created with the pseudo-compartment `stretcher_report` and item key `stretcher:{problem}`, or, when that problem is
 * already open on the unit, joined with an observed_again event so it is never duplicated or reset. No inspection is
 * created or touched. The owner is always Captain 5 (DefectOwnership), on any apparatus. "Cannot be used" makes it
 * significant and also tells the EMS Captain; it never changes the unit's service status.
 *
 * Idempotent on client_submission_id: a retry returns the first result, a reused id from someone else is refused.
 */
final class StretcherIssueReporter
{
    public function __construct(
        private readonly DefectParticipants $participants,
        private readonly Captain5Notifier $notifier,
        private readonly DefectOwnership $ownership,
    ) {}

    /**
     * @param  array{client_submission_id: string, problem: string, description: string, unusable: bool, photo_path: ?string}  $data
     * @return array{report: StretcherIssueReport, defect: ApparatusDefect, replayed: bool}
     */
    public function report(User $member, Apparatus $apparatus, array $data): array
    {
        $existing = StretcherIssueReport::query()->where('client_submission_id', $data['client_submission_id'])->first();
        if ($existing instanceof StretcherIssueReport) {
            return $this->replay($existing, $member, $apparatus, $data);
        }

        try {
            return PostgresLockRetry::run(fn (): array => DB::transaction(fn (): array => $this->record($member, $apparatus, $data), 3));
        } catch (UniqueConstraintViolationException) {
            // Two sends of the same form raced; the first one won.
            return $this->replay(StretcherIssueReport::query()->where('client_submission_id', $data['client_submission_id'])->firstOrFail(), $member, $apparatus, $data);
        }
    }

    /**
     * @param  array{client_submission_id: string, problem: string, description: string, unusable: bool, photo_path: ?string}  $data
     * @return array{report: StretcherIssueReport, defect: ApparatusDefect, replayed: bool}
     */
    private function record(User $member, Apparatus $apparatus, array $data): array
    {
        Apparatus::query()->lockForUpdate()->findOrFail($apparatus->getKey()); // global lock order: apparatus -> defects
        $problem = $data['problem'];
        $itemKey = 'stretcher:'.$problem;
        $open = ApparatusDefect::query()->lockForUpdate()
            ->where('apparatus_id', $apparatus->getKey())->where('resolved', false)
            ->where('compartment_key', ApparatusDefect::STRETCHER_REPORT_COMPARTMENT)->where('item_key', $itemKey)
            ->first();

        $attached = $open instanceof ApparatusDefect;
        if ($attached) {
            $defect = $open;
            $defect->last_observed_at = now();
            if ($data['unusable']) {
                $defect->severity = 'significant';
            }
            $defect->save();
        } else {
            $defect = ApparatusDefect::query()->create([
                'apparatus_id' => $apparatus->getKey(),
                'apparatus_inspection_id' => null,
                'compartment' => 'Stretcher',
                'compartment_key' => ApparatusDefect::STRETCHER_REPORT_COMPARTMENT,
                'item' => 'Stretcher: '.StretcherIssueReport::PROBLEMS[$problem],
                'item_key' => $itemKey,
                'status' => 'open',
                'issue_type' => 'damaged',
                'reported_date' => now()->toDateString(),
                'notes' => $data['description'],
                'photo_path' => $data['photo_path'],
                'severity' => $data['unusable'] ? 'significant' : 'routine',
                'action_status' => DefectActionStatus::REPORTED,
                'first_reported_by_user_id' => $member->getKey(),
                'first_reported_at' => now(),
                'last_observed_at' => now(),
            ]);
        }

        $report = StretcherIssueReport::query()->create([
            'client_submission_id' => $data['client_submission_id'],
            'apparatus_id' => $apparatus->getKey(),
            'apparatus_defect_id' => $defect->getKey(),
            'reporter_user_id' => $member->getKey(),
            'reporter_employee_id' => $member->employee_profile_id,
            'problem' => $problem,
            'description' => $data['description'],
            'unusable' => $data['unusable'],
            'photo_path' => $data['photo_path'],
            'attached_to_existing' => $attached,
        ]);

        $status = (string) ($defect->action_status ?: DefectActionStatus::REPORTED);
        ApparatusDefectEvent::query()->create([
            'apparatus_defect_id' => $defect->getKey(),
            'apparatus_inspection_id' => null,
            'actor_user_id' => $member->getKey(),
            'event_type' => $attached ? 'observed_again' : 'reported',
            'from_status' => $attached ? $status : null,
            'to_status' => $status,
            'member_message' => null,
            'internal_note' => null,
            'details' => [
                'stretcher_report_id' => (int) $report->getKey(),
                'problem' => $problem,
                'unusable' => $data['unusable'],
                'description' => mb_substr($data['description'], 0, 1000),
                // What the member wrote, under the key the member issue history reads (MemberIssueHistory).
                'notes' => mb_substr($data['description'], 0, 1000),
            ],
            'idempotency_key' => 'stretcher-report:'.$report->getKey(),
        ]);
        $this->participants->enroll($defect, $member, $attached ? 'stretcher_report' : 'reporter');
        $this->ownership->project($defect->refresh(), 'stretcher_report');
        $this->notifier->stretcherReported($report);

        return ['report' => $report, 'defect' => $defect->refresh(), 'replayed' => false];
    }

    /**
     * @param  array{client_submission_id: string, problem: string, description: string, unusable: bool, photo_path: ?string}  $data
     * @return array{report: StretcherIssueReport, defect: ApparatusDefect, replayed: bool}
     */
    private function replay(StretcherIssueReport $report, User $member, Apparatus $apparatus, array $data): array
    {
        if ((int) $report->reporter_user_id !== (int) $member->getKey()) {
            throw new ConflictHttpException('That report id was already used. Reload the form and send it again.');
        }
        // The same id with a different unit, problem or "cannot be used" answer is a different report, never a retry.
        if ((int) $report->apparatus_id !== (int) $apparatus->getKey()
            || $report->problem !== $data['problem']
            || (bool) $report->unusable !== (bool) $data['unusable']) {
            throw new ConflictHttpException('That report id was already used for a different report. Reload the form and send it again.');
        }

        return ['report' => $report, 'defect' => ApparatusDefect::query()->findOrFail($report->apparatus_defect_id), 'replayed' => true];
    }
}
