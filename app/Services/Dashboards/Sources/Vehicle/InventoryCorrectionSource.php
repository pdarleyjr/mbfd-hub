<?php

declare(strict_types=1);

namespace App\Services\Dashboards\Sources\Vehicle;

use App\Filament\Resources\InventoryCorrectionResource;
use App\Models\ApparatusInventoryCorrection as Correction;
use App\Models\ApparatusInventoryCorrectionEvent as Event;
use App\Models\User;
use App\Services\Dashboards\Support\ApparatusClass;
use App\Services\Dashboards\Support\StaleVersion;
use App\Services\Dashboards\Support\VehicleIssueSource;
use App\Services\Inventory\InventoryChangePlanner;
use App\Services\Inventory\InventoryCorrectionDiff;
use App\Services\Inventory\InventoryCorrectionReason;
use App\Services\Inventory\InventoryCorrectionService;
use App\Support\Dashboards\ActionResult;
use App\Support\Dashboards\DashboardIssue;
use App\Support\Dashboards\IssueAction;
use App\Support\Dashboards\IssueDetail;
use App\Support\Fleet\InventoryCorrectionAccess;
use App\Support\OperationalDisplayWindow;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Member requests to change a vehicle's Daily Checkout equipment list
 * (apparatus_inventory_corrections). The Fleet "Inventory corrections" queue and the admin
 * "Equipment change requests" card read these same records, so this one source covers both.
 * Every decision goes through InventoryCorrectionService.
 *
 * Visibility and actions: InventoryCorrectionAccess::canReview (the daily inspection reviewer
 * rule), exactly as the resource, the card and the service. Never on the wall display: the card
 * names members and is kept off it, so this source is too.
 *
 * Severity: a correction has no priority field and never changes readiness by itself -> low.
 * Stage: submitted (also after the member answers a question) -> awaiting_response;
 * clarification requested (waiting on the member) -> in_progress; applied -> resolved;
 * rejected -> other.
 * Row set: open corrections, plus corrections approved or rejected (decided_at) in the
 * operational day. Source tag "inventory": every correction.
 *
 * Version: "{lock_version}:{last event id}". lock_version is the service's own decision token
 * (passed through as expectedLock); the event id also catches a new message in the thread.
 *
 * "Approve" here is the reviewer's default decision on the full review page: the member's
 * proposal, applied to the reporting apparatus only. Its impact fingerprint is carried in the
 * "Apply to" choice, so a checklist that changed after the panel opened is a conflict, never a
 * silent different change. Anything else (editing the change, other apparatus) stays on the
 * full review page.
 *
 * @extends VehicleIssueSource<Correction>
 */
final class InventoryCorrectionSource extends VehicleIssueSource
{
    private const STAGE = "CASE apparatus_inventory_corrections.status WHEN 'submitted' THEN 'awaiting_response' WHEN 'clarification_requested' THEN 'in_progress'"
        ." WHEN 'applied' THEN 'resolved' ELSE 'other' END";

    private const OCCURRED = "CASE WHEN apparatus_inventory_corrections.status IN ('applied', 'rejected') THEN apparatus_inventory_corrections.decided_at"
        .' ELSE apparatus_inventory_corrections.created_at END';

    private const EVENT_LABELS = [
        Event::SUBMITTED => 'Request submitted',
        Event::CLARIFICATION_REQUESTED => 'Question sent to the member',
        Event::MEMBER_REPLIED => 'Member replied',
        Event::LOGISTICS_REPLIED => 'Logistics replied',
        Event::INTERNAL_NOTE => 'Internal note',
        Event::APPROVED => 'Approved',
        Event::APPLIED => 'Checklist updated',
        Event::REJECTED => 'Rejected',
        Event::REVERSED => 'Change reversed',
        Event::LINKED_TO_INSPECTION => 'Linked to the inspection',
    ];

    public function type(): string
    {
        return 'inventory_correction';
    }

