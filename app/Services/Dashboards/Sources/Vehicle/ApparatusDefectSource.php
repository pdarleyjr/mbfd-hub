<?php

declare(strict_types=1);

namespace App\Services\Dashboards\Sources\Vehicle;

use App\Filament\Resources\DefectResource;
use App\Filament\Support\DefectCorrectiveActions;
use App\Filament\Support\InspectionWorkspace;
use App\Models\ApparatusDefect;
use App\Models\ApparatusDefectEvent;
use App\Models\User;
use App\Services\Dashboards\Support\ApparatusClass;
use App\Services\Dashboards\Support\VehicleIssueSource;
use App\Services\Defects\DefectCorrectiveActionService;
use App\Support\Captain5\EventActorLabel;
use App\Support\Dashboards\ActionResult;
use App\Support\Dashboards\DashboardIssue;
use App\Support\Dashboards\IssueAction;
use App\Support\Dashboards\IssueDetail;
use App\Support\Fleet\DefectActionStatus;
use App\Support\Fleet\DefectEventNarrative;
use App\Support\Fleet\DefectFollowUpState;
use App\Support\Fleet\DefectNoActionClosure;
use App\Support\Fleet\DefectSeverityCorrection;
use App\Support\Fleet\InspectionReviewers;
use App\Support\OperationalDisplayWindow;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Equipment issues reported in Daily Checkout (apparatus_defects), answered through
 * DefectCorrectiveActionService exactly as the inspection page and the Defects list do.
 *
 * Visibility: the DefectResource rule (policy viewAny). Actions: manageCorrectiveAction
 * (InspectionReviewers), enforced again inside the service.
 *
 * Severity (from the record's own fields):
 *  - high:   severity = significant, or operational_impact = out_of_service;
 *  - medium: issue_type missing or damaged, or operational_impact = needs_repair;
 *  - low:    everything else (expired, low quantity, other).
 * Stage (from action_status, the lifecycle source of truth): reported -> awaiting_response;
 * acknowledged / action pending / replacement available / repair scheduled -> in_progress;
 * resolved -> resolved; dismissed (closed, no action required) -> other.
 * Row set: unresolved issues, plus issues whose resolved_at falls in the operational day.
 * Source tag "inventory": issue_type missing or low_quantity.
 *
 * Version: the service's own optimistic token "{action_status}:{last event id}"; perform()
 * hands it to DefectCorrectiveActionService::expectingState(), which compares it under the
 * service's row locks (apparatus -> defect, the global lock order) before writing anything.
 *
 * @extends VehicleIssueSource<ApparatusDefect>
 */
final class ApparatusDefectSource extends VehicleIssueSource
{
    private const SEVERITY = "CASE WHEN apparatus_defects.severity = 'significant' OR apparatus_defects.operational_impact = 'out_of_service' THEN 'high'"
        ." WHEN apparatus_defects.issue_type IN ('missing', 'damaged') OR apparatus_defects.operational_impact = 'needs_repair' THEN 'medium' ELSE 'low' END";

    private const STAGE = "CASE WHEN apparatus_defects.action_status IS NULL OR apparatus_defects.action_status = 'reported' THEN 'awaiting_response'"
        ." WHEN apparatus_defects.action_status = 'resolved' THEN 'resolved' WHEN apparatus_defects.action_status = 'dismissed' THEN 'other' ELSE 'in_progress' END";

    private const OCCURRED = "CASE WHEN apparatus_defects.action_status IN ('resolved', 'dismissed') THEN apparatus_defects.resolved_at"
        .' ELSE COALESCE(apparatus_defects.first_reported_at, apparatus_defects.created_at) END';

    public function type(): string
    {
        return 'apparatus_defect';
    }

    public function visibleTo(User $user, bool $displayMode): bool
    {
        return Gate::forUser($user)->allows('viewAny', ApparatusDefect::class);
    }

    protected function newQuery(): Builder
    {
        return ApparatusDefect::query();
    }

    protected function scopeToBoard(Builder $query, OperationalDisplayWindow $window): void
    {
        $query->where(fn (Builder $board) => $board
            ->where('apparatus_defects.resolved', false)
            ->orWhere(fn (Builder $closed) => $closed
                ->whereIn('apparatus_defects.action_status', DefectActionStatus::CLOSED)
                ->where('apparatus_defects.resolved_at', '>=', $window->databaseStart())
                ->where('apparatus_defects.resolved_at', '<', $window->databaseEnd())));
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

    protected function sourceTagSql(): array
    {
        return ['inventory' => "apparatus_defects.issue_type IN ('missing', 'low_quantity')"];
    }

    protected function eagerLoads(): array
    {
        return ['apparatus:'.implode(',', ApparatusClass::COLUMNS), 'firstReporter:id,name,display_name'];
    }

    protected function rowSelects(): array
    {
        return ['dashboard_last_event_id' => ApparatusDefectEvent::query()->selectRaw('MAX(id)')
            ->whereColumn('apparatus_defect_events.apparatus_defect_id', 'apparatus_defects.id')];
    }

    protected function toIssue(Model $record, bool $displayMode, array $projection): DashboardIssue
    {
        $status = (string) ($record->action_status ?: DefectActionStatus::REPORTED);
        $problem = str((string) $record->issue_type)->replace('_', ' ')->headline()->toString();
        $notes = $displayMode ? null : (trim((string) $record->notes) ?: null);

        return new DashboardIssue(
            type: $this->type(),
            id: (string) $record->getKey(),
            title: trim($problem.': '.$record->item, ': '),
            summary: implode(' — ', array_filter([trim((string) $record->compartment) ?: null, $notes])) ?: null,
            severity: $projection['severity'],
            status: $status,
            statusLabel: DefectActionStatus::label($status),
            stage: $projection['stage'],
            tags: $projection['tags'],
            subject: $projection['subject'],
            imageKey: $projection['imageKey'],
            actorLabel: $displayMode ? null : self::personName($record->getRelationValue('firstReporter')),
            categoryLabel: 'Equipment issue',
            occurredAt: $projection['occurredAt'],
            version: $status.':'.(int) $record->getAttribute('dashboard_last_event_id'),
            sourceUrl: self::sourceUrl($record),
        );
    }

    // ------------------------------------------------------------------ detail

    public function detail(User $user, string $id): ?IssueDetail
    {
        if (! $this->visibleTo($user, false) || ($fresh = $this->freshIssue($id)) === null) {
            return null;
        }
        $defect = $fresh['record'];
        assert($defect instanceof ApparatusDefect);
        if (! Gate::forUser($user)->allows('view', $defect)) {
            return null;
        }
        $defect->load(['serviceTicket:id,ticket_number,status,archived_at', 'latestObservation', 'events.actor:id,name,display_name']);
        $manage = DefectCorrectiveActions::canManage($user);
        $details = is_array($defect->action_details) ? $defect->action_details : [];

        $facts = array_values(array_filter([
            ['label' => 'Apparatus', 'value' => (string) ($fresh['issue']->subject ?? 'Apparatus')],
            ['label' => 'Where', 'value' => trim((string) $defect->compartment)],
            ['label' => 'Item', 'value' => trim((string) $defect->item)],
            ['label' => 'Problem', 'value' => str((string) $defect->issue_type)->replace('_', ' ')->headline()->toString()],
            ['label' => 'Severity', 'value' => DefectSeverityCorrection::label($defect->severity)],
            ['label' => 'Operational impact', 'value' => DefectCorrectiveActions::IMPACT_LABELS[(string) $defect->operational_impact] ?? 'Not classified yet'],
            ['label' => 'Status', 'value' => DefectActionStatus::label($defect->action_status)],
            ['label' => 'Follow-up', 'value' => DefectFollowUpState::label(DefectFollowUpState::for($defect))],
            ['label' => 'First reported', 'value' => (string) self::local($defect->first_reported_at ?? $defect->created_at)],
            ['label' => 'Reported by', 'value' => (string) $fresh['issue']->actorLabel],
            ['label' => 'Service ticket', 'value' => (string) $defect->serviceTicket?->ticket_number],
            ['label' => 'Assigned to', 'value' => $this->assigneeLabel($details['assigned_to'] ?? null)],
            ['label' => 'Member reports', 'value' => $defect->latestObservation?->observation === 'appears_corrected' ? 'The latest checkout says the item is present again' : ''],
        ], static fn (array $fact): bool => $fact['value'] !== ''));

        $timeline = $defect->events->map(static fn (ApparatusDefectEvent $event): array => [
            'at' => CarbonImmutable::parse($event->created_at),
            'actor' => EventActorLabel::for($event, self::personName($event->actor)),
            'text' => implode("\n", array_filter([
                DefectEventNarrative::label((string) $event->event_type),
                ...DefectEventNarrative::lines($event),
                filled($event->member_message) ? 'Message to the member: '.$event->member_message : null,
                $manage && filled($event->internal_note) ? 'Internal note: '.$event->internal_note : null,
            ])),
        ])->values()->all();

        $actions = $manage ? $this->actionsFor($defect) : [];

        return new IssueDetail(
            issue: $fresh['issue'],
            body: trim((string) $defect->notes) ?: null,
            facts: $facts,
            timeline: $timeline,
            actions: $actions,
            readOnlyReason: $manage ? null : 'Only Logistics reviewers can act on equipment issues. You can see it here; ask Logistics to update it.',
        );
    }

    /** @return list<IssueAction> */
    private function actionsFor(ApparatusDefect $defect): array
    {
        $status = (string) ($defect->action_status ?: DefectActionStatus::REPORTED);
        $messages = [
            self::textarea('member_message', 'Message to the member (optional)'),
            self::textarea('internal_note', 'Internal note, staff only (optional)', max: 4000),
        ];
        if (DefectActionStatus::isClosed($status)) {
            return [new IssueAction('reopen', 'Reopen', 'secondary', [self::textarea('reason', 'Reason for reopening', true, 5000), ...$messages])];
        }

        $progress = array_combine(
            DefectCorrectiveActionService::ACTION_STATUSES,
            array_map(static fn (string $state): string => DefectActionStatus::label($state), DefectCorrectiveActionService::ACTION_STATUSES),
        );
        $staff = [];
        foreach (InspectionReviewers::all() as $reviewer) {
            $staff[(int) $reviewer->getKey()] = (string) self::personName($reviewer);
        }

        return array_values(array_filter([
            $status === DefectActionStatus::REPORTED
                ? new IssueAction('acknowledge', 'Acknowledge', 'primary', $messages, bulk: IssueAction::BULK_DIRECT)
                : new IssueAction('add_note', 'Add note or message', 'secondary', $messages, bulk: IssueAction::BULK_FORM),
            new IssueAction('set_progress', 'Update progress', 'secondary', [self::select('action_status', 'Progress', $progress), ...$messages], bulk: IssueAction::BULK_FORM),
            $staff === [] ? null : new IssueAction('assign', 'Assign', 'secondary', [self::select('assignee_id', 'Assign to', $staff), ...$messages], bulk: IssueAction::BULK_FORM),
            $defect->latestObservation?->observation === 'appears_corrected'
                ? new IssueAction('verify', 'Verify correction', 'primary', [self::textarea('notes', 'Notes (optional)', max: 5000), ...$messages])
                : null,
            new IssueAction('classify_impact', 'Classify impact', 'secondary', [self::select('operational_impact', 'Operational impact', DefectCorrectiveActions::IMPACT_LABELS), ...$messages]),
            new IssueAction('resolve', 'Resolve', 'primary', [
                self::select('resolution_type', 'How was it resolved?', DefectCorrectiveActions::RESOLUTION_OPTIONS),
                self::textarea('resolution_notes', 'Resolution notes', true, 5000),
                ...$messages,
            ], bulk: IssueAction::BULK_FORM),
            DefectCorrectiveActions::closeBlockedReason($defect) === null
                ? new IssueAction('close_no_action', DefectNoActionClosure::ACTION_LABEL, 'danger', [
                    self::textarea('reason', DefectNoActionClosure::REASON_LABEL, true, 5000),
                    ...$messages,
                ], DefectNoActionClosure::DIALOG_TEXT, IssueAction::BULK_FORM)
                : null,
        ]));
    }

    private function assigneeLabel(mixed $assigned): string
    {
        if (is_int($assigned)) {
            return (string) self::personName(User::query()->find($assigned, ['id', 'name', 'display_name']));
        }

        return is_string($assigned) ? trim($assigned) : '';
    }

    // ----------------------------------------------------------------- perform

    public function perform(User $user, string $id, string $action, array $input, string $expectedVersion): ActionResult
    {
        return $this->attempt(function () use ($user, $id, $action, $input, $expectedVersion): ActionResult {
            $defectId = self::intId($id);
            $defect = $defectId === null ? null : ApparatusDefect::query()->find($defectId);
            if (! $defect instanceof ApparatusDefect) {
                return ActionResult::gone();
            }
            if (! Gate::forUser($user)->allows('view', $defect) || ! DefectCorrectiveActions::canManage($user)) {
                return ActionResult::forbidden();
            }
            $messages = ['member_message' => self::text($input, 'member_message'), 'internal_note' => self::text($input, 'internal_note')];
            $quiet = $messages['member_message'] === null && $messages['internal_note'] === null;

            $already = $this->alreadyInEffect($defect, $action, $input, $quiet);
            if ($already !== null) {
                return ActionResult::done($already);
            }
            if (trim($expectedVersion) === '') {
                return ActionResult::conflict();
            }

            $service = app(DefectCorrectiveActionService::class)->expectingState($expectedVersion);
            $key = (int) $defect->getKey();

            return match ($action) {
                'acknowledge', 'add_note' => $this->done($service->acknowledge($key, $user, $messages), $action === 'acknowledge' ? 'Issue acknowledged.' : 'Update saved.'),
                'set_progress' => $this->done($service->updateAction($key, $user, ['action_status' => $input['action_status'] ?? null, ...$messages]), 'Progress updated.'),
                'assign' => $this->done($service->updateAction($key, $user, [
                    'action_status' => in_array($defect->action_status, DefectCorrectiveActionService::ACTION_STATUSES, true) ? $defect->action_status : DefectActionStatus::ACTION_PENDING,
                    'details' => ['assigned_to' => $this->eligibleAssignee($input['assignee_id'] ?? null)],
                    ...$messages,
                ]), 'Issue assigned.'),
                'verify' => $this->done($service->verifyCorrection($key, $user, ['notes' => self::text($input, 'notes'), ...$messages]), 'Correction verified and issue resolved.'),
                'classify_impact' => $this->done($service->classifyImpact($key, $user, ['operational_impact' => $input['operational_impact'] ?? null, ...$messages]), 'Operational impact saved.'),
                'resolve' => $this->done($service->resolve($key, $user, [
                    'resolution_type' => $input['resolution_type'] ?? null,
                    'resolution_notes' => self::text($input, 'resolution_notes'),
                    ...$messages,
                ]), 'Issue resolved.'),
                'close_no_action' => $this->done($service->dismiss($key, $user, ['reason' => self::text($input, 'reason'), ...$messages]), DefectNoActionClosure::SUCCESS),
                'reopen' => $this->done($service->reopen($key, $user, ['reason' => self::text($input, 'reason'), ...$messages]), 'Issue reopened.'),
                default => ActionResult::invalid(['action' => 'That action is not available for this issue.'], 'That action is not available for this issue.'),
            };
        });
    }

    /** The message when repeating $action would change nothing, else null. */
    private function alreadyInEffect(ApparatusDefect $defect, string $action, array $input, bool $quiet): ?string
    {
        $status = (string) ($defect->action_status ?: DefectActionStatus::REPORTED);
        $details = is_array($defect->action_details) ? $defect->action_details : [];
        // A closing or reopening action with different typed text goes on to the version check, so it is never lost.
        $stored = $defect->resolution_notes;
        $nothingNew = $quiet
            && in_array(self::text($input, 'resolution_notes'), [null, $stored], true)
            && in_array(self::text($input, 'reason'), [null, $stored], true);

        return match (true) {
            $action === 'acknowledge' && $quiet && $status !== DefectActionStatus::REPORTED && ! DefectActionStatus::isClosed($status) => 'Already acknowledged.',
            $action === 'set_progress' && $quiet && $status === ($input['action_status'] ?? null) => 'Progress is already set to that.',
            $action === 'assign' && $quiet && in_array($status, DefectCorrectiveActionService::ACTION_STATUSES, true)
                && is_int($details['assigned_to'] ?? null) && (string) $details['assigned_to'] === (string) ($input['assignee_id'] ?? '') => 'Already assigned to that person.',
            $action === 'classify_impact' && $quiet && ($input['operational_impact'] ?? null) !== 'out_of_service'
                && $defect->operational_impact === ($input['operational_impact'] ?? null) && ! DefectActionStatus::isClosed($status) => 'Impact is already classified that way.',
            in_array($action, ['resolve', 'verify'], true) && $nothingNew && $status === DefectActionStatus::RESOLVED => 'Already resolved.',
            $action === 'close_no_action' && $nothingNew && $status === DefectActionStatus::DISMISSED => 'Already closed with no action required.',
            $action === 'reopen' && $nothingNew && ! DefectActionStatus::isClosed($status) => 'Already open.',
            default => null,
        };
    }

    /** Only someone offered in the Assign list (an active Logistics reviewer) can be chosen. */
    private function eligibleAssignee(mixed $value): int
    {
        $id = is_scalar($value) ? self::intId((string) $value) : null;
        $eligible = $id !== null && InspectionReviewers::all()->contains(static fn (User $reviewer): bool => (int) $reviewer->getKey() === $id);
        if (! $eligible) {
            throw ValidationException::withMessages(['assignee_id' => 'Choose someone from the list.']);
        }

        return $id;
    }

    private function done(ApparatusDefect $defect, string $message): ActionResult
    {
        return ActionResult::done($message);
    }

    private static function sourceUrl(ApparatusDefect $defect): string
    {
        $inspectionId = $defect->first_reported_inspection_id ?? $defect->apparatus_inspection_id ?? $defect->last_observed_inspection_id;

        return $inspectionId !== null
            ? InspectionWorkspace::url((int) $inspectionId, 'issue-'.$defect->getKey())
            : DefectResource::getUrl('edit', ['record' => $defect->getKey()], panel: 'admin');
    }
}
