<?php

declare(strict_types=1);

namespace App\Services\Dashboards\Sources\Vehicle;

use App\Filament\Resources\DefectResource;
use App\Filament\Support\DefectCorrectiveActions;
use App\Filament\Support\InspectionWorkspace;
use App\Models\ApparatusDefect;
use App\Models\ApparatusDefectEvent;
use App\Models\SupportTriage\SupportTriageLevel;
use App\Models\User;
use App\Services\Dashboards\Support\ApparatusClass;
use App\Services\Dashboards\Support\VehicleIssueSource;
use App\Services\Defects\DefectCorrectiveActionService;
use App\Services\Defects\DefectOwnership;
use App\Services\SupportTriage\TriageProjector;
use App\Services\SupportTriage\TriageSql;
use App\Support\Captain5\EventActorLabel;
use App\Support\Dashboards\ActionResult;
use App\Support\Dashboards\DashboardIssue;
use App\Support\Dashboards\IssueAction;
use App\Support\Dashboards\IssueDetail;
use App\Support\Fleet\DefectActionStatus;
use App\Support\Fleet\DefectEventNarrative;
use App\Support\Fleet\DefectFollowUpState;
use App\Support\Fleet\DefectNoActionClosure;
use App\Support\Fleet\DefectResolutionLine;
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
 * Severity is the Low/Medium/High priority from the equipment catalog (support_triage_levels, which a staff member may
 * override): high whenever severity = significant or operational_impact = out_of_service, otherwise the projected
 * level. Rows not projected yet fall back to the older rule so nothing disappears before the first backfill:
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
    private const SEVERITY_FALLBACK = "CASE WHEN apparatus_defects.issue_type IN ('missing', 'damaged') OR apparatus_defects.operational_impact = 'needs_repair' THEN 'medium' ELSE 'low' END";

    /** Staff may change the priority an equipment issue shows; "auto" goes back to the equipment priorities. */
    private const PRIORITY_CHOICES = ['low' => 'Low', 'medium' => 'Medium', 'high' => 'High', 'auto' => 'Use the equipment priority'];

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
        // One responsible queue: Captain 5's issues are worked in the Captain 5 workspace, not here. They stay in the
        // apparatus, inspection and Defects history, and Latest Activity shows them read-only.
        $query->where(fn (Builder $owner) => $owner->whereNull('apparatus_defects.responsible_owner')
            ->orWhere('apparatus_defects.responsible_owner', '<>', ApparatusDefect::OWNER_CAPTAIN5)
            // Cleared to Logistics by the previous image and not adopted yet: Logistics' work, never in neither queue.
            ->orWhere(fn (Builder $clear) => DefectOwnership::whereUnadoptedClear($clear, 'apparatus_defects')));
        // Open issues, plus fixed ones for dashboards.resolved_display_hours unless staff cleared them (the resolution line
        // on the row says how it ended). An issue closed with no action required is not a "resolved" item: it keeps the
        // operational-day rule, and a clear takes it off early as well.
        $query->where(fn (Builder $board) => $board
            ->where('apparatus_defects.resolved', false)
            ->orWhere(fn (Builder $fixed) => (new ApparatusDefect)->scopeRecentlyResolved($fixed, $window->now))
            ->orWhere(fn (Builder $closed) => $closed
                ->where('apparatus_defects.action_status', DefectActionStatus::DISMISSED)
                ->whereNull('apparatus_defects.resolution_cleared_at')
                ->where('apparatus_defects.resolved_at', '>=', $window->databaseStart())
                ->where('apparatus_defects.resolved_at', '<', $window->databaseEnd())));
    }

    protected function resolvedTodaySql(OperationalDisplayWindow $window): string
    {
        return 'apparatus_defects.resolved_at >= \''.$window->databaseStart()->format('Y-m-d H:i:s').'\' AND apparatus_defects.resolved_at < \''.$window->databaseEnd()->format('Y-m-d H:i:s').'\'';
    }

    protected function severitySql(): string
    {
        return TriageSql::defectLevel(self::SEVERITY_FALLBACK);
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
        return ['apparatus:'.implode(',', ApparatusClass::COLUMNS), 'firstReporter:id,name,display_name', 'resolvedBy:id,name,display_name'];
    }

    protected function rowSelects(): array
    {
        return [
            'dashboard_last_event_id' => ApparatusDefectEvent::query()->changingTheIssue()->selectRaw('MAX(id)')
                ->whereColumn('apparatus_defect_events.apparatus_defect_id', 'apparatus_defects.id'),
            // How it was closed, for the resolution line on a resolved row.
            'dashboard_closing_event' => ApparatusDefectEvent::query()->select('event_type')
                ->whereColumn('apparatus_defect_events.apparatus_defect_id', 'apparatus_defects.id')
                ->whereIn('event_type', DefectResolutionLine::CLOSING_EVENTS)->orderByDesc('id')->limit(1),
        ];
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
            summary: implode(' — ', array_filter([
                DefectResolutionLine::forRow($record, $record->getAttribute('dashboard_closing_event')),
                trim((string) $record->compartment) ?: null,
                $notes,
            ])) ?: null,
            severity: $projection['severity'],
            status: $status,
            statusLabel: DefectActionStatus::labelFor($status, $record->resolution_type),
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
            ['label' => 'Priority', 'value' => $this->priorityLabel($defect, (string) $fresh['issue']->severity)],
            ['label' => 'Severity', 'value' => DefectSeverityCorrection::label($defect->severity)],
            ['label' => 'Operational impact', 'value' => DefectCorrectiveActions::IMPACT_LABELS[(string) $defect->operational_impact] ?? 'Not classified yet'],
            ['label' => 'Status', 'value' => DefectActionStatus::labelFor($defect->action_status, $defect->resolution_type)],
            ['label' => 'Merged into', 'value' => $defect->duplicate_of_defect_id !== null ? 'Issue #'.$defect->duplicate_of_defect_id : ''],
            ['label' => 'Follow-up', 'value' => DefectFollowUpState::label(DefectFollowUpState::for($defect))],
            ['label' => 'First reported', 'value' => (string) self::local($defect->first_reported_at ?? $defect->created_at)],
            ['label' => 'Reported by', 'value' => (string) $fresh['issue']->actorLabel],
            ['label' => 'Service ticket', 'value' => (string) $defect->serviceTicket?->ticket_number],
            ['label' => 'Assigned to', 'value' => $this->assigneeLabel($details['assigned_to'] ?? null)],
            ['label' => 'Needs verification', 'value' => $this->memberReportLabel($defect)],
            ['label' => 'How it ended', 'value' => (string) (DefectResolutionLine::forDefects([$defect])[(int) $defect->getKey()]['text'] ?? '')],
            ['label' => 'Handled by', 'value' => DefectOwnership::isCaptain5Owned($defect) ? 'Captain 5' : 'Support Services (Logistics)'],
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
        // A merged duplicate lives on in the issue it was merged into; reopening it would split that issue again.
        if ($defect->isMergedDuplicate()) {
            return [];
        }
        if (DefectActionStatus::isClosed($status)) {
            return array_values(array_filter([
                $defect->resolution_cleared_at === null ? new IssueAction('clear_resolution', 'Clear from list', 'secondary', []) : null,
                new IssueAction('reopen', 'Reopen', 'secondary', [self::textarea('reason', 'Reason for reopening', true, 5000), ...$messages]),
            ]));
        }
        if (DefectOwnership::isCaptain5Owned($defect)) {
            if ((string) $defect->compartment_key === ApparatusDefect::STRETCHER_REPORT_COMPARTMENT) {
                return []; // stretcher reports always stay with Captain 5; DefectOwnership::transfer refuses the move
            }

            // Captain 5 works it; from here Logistics can only take it over (one queue per issue).
            return [new IssueAction('take_for_logistics', 'Take for Logistics', 'secondary', [
                self::textarea('internal_note', 'Why Logistics should handle it (staff only, optional)', max: 4000),
            ])];
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
            $this->memberReportLabel($defect) !== ''
                ? new IssueAction('verify', 'Verify correction', 'primary', [self::textarea('notes', 'Notes (optional)', max: 5000), ...$messages])
                : null,
            new IssueAction('send_to_captain5', 'Send to Captain 5', 'secondary', [
                self::textarea('internal_note', 'Why Captain 5 should handle it (staff only, optional)', max: 4000),
            ]),
            new IssueAction('classify_impact', 'Classify impact', 'secondary', [self::select('operational_impact', 'Operational impact', DefectCorrectiveActions::IMPACT_LABELS), ...$messages]),
            new IssueAction('set_priority', 'Change priority', 'secondary', [self::select('level', 'Priority', self::PRIORITY_CHOICES)]),
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

    /** What a member last said about the item being back: a checkout's Present, or a "Report fixed" (newest wins). */
    private function memberReportLabel(ApparatusDefect $defect): string
    {
        $observation = $defect->latestObservation;
        $reported = $defect->correction_reported_at;
        if ($reported !== null && ($observation === null || $reported->greaterThanOrEqualTo($observation->created_at))) {
            return 'Needs verification: a member reported it fixed. Verify before closing.';
        }

        return $observation?->observation === 'appears_corrected' ? 'Needs verification: the latest checkout reported the item present' : '';
    }

    /** "Medium (set by equipment priorities)" or "High (changed by Pat Smith)". */
    private function priorityLabel(ApparatusDefect $defect, string $fallback): string
    {
        $row = SupportTriageLevel::query()->where('subject_type', TriageProjector::DEFECT)->where('subject_id', $defect->getKey())
            ->with('overriddenBy:id,name,display_name')->first();
        $label = ucfirst($row instanceof SupportTriageLevel ? $row->level : $fallback);
        if ($defect->severity === 'significant' || $defect->operational_impact === 'out_of_service') {
            return $label.' (issue is flagged significant or out of service)';
        }
        if ($row instanceof SupportTriageLevel && $row->override_level !== null) {
            return $label.' (changed by '.(self::personName($row->overriddenBy) ?: 'staff').')';
        }

        return $label.' (set by equipment priorities)';
    }

    private function setPriority(ApparatusDefect $defect, User $user, array $input): ActionResult
    {
        $choice = is_string($input['level'] ?? null) ? $input['level'] : '';
        if (! array_key_exists($choice, self::PRIORITY_CHOICES)) {
            return ActionResult::invalid(['level' => 'Choose Low, Medium or High.'], 'Choose Low, Medium or High.');
        }
        app(TriageProjector::class)->setOverride(TriageProjector::DEFECT, (int) $defect->getKey(), $choice === 'auto' ? null : $choice, $user);

        return ActionResult::done($choice === 'auto' ? 'Priority set back to the equipment priority.' : 'Priority saved.');
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
            if (DefectOwnership::isCaptain5Owned($defect) && ! DefectActionStatus::isClosed($defect->action_status) && ! in_array($action, ['take_for_logistics', 'reopen'], true)) {
                return ActionResult::invalid(['action' => 'Captain 5 handles this issue. Take it for Logistics first.'], 'Captain 5 handles this issue. Take it for Logistics first.');
            }
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
                'set_priority' => $this->setPriority($defect, $user, $input),
                'take_for_logistics' => $this->done(app(DefectOwnership::class)->transfer(
                    $user,
                    $key,
                    ApparatusDefect::OWNER_LOGISTICS,
                    $expectedVersion,
                    substr(hash('sha256', $expectedVersion), 0, 32),
                    $messages['internal_note'],
                    ['role' => 'fleet_reviewer'],
                ), 'Taken for Logistics. It is in this queue now.'),
                'send_to_captain5' => $this->done(app(DefectOwnership::class)->transfer(
                    $user,
                    $key,
                    ApparatusDefect::OWNER_CAPTAIN5,
                    $expectedVersion,
                    substr(hash('sha256', $expectedVersion), 0, 32),
                    $messages['internal_note'],
                    ['role' => 'fleet_reviewer'],
                ), 'Sent to Captain 5. It is in the Captain 5 queue now.'),
                'classify_impact' => $this->done($service->classifyImpact($key, $user, ['operational_impact' => $input['operational_impact'] ?? null, ...$messages]), 'Operational impact saved.'),
                'resolve' => $this->done($service->resolve($key, $user, [
                    'resolution_type' => $input['resolution_type'] ?? null,
                    'resolution_notes' => self::text($input, 'resolution_notes'),
                    ...$messages,
                ]), 'Issue resolved.'),
                'close_no_action' => $this->done($service->dismiss($key, $user, ['reason' => self::text($input, 'reason'), ...$messages]), DefectNoActionClosure::SUCCESS),
                'clear_resolution' => $this->done($service->clearResolution($key, $user, [], substr(hash('sha256', 'clear:'.$key.':'.$expectedVersion), 0, 32)), 'Cleared from the list. The resolution stays in the history.'),
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
            $action === 'clear_resolution' && ($defect->resolution_cleared_at !== null || ! DefectActionStatus::isClosed($status)) => 'Already cleared.',
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