    public function visibleTo(User $user, bool $displayMode): bool
    {
        return ! $displayMode && InventoryCorrectionAccess::canReview($user);
    }

    protected function newQuery(): Builder
    {
        return Correction::query();
    }

    protected function scopeToBoard(Builder $query, OperationalDisplayWindow $window): void
    {
        $query->where(fn (Builder $board) => $board
            ->whereIn('apparatus_inventory_corrections.status', Correction::OPEN_STATUSES)
            ->orWhere(fn (Builder $decided) => $decided
                ->whereIn('apparatus_inventory_corrections.status', [Correction::STATUS_APPLIED, Correction::STATUS_REJECTED])
                ->where('apparatus_inventory_corrections.decided_at', '>=', $window->databaseStart())
                ->where('apparatus_inventory_corrections.decided_at', '<', $window->databaseEnd())));
    }

    protected function severitySql(): string
    {
        return "'low'";
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
        return ['inventory' => '1 = 1'];
    }

    protected function eagerLoads(): array
    {
        return ['apparatus:'.implode(',', ApparatusClass::COLUMNS), 'reporter:id,name,display_name'];
    }

    protected function rowSelects(): array
    {
        return ['dashboard_last_event_id' => Event::query()->selectRaw('MAX(id)')
            ->whereColumn('apparatus_inventory_correction_events.apparatus_inventory_correction_id', 'apparatus_inventory_corrections.id')];
    }

    protected function toIssue(Model $record, bool $displayMode, array $projection): DashboardIssue
    {

        return new DashboardIssue(
            type: $this->type(),
            id: (string) $record->getKey(),
            title: InventoryCorrectionResource::itemLabel($record).' — '.InventoryCorrectionReason::label($record->reason),
            summary: $displayMode ? null : (Str::limit(InventoryCorrectionDiff::summary($record), 280) ?: null),
            severity: $projection['severity'],
            status: (string) $record->status,
            statusLabel: Correction::statusLabel($record->status),
            stage: $projection['stage'],
            tags: $projection['tags'],
            subject: $projection['subject'],
            imageKey: $projection['imageKey'],
            actorLabel: $displayMode ? null : self::personName($record->getRelationValue('reporter')),
            categoryLabel: 'Equipment change request',
            occurredAt: $projection['occurredAt'],
            version: (int) $record->lock_version.':'.(int) $record->getAttribute('dashboard_last_event_id'),
            sourceUrl: InventoryCorrectionResource::getUrl('view', ['record' => $record->getKey()], panel: 'admin'),
        );
    }

    // ------------------------------------------------------------------ detail

    public function detail(User $user, string $id): ?IssueDetail
    {
        if (! $this->visibleTo($user, false) || ($fresh = $this->freshIssue($id)) === null) {
            return null;
        }
        $correction = $fresh['record'];
        assert($correction instanceof Correction);
        $correction->load(['events.actor:id,name,display_name']);

        $facts = array_values(array_filter([
            ['label' => 'Reference', 'value' => $correction->reference()],
            ['label' => 'Apparatus', 'value' => InventoryCorrectionResource::unitLabel($correction)],
            ['label' => 'Item', 'value' => InventoryCorrectionResource::itemLabel($correction)],
            ['label' => 'Reported problem', 'value' => InventoryCorrectionReason::label($correction->reason)],
            ['label' => 'Requested change', 'value' => InventoryCorrectionDiff::summary($correction)],
            ['label' => 'Status', 'value' => Correction::statusLabel($correction->status)],
            ['label' => 'Reported by', 'value' => (string) $fresh['issue']->actorLabel],
            ['label' => 'Reported', 'value' => (string) self::local($correction->created_at)],
        ], static fn (array $fact): bool => $fact['value'] !== ''));

        $timeline = $correction->events->map(static fn (Event $event): array => [
            'at' => CarbonImmutable::parse($event->created_at),
            'actor' => self::personName($event->actor),
            'text' => trim((self::EVENT_LABELS[$event->event_type] ?? str((string) $event->event_type)->headline()->toString())
                .($event->visibility === Event::VISIBILITY_INTERNAL && $event->event_type !== Event::INTERNAL_NOTE ? ' (internal)' : '')
                .(filled($event->body) ? "\n".$event->body : '')),
        ])->values()->all();

        $plan = $correction->isOpen() ? $this->approvalPlan($correction, $user) : null;
        if ($plan !== null) {
            $facts[] = ['label' => 'Approve here changes', 'value' => $plan['label'].' only: '.$plan['verdict']];
        }

        return new IssueDetail(
            issue: $fresh['issue'],
            body: trim((string) $correction->member_note) ?: null,
            facts: $facts,
            timeline: $timeline,
            actions: $this->actionsFor($correction, $plan),
        );
    }

