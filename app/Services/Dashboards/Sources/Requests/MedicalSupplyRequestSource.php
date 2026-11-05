<?php

declare(strict_types=1);

namespace App\Services\Dashboards\Sources\Requests;

use App\Filament\Resources\InventoryRequestResource;
use App\Models\Ems\InventoryRequest;
use App\Models\Ems\InventoryRequestEvent;
use App\Models\Ems\InventoryRequestLine;
use App\Models\Ems\InventoryRequestMessage;
use App\Models\User;
use App\Services\Dashboards\Support\RecentlyClosedSource;
use App\Services\Ems\EmsAccess;
use App\Services\Ems\EmsException;
use App\Services\Ems\RequestWorkflowService;
use App\Support\Dashboards\ActionResult;
use App\Support\Dashboards\DashboardIssue;
use App\Support\Dashboards\DashboardView;
use App\Support\Dashboards\IssueAction;
use App\Support\Dashboards\IssueDetail;
use App\Support\MemberReplies\ReplySubject;
use App\Support\OperationalDisplayWindow;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Medical Inventory Requests (ems_inventory_requests): a member's request to restock a rescue unit or an event bag,
 * worked by Captain 5 / EMS supply staff. Acknowledge and "send back to the member" go through
 * RequestWorkflowService exactly as the Captain 5 page does (same idempotency table, events and access rule).
 * Reconciling against the supply room is a batch on the Medical Supply Room page, so it is not offered here.
 *
 * Visibility: EmsAccess::canView (Captain 5 or Admin -> EMS view/manage); actions: EmsAccess::canAct.
 * Never on the wall display: rows name members.
 *
 * Open: submitted, reviewed, prepared, returned (InventoryRequest::ACTIVE_STATUSES). Closed and shown for the
 * retention window: reconciled, closed, cancelled. A superseded form (the member sent a corrected revision) and the
 * older Issue workflow's issued rows are not shown; the revision is the live record.
 * Stage: submitted and not yet acknowledged -> awaiting_response; other open -> in_progress; closed -> resolved.
 * Severity: medium (a unit restock; there is no priority on the form). Tags: medical, waiting_member (returned),
 * awaiting_pickup (prepared), member_replied (the newest message on the form is the member's).
 *
 * @extends RecentlyClosedSource<InventoryRequest>
 */
final class MedicalSupplyRequestSource extends RecentlyClosedSource
{
    private const CLOSING = [InventoryRequest::RECONCILED => 'reconciled_at', InventoryRequest::CLOSED => 'closed_at', InventoryRequest::CANCELLED => 'cancelled_at'];

    private const STATUS_LABELS = [
        InventoryRequest::SUBMITTED => 'Submitted',
        InventoryRequest::REVIEWED => 'Acknowledged',
        InventoryRequest::PREPARED => 'Prepared',
        InventoryRequest::RETURNED => 'Sent back to the member',
        InventoryRequest::RECONCILED => 'Filled',
        InventoryRequest::CLOSED => 'Closed',
        InventoryRequest::CANCELLED => 'Cancelled',
    ];

    private const LINES_IN_TITLE = 3;

    public function __construct(
        private readonly RequestWorkflowService $workflow,
        private readonly EmsAccess $access,
    ) {}

    public function type(): string
    {
        return 'medical_supply_request';
    }

    public function views(): array
    {
        return [DashboardView::MemberRequests, DashboardView::Overview];
    }

    public function visibleTo(User $user, bool $displayMode): bool
    {
        return ! $displayMode && $this->access->canView($user);
    }

    protected function baseQuery(): Builder
    {
        return InventoryRequest::query();
    }

    protected function whereOpen(Builder $query): void
    {
        $query->whereIn('ems_inventory_requests.status', InventoryRequest::ACTIVE_STATUSES);
    }

    protected function whereStage(Builder $query, string $stage, OperationalDisplayWindow $window): void
    {
        match ($stage) {
            'awaiting_response' => $query->where('ems_inventory_requests.status', InventoryRequest::SUBMITTED)->whereNull('ems_inventory_requests.reviewed_at'),
            'in_progress' => $query->whereIn('ems_inventory_requests.status', InventoryRequest::ACTIVE_STATUSES)
                ->where(fn (Builder $q) => $q->where('ems_inventory_requests.status', '<>', InventoryRequest::SUBMITTED)->orWhereNotNull('ems_inventory_requests.reviewed_at')),
            'resolved' => $query->where(function (Builder $q) use ($window): void {
                foreach (self::CLOSING as $status => $column) {
                    $q->orWhere(function (Builder $one) use ($status, $column, $window): void {
                        $one->where('ems_inventory_requests.status', $status);
                        self::whereInWindow($one, 'ems_inventory_requests.'.$column, $window);
                    });
                }
            }),
            default => self::nothing($query),
        };
    }

    protected function whereClosedSince(Builder $query, CarbonImmutable $since): void
    {
        $query->where(function (Builder $q) use ($since): void {
            foreach (self::CLOSING as $status => $column) {
                $q->orWhere(fn (Builder $one) => $one->where('ems_inventory_requests.status', $status)->where('ems_inventory_requests.'.$column, '>=', $since));
            }
        });
    }

    protected function whereSeverity(Builder $query, string $severity): void
    {
        if ($severity !== 'medium') {
            self::nothing($query);
        }
    }

    protected function requestTagConstraints(): array
    {
        return [
            'medical' => fn (Builder $q) => $q->whereNotNull('ems_inventory_requests.id'),
            'waiting_member' => fn (Builder $q) => $q->where('ems_inventory_requests.status', InventoryRequest::RETURNED),
            'awaiting_pickup' => fn (Builder $q) => $q->where('ems_inventory_requests.status', InventoryRequest::PREPARED),
        ];
    }

    /** Member replied: the newest message on the form is the member's (RequestMessageService). Hook for the member-replies work. */
    protected function whereMemberReplied(Builder $query): void
    {
        $query->whereRaw('('.self::lastMessageContextSql().') = ?', [InventoryRequestEvent::CONTEXT_MEMBER]);
    }

    protected function memberReplied(Model $record): bool
    {
        return $record->getAttribute('dashboard_last_message_context') === InventoryRequestEvent::CONTEXT_MEMBER;
    }

    protected function replySubject(): ?string
    {
        return ReplySubject::EMS_INVENTORY_REQUEST;
    }

    protected function prepareRows(Builder $query): void
    {
        $active = implode(',', array_map(static fn (string $s): string => "'".$s."'", InventoryRequest::ACTIVE_STATUSES));
        $query->select('ems_inventory_requests.*')
            ->with(['lines' => fn ($lines) => $lines->select(['id', 'request_id', 'label', 'variant_label', 'unit', 'requested_qty', 'requested_flag', 'sort'])])
            ->selectRaw('('.self::lastMessageContextSql().') as dashboard_last_message_context')
            ->orderByRaw("CASE WHEN ems_inventory_requests.status IN ({$active}) THEN 0 ELSE 1 END")
            ->orderByDesc('ems_inventory_requests.submitted_at')->orderByDesc('ems_inventory_requests.id');
        $this->selectAwaitingStaffReply($query);
    }

    /** @param InventoryRequest $record */
    protected function toIssue(Model $record, bool $displayMode): DashboardIssue
    {
        $status = (string) $record->status;
        $open = in_array($status, InventoryRequest::ACTIVE_STATUSES, true);
        $tags = ['medical'];
        if ($status === InventoryRequest::RETURNED) {
            $tags[] = 'waiting_member';
        }
        if ($status === InventoryRequest::PREPARED) {
            $tags[] = 'awaiting_pickup';
        }
        $closedAt = isset(self::CLOSING[$status]) ? $record->getAttribute(self::CLOSING[$status]) : null;

        return new DashboardIssue(
            type: $this->type(),
            id: (string) $record->getKey(),
            title: self::linesLine($record->lines->all()),
            summary: implode(' · ', array_filter([(string) $record->number, trim((string) $record->target_label), trim((string) $record->event_assignment)])) ?: null,
            severity: 'medium',
            status: $status,
            statusLabel: self::STATUS_LABELS[$status] ?? ucfirst(str_replace('_', ' ', $status)),
            stage: self::stageOf($record),
            tags: $this->withReplyTag($record, $tags, $open),
            subject: trim((string) $record->submitter_name) ?: null,
            imageKey: null,
            actorLabel: null,
            categoryLabel: 'Medical supply request',
            occurredAt: self::at($open ? $record->submitted_at : $closedAt, $record->updated_at),
            version: self::stamp($record->updated_at),
            sourceUrl: InventoryRequestResource::getUrl('view', ['record' => $record->uuid], panel: 'admin'),
            groupKey: self::memberGroup($record->submitted_by_user_id, $record->submitted_by_employee_id),
        );
    }

    public function detail(User $user, string $id): ?IssueDetail
    {
        if (! $this->visibleTo($user, false) || ($request = $this->find($id)) === null) {
            return null;
        }
        $request->load(['lines', 'events', 'messages']);
        $last = $request->messages->sortByDesc('id')->first();
        $request->setAttribute('dashboard_last_message_context', $last instanceof InventoryRequestMessage ? $last->author_context : null);
        $issue = $this->toIssue($request, false);

        $timeline = $request->events->map(static fn (InventoryRequestEvent $event): array => [
            'at' => CarbonImmutable::instance($event->created_at ?? $request->submitted_at),
            'actor' => $event->actor_name ?: 'System',
            'text' => implode("\n", array_filter([
                str($event->action)->replace('_', ' ')->ucfirst()->toString(),
                filled($event->payload['reason'] ?? null) ? 'Reason: '.$event->payload['reason'] : null,
            ])),
        ])->merge($request->messages->map(static fn (InventoryRequestMessage $message): array => [
            'at' => CarbonImmutable::instance($message->created_at ?? $request->submitted_at),
            'actor' => $message->author_name.($message->author_context === InventoryRequestEvent::CONTEXT_MEMBER ? ' (member)' : ''),
            'text' => 'Message: '.$message->body,
        ]))->sortBy(static fn (array $entry): int => $entry['at']->getTimestamp())->values()->all();

        $facts = array_values(array_filter([
            ['label' => 'Request', 'value' => (string) $request->number],
            ['label' => 'For', 'value' => trim((string) $request->target_label)],
            ['label' => 'Event or detail', 'value' => trim((string) $request->event_assignment)],
            ['label' => 'Submitted by', 'value' => trim(implode(' · ', array_filter([(string) $request->submitter_rank, (string) $request->submitter_name])))],
            ['label' => 'Submitted', 'value' => (string) $request->submitted_at->timezone(OperationalDisplayWindow::TIMEZONE)->format('M j, Y g:i A')],
            ['label' => 'Status', 'value' => $issue->statusLabel],
            ['label' => 'Acknowledged by', 'value' => (string) $request->reviewed_by_name],
            ['label' => 'Items', 'value' => $request->lines->map(static fn (InventoryRequestLine $line): string => self::lineLabel($line))->filter()->implode('; ')],
            ['label' => 'Sent back because', 'value' => $request->status === InventoryRequest::RETURNED ? trim((string) $request->return_reason) : ''],
        ], static fn (array $fact): bool => trim($fact['value']) !== ''));

        $canAct = $this->access->canAct($user);
        $reason = match (true) {
            ! $canAct => 'You can see medical supply requests but not work them.',
            ! $request->canBeWorked() => $request->status === InventoryRequest::RETURNED
                ? 'Sent back to the member. It comes back here when they send a corrected request.'
                : 'This request is finished. Its record stays in Medical Inventory Requests.',
            default => null,
        };
        $actions = [];
        if ($reason === null) {
            if (! $request->isAcknowledged()) {
                $actions[] = new IssueAction('acknowledge', 'Acknowledge', 'primary', [], bulk: IssueAction::BULK_DIRECT);
            }
            $actions[] = new IssueAction('return_to_member', 'Send back to the member', 'danger', [
                ['name' => 'reason', 'label' => 'What the member should correct', 'type' => 'textarea', 'required' => true, 'max' => 500],
            ], 'Send this request back? The member sees your reason and sends a corrected one.');
        }

        return new IssueDetail($issue, trim((string) $request->member_note) ?: null, $facts, $timeline, $actions, $reason);
    }

    public function perform(User $user, string $id, string $action, array $input, string $expectedVersion): ActionResult
    {
        if (! $this->visibleTo($user, false) || ! $this->access->canAct($user)) {
            return ActionResult::forbidden();
        }
        if (! in_array($action, ['acknowledge', 'return_to_member'], true)) {
            return ActionResult::invalid(['action' => 'This action is not available.']);
        }
        $request = $this->find($id);
        if ($request === null) {
            return ActionResult::gone('This request no longer exists.');
        }
        if ($action === 'acknowledge' && $request->isAcknowledged() && $request->canBeWorked()) {
            return ActionResult::done('Already acknowledged.');
        }
        if (self::stamp($request->updated_at) !== $expectedVersion) {
            return ActionResult::conflict();
        }
        $key = 'dashboard-'.substr(hash('sha256', implode('|', [$user->getKey(), $request->getKey(), $expectedVersion, $action, self::text($input['reason'] ?? '')])), 0, 48);

        try {
            if ($action === 'acknowledge') {
                $this->workflow->acknowledge((int) $request->getKey(), $user, $key);

                return ActionResult::done('Request acknowledged.');
            }
            $this->workflow->reject((int) $request->getKey(), $user, $key, ['reason' => self::text($input['reason'] ?? '')]);

            return ActionResult::done('Sent back to the member.');
        } catch (EmsException $exception) {
            return match ($exception->status) {
                403 => ActionResult::forbidden($exception->getMessage()),
                404 => ActionResult::gone($exception->getMessage()),
                409 => ActionResult::conflict($exception->getMessage()),
                default => ActionResult::invalid(
                    array_map(static fn (array $messages): string => (string) ($messages[0] ?? $exception->getMessage()), $exception->fields) ?: ['reason' => $exception->getMessage()],
                    $exception->getMessage(),
                ),
            };
        } catch (Throwable $exception) {
            report($exception);

            return ActionResult::invalid(['action' => 'That could not be saved. Try again.']);
        }
    }

    private function find(string $id): ?InventoryRequest
    {
        if (preg_match('/^[1-9][0-9]{0,18}$/', $id) !== 1) {
            return null;
        }
        $request = InventoryRequest::query()->where('status', '<>', InventoryRequest::SUPERSEDED)->find((int) $id);

        return $request instanceof InventoryRequest ? $request : null;
    }

    private static function stageOf(InventoryRequest $request): string
    {
        return match (true) {
            $request->status === InventoryRequest::SUBMITTED && $request->reviewed_at === null => 'awaiting_response',
            in_array($request->status, InventoryRequest::ACTIVE_STATUSES, true) => 'in_progress',
            isset(self::CLOSING[$request->status]) => 'resolved',
            default => 'other',
        };
    }

    private static function lastMessageContextSql(): string
    {
        return 'SELECT m.author_context FROM ems_inventory_request_messages m WHERE m.request_id = ems_inventory_requests.id ORDER BY m.id DESC LIMIT 1';
    }

    /** @param list<InventoryRequestLine> $lines */
    private static function linesLine(array $lines): string
    {
        $labels = array_values(array_filter(array_map(static fn (InventoryRequestLine $line): string => self::lineLabel($line), $lines)));
        if ($labels === []) {
            return 'No items listed';
        }
        $shown = array_slice($labels, 0, self::LINES_IN_TITLE);
        $more = count($labels) - count($shown);

        return implode(', ', $shown).($more > 0 ? ' + '.$more.' more' : '');
    }

    /** "Gauze 4x4 ×10 each"; a checkbox line ("needed") has no count. Lines asking for nothing are left out. */
    private static function lineLabel(InventoryRequestLine $line): string
    {
        $label = trim(trim((string) $line->label).' '.trim((string) $line->variant_label));
        if ((int) $line->requested_qty > 0) {
            return $label.' ×'.(int) $line->requested_qty.(filled($line->unit) && $line->unit !== 'each' ? ' '.$line->unit : '');
        }

        return $line->requested_flag ? $label : '';
    }
}
