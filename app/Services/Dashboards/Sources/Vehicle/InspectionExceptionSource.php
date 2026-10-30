<?php

declare(strict_types=1);

namespace App\Services\Dashboards\Sources\Vehicle;

use App\Filament\Support\DefectCorrectiveActions;
use App\Filament\Support\InspectionWorkspace;
use App\Models\Apparatus;
use App\Models\ApparatusDefect;
use App\Models\ApparatusInspection;
use App\Models\ApparatusInspectionException;
use App\Models\ApparatusInspectionReviewEvent;
use App\Models\User;
use App\Services\ApparatusInspectionExceptionService;
use App\Services\Dashboards\Support\ApparatusClass;
use App\Services\Dashboards\Support\StaleVersion;
use App\Services\Dashboards\Support\VehicleIssueSource;
use App\Services\Inspections\InspectionHold;
use App\Support\Dashboards\ActionResult;
use App\Support\Dashboards\DashboardIssue;
use App\Support\Dashboards\IssueAction;
use App\Support\Dashboards\IssueDetail;
use App\Support\OperationalDisplayWindow;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Daily Checkout exceptions: persisted holds on an operational inspection
 * (apparatus_inspection_exceptions) for a meter reading or an equipment finding that needs a
 * reviewer's decision. Decided through ApparatusInspectionExceptionService::reconcile(), the same
 * call as the Inspection Exceptions resource "Review" action, so review history, member messages,
 * inspection release and PM alerts follow unchanged. Member-flag and significant-damage holds are
 * decided by approving or rejecting the inspection itself; they are read-only here.
 *
 * Visibility: ApparatusInspectionExceptionPolicy viewAny; actions: its `update` (approve rule).
 *
 * Severity: significant-damage hold -> high; any other hold that blocks acceptance -> medium;
 * otherwise low. Stage: open, revision submitted (member answered) -> awaiting_response;
 * revision requested (waiting on the member) -> in_progress; resolved -> resolved;
 * dismissed -> other. Row set: undecided exceptions on operational (non-test) inspections, plus
 * those resolved or dismissed in the operational day (status is the only column a decision
 * changes, so its updated_at is the decision time).
 *
 * Version: "{status}:{updated_at epoch}:{authoritative meter}". The meter part is the
 * expected_current_value the service insists on, so a meter changed by another decision is a
 * conflict. perform() locks inspection -> apparatus -> exception (the service's lock order).
 *
 * @extends VehicleIssueSource<ApparatusInspectionException>
 */
final class InspectionExceptionSource extends VehicleIssueSource
{
    private const CLOSED = ['resolved', 'dismissed'];

    private const METERS = ['engine_hours' => 'current_engine_hours', 'miles' => 'current_miles'];

    private const SEVERITY = "CASE WHEN apparatus_inspection_exceptions.field = 'significant_damage' THEN 'high'"
        ." WHEN apparatus_inspection_exceptions.blocks_acceptance = TRUE THEN 'medium' ELSE 'low' END";

    private const STAGE = "CASE WHEN apparatus_inspection_exceptions.status IN ('open', 'revision_submitted') THEN 'awaiting_response'"
        ." WHEN apparatus_inspection_exceptions.status = 'revision_requested' THEN 'in_progress'"
        ." WHEN apparatus_inspection_exceptions.status = 'resolved' THEN 'resolved' ELSE 'other' END";

    private const OCCURRED = "CASE WHEN apparatus_inspection_exceptions.status IN ('resolved', 'dismissed') THEN apparatus_inspection_exceptions.updated_at"
        .' ELSE apparatus_inspection_exceptions.created_at END';

    public function type(): string
    {
        return 'inspection_exception';
    }

    public function visibleTo(User $user, bool $displayMode): bool
    {
        return Gate::forUser($user)->allows('viewAny', ApparatusInspectionException::class);
    }

    protected function newQuery(): Builder
    {
        return ApparatusInspectionException::query();
    }

    protected function scopeToBoard(Builder $query, OperationalDisplayWindow $window): void
    {
        $query->whereIn('apparatus_inspection_exceptions.apparatus_inspection_id', ApparatusInspection::query()->operational()->select('id'))
            ->where(fn (Builder $board) => $board
                ->whereNotIn('apparatus_inspection_exceptions.status', self::CLOSED)
                ->orWhere(fn (Builder $closed) => $closed
                    ->whereIn('apparatus_inspection_exceptions.status', self::CLOSED)
                    ->where('apparatus_inspection_exceptions.updated_at', '>=', $window->databaseStart())
                    ->where('apparatus_inspection_exceptions.updated_at', '<', $window->databaseEnd())));
    }

    protected function severitySql(): string
    {
        return self::SEVERITY;
    }

    protected function stageSql(): string
    {
        return self::STAGE;
    }

    protected function occurredSql(): string
    {
        return self::OCCURRED;
    }

    protected function eagerLoads(): array
    {
        return [
            'apparatus:'.implode(',', [...ApparatusClass::COLUMNS, 'current_engine_hours', 'current_miles']),
            'inspection:id,operator_name,vehicle_number,completed_at,created_at',
        ];
    }

    protected function toIssue(Model $record, bool $displayMode, array $projection): DashboardIssue
    {
        $field = (string) $record->field;

        return new DashboardIssue(
            type: $this->type(),
            id: (string) $record->getKey(),
            title: match (true) {
                $field === 'engine_hours' => 'Engine hours reading needs review',
                $field === 'miles' => 'Mileage reading needs review',
                $field === InspectionHold::FIELD_REVIEW_FLAG => 'Crew asked for a review',
                $field === InspectionHold::FIELD_SIGNIFICANT_DAMAGE => 'Significant damage reported',
                str_starts_with($field, 'defect:') => 'Equipment finding held for review',
                default => str($field)->headline()->toString(),
            },
            summary: $this->summary($record),
            severity: $projection['severity'],
            status: (string) $record->status,
            statusLabel: str((string) $record->status)->headline()->toString(),
            stage: $projection['stage'],
            tags: $projection['tags'],
            subject: $projection['subject'],
            imageKey: $projection['imageKey'],
            actorLabel: $displayMode ? null : (trim((string) $record->inspection?->operator_name) ?: null),
            categoryLabel: 'Checkout hold',
            occurredAt: $projection['occurredAt'],
            version: self::versionOf($record, $record->getRelationValue('apparatus')),
            sourceUrl: InspectionWorkspace::url((int) $record->apparatus_inspection_id, str_starts_with($field, 'defect:') && isset($record->metadata['defect_id'])
                ? 'issue-'.(int) $record->metadata['defect_id']
                : 'hold-'.$record->getKey()),
        );
    }

    private function summary(ApparatusInspectionException $record): string
    {
        $reason = str((string) $record->reason)->replace('_', ' ')->headline()->toString();
        if (! isset(self::METERS[$record->field])) {
            return $reason;
        }
        $current = $record->apparatus?->getAttribute(self::METERS[$record->field]);

        return $reason.' · Reported '.($record->submitted_value ?? 'nothing').' · Fleet record '.($current ?? 'unknown');
    }

    private static function versionOf(ApparatusInspectionException $exception, ?Apparatus $apparatus): string
    {
        $meter = isset(self::METERS[$exception->field]) ? $apparatus?->getAttribute(self::METERS[$exception->field]) : null;

        return $exception->status.':'.(int) $exception->updated_at?->getTimestamp().':'.($meter === null ? '-' : (string) (float) $meter);
    }

    // ------------------------------------------------------------------ detail

    public function detail(User $user, string $id): ?IssueDetail
    {
        if (! $this->visibleTo($user, false) || ($fresh = $this->freshIssue($id)) === null) {
            return null;
        }
        $exception = $fresh['record'];
        assert($exception instanceof ApparatusInspectionException);
        if (! $exception->inspection instanceof ApparatusInspection || ! Gate::forUser($user)->allows('view', $exception)) {
            return null;
        }
        $field = (string) $exception->field;
        $isMeter = isset(self::METERS[$field]);
        $defect = str_starts_with($field, 'defect:')
            ? ApparatusDefect::query()->where('apparatus_id', $exception->apparatus_id)->find((int) ($exception->metadata['defect_id'] ?? 0))
            : null;

        $facts = array_values(array_filter([
            ['label' => 'Unit', 'value' => (string) $fresh['issue']->subject],
            ['label' => 'Physical vehicle', 'value' => trim((string) $exception->inspection->vehicle_number)],
            ['label' => 'Reason', 'value' => str((string) $exception->reason)->replace('_', ' ')->headline()->toString()],
            ['label' => 'Finding', 'value' => $defect instanceof ApparatusDefect ? $defect->compartment.' / '.$defect->item.' — '.$defect->issue_type : ''],
            ['label' => 'Reported value', 'value' => $isMeter ? (string) ($exception->submitted_value ?? 'Not entered') : ''],
            ['label' => 'Baseline at the time', 'value' => $isMeter ? (string) ($exception->baseline_value ?? 'Unknown') : ''],
            ['label' => 'Current fleet value', 'value' => $isMeter ? (string) ($exception->apparatus?->getAttribute(self::METERS[$field]) ?? 'Unknown') : ''],
            ['label' => 'Status', 'value' => (string) $fresh['issue']->statusLabel],
            ['label' => 'Submitted by', 'value' => (string) $fresh['issue']->actorLabel],
            ['label' => 'Received', 'value' => (string) self::local($exception->created_at)],
        ], static fn (array $fact): bool => $fact['value'] !== ''));

        $timeline = ApparatusInspectionReviewEvent::query()
            ->where('apparatus_inspection_id', $exception->apparatus_inspection_id)
            ->orderBy('id')
            ->limit(500)
            ->get()
            ->filter(static fn (ApparatusInspectionReviewEvent $event): bool => (int) ($event->metadata['exception_id'] ?? 0) === (int) $exception->getKey())
            ->map(static fn (ApparatusInspectionReviewEvent $event): array => [
                'at' => CarbonImmutable::parse($event->created_at),
                'actor' => is_string($event->metadata['actor_name'] ?? null) ? $event->metadata['actor_name'] : null,
                'text' => trim(str((string) $event->status)->headline()->toString().(filled($event->internal_note) ? "\n".$event->internal_note : '')),
            ])->values()->all();

        $canDecide = Gate::forUser($user)->allows('update', $exception);
        $reason = match (true) {
            in_array($exception->status, self::CLOSED, true) => 'This hold has already been decided.',
            in_array($field, [InspectionHold::FIELD_REVIEW_FLAG, InspectionHold::FIELD_SIGNIFICANT_DAMAGE], true) => 'This hold is decided by approving or rejecting the inspection. Open the inspection to review it.',
            ! $canDecide => 'Only daily inspection reviewers can decide checkout holds.',
            default => null,
        };

        return new IssueDetail(
            issue: $fresh['issue'],
            body: null,
            facts: $facts,
            timeline: $timeline,
            actions: $reason === null ? $this->actionsFor($exception, $isMeter) : [],
            readOnlyReason: $reason,
        );
    }

    /** @return list<IssueAction> */
    private function actionsFor(ApparatusInspectionException $exception, bool $isMeter): array
    {
        $note = self::textarea('reason', 'Decision note', true);
        $reset = self::select('confirm_meter_reset', 'Lower than the current reading?', [
            'no' => 'No',
            'yes' => 'Yes, the vehicle meter was reset or replaced',
        ], false);
        $current = $isMeter ? $exception->apparatus?->getAttribute(self::METERS[$exception->field]) : null;
        $lower = $isMeter && $current !== null && $exception->submitted_value !== null && (float) $exception->submitted_value < (float) $current;

        return array_values(array_filter([
            $isMeter
                ? new IssueAction('accept', 'Accept reported value', 'primary', array_values(array_filter([$lower ? [...$reset, 'required' => true] : null, $note])))
                : new IssueAction('accept', 'Classify and clear', 'primary', [self::select('operational_impact', 'Operational impact', DefectCorrectiveActions::IMPACT_LABELS), $note]),
            $isMeter ? new IssueAction('correct', 'Use a corrected value', 'secondary', [
                ['name' => 'value', 'label' => 'Corrected value', 'type' => 'text', 'required' => true, 'max' => 20],
                $reset,
                $note,
            ]) : null,
            new IssueAction('ask_member', 'Ask the member to clarify', 'secondary', [self::textarea('reason', 'Question for the member', true)]),
            new IssueAction('dismiss', 'Dismiss without changing fleet data', 'danger', [$note]),
        ]));
    }

    // ----------------------------------------------------------------- perform

    public function perform(User $user, string $id, string $action, array $input, string $expectedVersion): ActionResult
    {
        return $this->attempt(function () use ($user, $id, $action, $input, $expectedVersion): ActionResult {
            $exceptionId = self::intId($id);
            $reference = $exceptionId === null ? null : ApparatusInspectionException::query()->with('inspection')->find($exceptionId);
            if (! $reference instanceof ApparatusInspectionException || ! $reference->inspection instanceof ApparatusInspection) {
                return ActionResult::gone();
            }
            if (! Gate::forUser($user)->allows('update', $reference)) {
                return ActionResult::forbidden();
            }
            $decision = match ($action) {
                'accept' => 'accept_submitted',
                'correct' => 'correct',
                'ask_member' => 'request_revision',
                'dismiss' => 'dismiss',
                default => null,
            };
            if ($decision === null) {
                return ActionResult::invalid(['action' => 'That action is not available for this hold.'], 'That action is not available for this hold.');
            }

            return DB::transaction(function () use ($user, $reference, $decision, $input, $expectedVersion): ActionResult {
                // The service's lock order: inspection -> apparatus -> exception.
                ApparatusInspection::query()->lockForUpdate()->findOrFail($reference->apparatus_inspection_id);
                $apparatus = Apparatus::query()->lockForUpdate()->findOrFail($reference->apparatus_id);
                $locked = ApparatusInspectionException::query()->lockForUpdate()->findOrFail($reference->getKey());
                if (in_array($locked->status, self::CLOSED, true)) {
                    return ActionResult::done('This hold was already decided.');
                }
                if ($decision === 'request_revision' && $locked->status === 'revision_requested') {
                    return ActionResult::done('The member has already been asked to clarify.');
                }
                if (! hash_equals(self::versionOf($locked, $apparatus), $expectedVersion)) {
                    throw new StaleVersion;
                }
                $data = ['action' => $decision, 'reason' => self::text($input, 'reason')];
                if (isset(self::METERS[$locked->field])) {
                    $data['expected_current_value'] = $apparatus->getAttribute(self::METERS[$locked->field]);
                    $data['confirm_meter_reset'] = ($input['confirm_meter_reset'] ?? null) === 'yes';
                    if ($decision === 'correct') {
                        $value = self::text($input, 'value');
                        if ($value === null || ! is_numeric($value)) {
                            throw ValidationException::withMessages(['value' => 'Enter the corrected reading as a number.']);
                        }
                        $data['value'] = $value;
                    }
                } elseif ($decision === 'accept_submitted') {
                    $data['operational_impact'] = $input['operational_impact'] ?? null;
                }
                app(ApparatusInspectionExceptionService::class)->reconcile((int) $locked->getKey(), $user, $data);

                return ActionResult::done(match ($decision) {
                    'request_revision' => 'The member was asked to clarify.',
                    'dismiss' => 'Hold dismissed. Fleet data was not changed.',
                    default => 'Decision recorded.',
                });
            }, 3);
        });
    }
}