    /**
     * @param  array{fingerprint: string, label: string, verdict: string}|null  $plan
     * @return list<IssueAction>
     */
    private function actionsFor(Correction $correction, ?array $plan): array
    {
        $reply = new IssueAction('reply', 'Reply or add note', 'secondary', [
            self::select('visibility', 'Who can read it', [
                Event::VISIBILITY_MEMBER => 'The member and Logistics (the member is notified)',
                Event::VISIBILITY_INTERNAL => 'Logistics only (internal note)',
            ]),
            self::textarea('body', 'Message', true, InventoryCorrectionService::MAX_MESSAGE),
        ]);
        $optional = [
            self::textarea('member_message', 'Note to the member (optional)', max: InventoryCorrectionService::MAX_MESSAGE),
            self::textarea('internal_note', 'Internal note, Logistics only (optional)', max: InventoryCorrectionService::MAX_MESSAGE),
        ];

        if ($correction->status === Correction::STATUS_APPLIED) {
            return [$reply, new IssueAction('reverse', 'Reverse change', 'danger', [
                self::textarea('reason', 'Reason shared with the member', true, InventoryCorrectionService::MAX_MESSAGE),
            ], 'Every apparatus this correction changed goes back to its previous checklist. Past inspections are not affected.')];
        }
        if (! $correction->isOpen()) {
            return [$reply];
        }

        return array_values(array_filter([
            $plan === null ? null : new IssueAction('approve', 'Approve as requested', 'primary', [
                self::select('apply_to', 'Apply to', [$plan['fingerprint'] => $plan['label'].' only']),
                ...$optional,
            ]),
            new IssueAction('ask_member', 'Ask the member', 'secondary', [
                self::textarea('message', 'Your question', true, InventoryCorrectionService::MAX_MESSAGE),
            ]),
            $reply,
            new IssueAction('reject', 'Reject', 'danger', [
                self::textarea('reason', 'Message to the member (optional)', max: InventoryCorrectionService::MAX_MESSAGE),
                $optional[1],
            ], 'The checklist stays exactly as it is. The member is told it was rejected.'),
        ]));
    }

    /**
     * The member's proposal applied to the reporting apparatus only, when that is a valid,
     * eligible decision right now (otherwise approve on the full review page).
     *
     * @return array{fingerprint: string, label: string, verdict: string, apparatus_ids: list<int>}|null
     */
    private function approvalPlan(Correction $correction, User $user): ?array
    {
        try {
            $plan = app(InventoryCorrectionService::class)->preview(
                (int) $correction->getKey(), $user, InventoryCorrectionReason::suggestedOperation($correction), InventoryChangePlanner::SCOPE_UNIT,
            );
        } catch (Throwable) {
            return null;
        }
        $source = collect($plan['units'])->firstWhere('is_source', true);
        if (! is_array($source) || ($source['default_selected'] ?? false) !== true) {
            return null;
        }

        return [
            'fingerprint' => (string) $plan['fingerprint'],
            'label' => (string) $source['label'],
            'verdict' => (string) $source['verdict_label'],
            'apparatus_ids' => [(int) $source['apparatus_id']],
        ];
    }

    // ----------------------------------------------------------------- perform

    public function perform(User $user, string $id, string $action, array $input, string $expectedVersion): ActionResult
    {
        return $this->attempt(function () use ($user, $id, $action, $input, $expectedVersion): ActionResult {
            $correctionId = self::intId($id);
            if ($correctionId === null || ! Correction::query()->whereKey($correctionId)->exists()) {
                return ActionResult::gone();
            }
            if (! InventoryCorrectionAccess::canReview($user)) {
                return ActionResult::forbidden();
            }
            if (! in_array($action, ['approve', 'ask_member', 'reply', 'reject', 'reverse'], true)) {
                return ActionResult::invalid(['action' => 'That action is not available for this request.'], 'That action is not available for this request.');
            }

            // Lock order matches the service: the correction row first.
            return DB::transaction(function () use ($user, $correctionId, $action, $input, $expectedVersion): ActionResult {
                $locked = Correction::query()->lockForUpdate()->findOrFail($correctionId);
                $already = match ($action) {
                    'approve' => $locked->status === Correction::STATUS_APPLIED ? 'Already approved.' : null,
                    'reject' => $locked->status === Correction::STATUS_REJECTED ? 'Already rejected.' : null,
                    'reverse' => $locked->status === Correction::STATUS_REVERSED ? 'Already reversed.' : null,
                    default => null,
                };
                if ($already !== null) {
                    return ActionResult::done($already);
                }
                $lastEvent = (int) Event::query()->where('apparatus_inventory_correction_id', $correctionId)->max('id');
                if (! hash_equals((int) $locked->lock_version.':'.$lastEvent, $expectedVersion)) {
                    throw new StaleVersion;
                }

                return $this->decide($locked, $user, $action, $input);
            });
        });
    }

    /** @param array<string, mixed> $input */
    private function decide(Correction $correction, User $user, string $action, array $input): ActionResult
    {
        $service = app(InventoryCorrectionService::class);
        $id = (int) $correction->getKey();
        $lock = (int) $correction->lock_version;
        $key = (string) Str::uuid();

        switch ($action) {
            case 'approve':
                $operation = InventoryCorrectionReason::suggestedOperation($correction);
                $plan = $service->preview($id, $user, $operation, InventoryChangePlanner::SCOPE_UNIT);
                if (! hash_equals((string) $plan['fingerprint'], (string) ($input['apply_to'] ?? ''))) {
                    throw new StaleVersion;
                }
                $selected = collect($plan['units'])->where('is_source', true)->where('default_selected', true)->pluck('apparatus_id')->all();
                if ($selected === []) {
                    return ActionResult::invalid(['apply_to' => 'This change cannot be applied to this apparatus as requested. Open the full review to decide it.']);
                }
                $service->approve($id, $user, $operation, InventoryChangePlanner::SCOPE_UNIT, $selected, (string) $plan['fingerprint'], $lock, $key,
                    self::text($input, 'member_message'), self::text($input, 'internal_note'));

                return ActionResult::done('Approved. The next checklist for this apparatus includes this change.');
            case 'ask_member':
                $service->requestClarification($id, $user, (string) self::text($input, 'message'), $lock, $key);

                return ActionResult::done('Question sent to the member.');
            case 'reply':
                $visibility = ($input['visibility'] ?? null) === Event::VISIBILITY_INTERNAL ? Event::VISIBILITY_INTERNAL : Event::VISIBILITY_MEMBER;
                $service->postMessage($id, $user, (string) self::text($input, 'body'), $visibility, $key);

                return ActionResult::done($visibility === Event::VISIBILITY_INTERNAL ? 'Internal note saved.' : 'Message sent to the member.');
            case 'reject':
                $service->reject($id, $user, self::text($input, 'reason'), self::text($input, 'internal_note'), $lock, $key);

                return ActionResult::done('Request rejected. The checklist was not changed and the member was told.');
            default: // reverse
                $service->reverse($id, $user, (string) self::text($input, 'reason'), $lock, $key);

                return ActionResult::done('Change reversed.');
        }
    }
}
