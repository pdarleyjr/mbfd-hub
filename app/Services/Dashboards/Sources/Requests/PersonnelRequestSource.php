<?php

declare(strict_types=1);

namespace App\Services\Dashboards\Sources\Requests;

use App\Enums\PersonnelRequestStatus as Status;
use App\Enums\PersonnelRequestType as Type;
use App\Filament\Clusters\PersonnelUniformsEquipment\Resources\PersonnelRequestResource;
use App\Models\Employee;
use App\Models\PersonnelRequest;
use App\Models\PersonnelRequestItem;
use App\Models\PersonnelRequestUpdate;
use App\Models\User;
use App\Services\Dashboards\Support\RecentlyClosedSource;
use App\Services\PersonnelRequests\PersonnelRequestWorkflowService;
use App\Services\RequestArchivalService;
use App\Support\Dashboards\ActionResult;
use App\Support\Dashboards\DashboardIssue;
use App\Support\Dashboards\DashboardView;
use App\Support\Dashboards\IssueAction;
use App\Support\Dashboards\IssueDetail;
use App\Support\DecisionReason;
use App\Support\MemberReplies\ReplySubject;
use App\Support\OperationalDisplayWindow;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Uniform orders and PPE / equipment requests (personnel_requests), the Personnel Requests list in the
 * Personnel, Uniforms & Equipment cluster. Every status change goes through PersonnelRequestWorkflowService, the
 * same service the request's own page uses, so its transition table, reasons, notifications and history apply.
 * Issuing items (stock and sizes) and recording arrivals per item stay on the full record.
 *
 * Visibility: admin.personnel.view (PersonnelRequestResource::canViewAny); actions: the policy's "update".
 * Never on the wall display: rows name members.
 *
 * Severity (no triage table covers these): a PPE / equipment request, or a uniform order over the annual
 * allowance, is medium; any other uniform order is low. Nothing here is high.
 * Stage: pending -> awaiting_response; other open statuses -> in_progress; completed / denied / cancelled ->
 * resolved (closing time completed_at / denied_at / cancelled_at). Tags: uniform | ppe, waiting_member
 * (needs_information), awaiting_pickup (ordered, arrived, ready_for_pickup), member_replied (the newest history row
 * is the member's own response).
 *
 * @extends RecentlyClosedSource<PersonnelRequest>
 */
final class PersonnelRequestSource extends RecentlyClosedSource
{
    private const CLOSING = [Status::Completed->value => 'completed_at', Status::Denied->value => 'denied_at', Status::Cancelled->value => 'cancelled_at'];

    private const PICKUP = [Status::Ordered->value, Status::Arrived->value, Status::ReadyForPickup->value];

    /** action => target status */
    private const TRANSITIONS = [
        'acknowledge' => Status::Acknowledged,
        'order' => Status::Ordered,
        'arrived' => Status::Arrived,
        'ready' => Status::ReadyForPickup,
        'complete' => Status::Completed,
        'deny' => Status::Denied,
        'cancel' => Status::Cancelled,
    ];

    private const INFORMATION_TYPES = [
        'additional_explanation' => 'Additional explanation',
        'damage_photo' => 'Photo of damage',
        'police_report' => 'Police report',
        'police_case_number' => 'Police case number',
        'other' => 'Other information',
    ];

    private const ITEMS_IN_TITLE = 3;

    private const MEMBER_REPLY_EVENT = 'employee_responded';

    public function __construct(private readonly PersonnelRequestWorkflowService $workflow) {}

    public function type(): string
    {
        return 'personnel_request';
    }

    public function views(): array
    {
        return [DashboardView::MemberRequests, DashboardView::Overview];
    }

    public function visibleTo(User $user, bool $displayMode): bool
    {
        return ! $displayMode && $user->can('admin.personnel.view');
    }

    protected function baseQuery(): Builder
    {
        return PersonnelRequest::query()->active();
    }

    protected function whereOpen(Builder $query): void
    {
        $query->whereNotIn('personnel_requests.status', array_keys(self::CLOSING));
    }

    protected function whereStage(Builder $query, string $stage, OperationalDisplayWindow $window): void
    {
        match ($stage) {
            'awaiting_response' => $query->where('personnel_requests.status', Status::Pending->value),
            'in_progress' => $query->whereNotIn('personnel_requests.status', [Status::Pending->value, ...array_keys(self::CLOSING)]),
            'resolved' => $query->where(function (Builder $q) use ($window): void {
                foreach (self::CLOSING as $status => $column) {
                    $q->orWhere(function (Builder $one) use ($status, $column, $window): void {
                        $one->where('personnel_requests.status', $status);
                        self::whereInWindow($one, 'personnel_requests.'.$column, $window);
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
                $q->orWhere(fn (Builder $one) => $one->where('personnel_requests.status', $status)->where('personnel_requests.'.$column, '>=', $since));
            }
        });
    }

    protected function whereSeverity(Builder $query, string $severity): void
    {
        match ($severity) {
            'medium' => $query->where(fn (Builder $q) => $q->where('personnel_requests.type', Type::Equipment->value)->orWhere('personnel_requests.over_allowance', true)),
            'low' => $query->where('personnel_requests.type', Type::Uniform->value)
                ->where(fn (Builder $q) => $q->whereNull('personnel_requests.over_allowance')->orWhere('personnel_requests.over_allowance', false)),
            default => self::nothing($query),
        };
    }

    protected function requestTagConstraints(): array
    {
        return [
            'uniform' => fn (Builder $q) => $q->where('personnel_requests.type', Type::Uniform->value),
            'ppe' => fn (Builder $q) => $q->where('personnel_requests.type', Type::Equipment->value),
            'waiting_member' => fn (Builder $q) => $q->where('personnel_requests.status', Status::NeedsInformation->value),
            'awaiting_pickup' => fn (Builder $q) => $q->whereIn('personnel_requests.status', self::PICKUP),
        ];
    }

    /**
     * Member replied: the newest history row is the member's own response (PersonnelRequestWorkflowService::employeeRespond).
     * Any staff action after it writes a newer row, which clears the signal. Hook for the member-replies work.
     */
    protected function whereMemberReplied(Builder $query): void
    {
        $query->whereRaw('('.self::lastEventSql().') = ?', [self::MEMBER_REPLY_EVENT]);
    }

    protected function memberReplied(Model $record): bool
    {
        return $record->getAttribute('dashboard_last_event') === self::MEMBER_REPLY_EVENT;
    }

    protected function replySubject(): string
    {
        return ReplySubject::PERSONNEL_REQUEST;
    }

    protected function prepareRows(Builder $query): void
    {
        $closed = implode(',', array_map(static fn (string $s): string => "'".$s."'", array_keys(self::CLOSING)));
        $query->select('personnel_requests.*')
            ->with(['items:id,personnel_request_id,item_name,size,quantity,arrived_quantity,fulfilled_quantity'])
            ->withMax('updates as updates_max_id', 'id')
            ->selectRaw('('.self::lastEventSql().') as dashboard_last_event')
            ->addSelect(['dashboard_member_user_id' => User::query()->select('id')
                ->whereColumn('users.employee_profile_id', 'personnel_requests.beneficiary_employee_id')->orderBy('id')->limit(1)])
            ->orderByRaw("CASE WHEN personnel_requests.status IN ({$closed}) THEN 1 ELSE 0 END")
            ->orderByRaw("CASE WHEN personnel_requests.type = 'equipment' OR personnel_requests.over_allowance = ? THEN 0 ELSE 1 END", [true])
            ->orderByDesc('personnel_requests.created_at')->orderByDesc('personnel_requests.id');
        $this->selectAwaitingStaffReply($query);
    }

    /** @param PersonnelRequest $record */
    protected function toIssue(Model $record, bool $displayMode): DashboardIssue
    {
        $status = $record->status;
        $type = $record->type;
        $open = ! $status->isTerminal();
        $tags = [$type === Type::Equipment ? 'ppe' : 'uniform'];
        if ($status === Status::NeedsInformation) {
            $tags[] = 'waiting_member';
        }
        if (in_array($status->value, self::PICKUP, true)) {
            $tags[] = 'awaiting_pickup';
        }
        $requester = trim((string) $record->requester_name);
        $member = trim((string) $record->beneficiary_name);
        $closedAt = isset(self::CLOSING[$status->value]) ? $record->getAttribute(self::CLOSING[$status->value]) : null;

        return new DashboardIssue(
            type: $this->type(),
            id: (string) $record->getKey(),
            title: self::itemsLine($record->items->all()),
            summary: implode(' · ', array_filter([
                (string) $record->request_number,
                $record->over_allowance ? 'Over annual allowance' : null,
                $requester !== '' && $requester !== $member ? 'Submitted by '.$requester : null,
            ])) ?: null,
            severity: self::severityOf($record),
            status: $status->value,
            statusLabel: $status->label(),
            stage: self::stage($status),
            tags: $this->withReplyTag($record, $tags, $open),
            subject: $member !== '' ? $member : null,
            imageKey: null,
            actorLabel: $requester !== '' && $requester !== $member ? $requester : null,
            categoryLabel: $type === Type::Equipment ? 'PPE / equipment request' : 'Uniform order',
            occurredAt: self::at($open ? $record->created_at : $closedAt, $record->updated_at),
            version: self::versionOf($record, (int) $record->getAttribute('updates_max_id')),
            sourceUrl: PersonnelRequestResource::getUrl('view', ['record' => $record->public_id], panel: 'admin'),
            groupKey: self::memberGroup($record->getAttribute('dashboard_member_user_id'), $record->beneficiary_employee_id),
        );
    }

    // ------------------------------------------------------------------ detail

    public function detail(User $user, string $id): ?IssueDetail
    {
        if (! $this->visibleTo($user, false) || ($request = $this->find($id)) === null || ! Gate::forUser($user)->allows('view', $request)) {
            return null;
        }
        $request->load(['items', 'updates', 'originatingStation:id,station_number,name', 'assignedAdmin:id,name']);
        $request->setAttribute('updates_max_id', $request->updates->max('id'));
        $request->setAttribute('dashboard_last_event', $request->updates->sortByDesc('id')->first()?->event);
        $request->setAttribute('dashboard_member_user_id', User::query()->where('employee_profile_id', $request->beneficiary_employee_id)->orderBy('id')->value('id'));
        $issue = $this->toIssue($request, false);

        $admins = User::query()->whereIn('id', $request->updates->pluck('changed_by_admin_id')->filter()->unique())->pluck('name', 'id');
        $employees = Employee::query()->whereIn('id', $request->updates->pluck('changed_by_employee_id')->filter()->unique())->get()
            ->mapWithKeys(static fn (Employee $employee): array => [(int) $employee->getKey() => (string) $employee->name]);
        $timeline = $request->updates->map(static fn (PersonnelRequestUpdate $update): array => [
            'at' => CarbonImmutable::instance($update->created_at),
            'actor' => $update->changed_by_admin_id !== null
                ? ($admins[$update->changed_by_admin_id] ?? 'Staff')
                : ($update->changed_by_employee_id !== null ? ($employees[$update->changed_by_employee_id] ?? 'Member').' (member)' : 'System'),
            'text' => implode("\n", array_filter([
                str((string) $update->event)->replace('_', ' ')->ucfirst()->toString().' · '.$update->status->label(),
                filled($update->employee_visible_note) ? 'To the member: '.$update->employee_visible_note : null,
                filled($update->internal_note) ? 'Internal note: '.$update->internal_note : null,
            ])),
        ])->values()->all();

        $unissued = $request->items->filter(static fn (PersonnelRequestItem $item): bool => (int) $item->fulfilled_quantity < (int) $item->quantity)->count();
        $facts = array_values(array_filter([
            ['label' => 'Request', 'value' => (string) $request->request_number],
            ['label' => 'Type', 'value' => $request->type->label()],
            ['label' => 'Member', 'value' => trim(implode(' · ', array_filter([(string) $request->beneficiary_rank, (string) $request->beneficiary_name, (string) $request->beneficiary_employee_number])))],
            ['label' => 'Submitted by', 'value' => trim(implode(' · ', array_filter([(string) $request->requester_rank, (string) $request->requester_name])))],
            ['label' => 'Station', 'value' => $request->originatingStation ? 'Station '.$request->originatingStation->station_number : ''],
            ['label' => 'Status', 'value' => $request->status->label()],
            ['label' => 'Items', 'value' => $request->items->map(static fn (PersonnelRequestItem $item): string => self::itemLabel($item)
                .' (arrived '.(int) $item->arrived_quantity.', issued '.(int) $item->fulfilled_quantity.')')->implode('; ')],
            ['label' => 'Allowance', 'value' => $request->over_allowance ? 'Over the annual allowance. Reason: '.trim((string) $request->allowance_justification) : ''],
            ['label' => 'Member note', 'value' => trim((string) data_get($request->metadata, 'member_note', ''))],
            ['label' => 'Information asked for', 'value' => collect((array) $request->information_requested)->map(static fn ($key): string => self::INFORMATION_TYPES[$key] ?? (string) $key)->implode(', ')],
            ['label' => 'Assigned to', 'value' => (string) $request->assignedAdmin?->name],
            ['label' => 'Submitted', 'value' => (string) $request->created_at?->timezone(OperationalDisplayWindow::TIMEZONE)->format('M j, Y g:i A')],
            ['label' => 'Issuing', 'value' => ! $request->status->isTerminal() && $unissued > 0
                ? $unissued.' item'.($unissued === 1 ? '' : 's').' still to issue. Record arrivals and issue items (stock and size) from the full request.' : ''],
        ], static fn (array $fact): bool => trim($fact['value']) !== ''));

        $reason = match (true) {
            $request->trashed() => 'This request is in the Trash. Restore it from the full record first.',
            $request->isArchived() => 'This request is archived. Restore it from the full record to work on it.',
            ! Gate::forUser($user)->allows('update', $request) => 'You can see personnel requests but not update them.',
            default => null,
        };

        return new IssueDetail($issue, trim((string) data_get($request->metadata, 'member_note', '')) ?: null, $facts, $timeline,
            $reason === null ? $this->actionsFor($request, $user) : [], $reason);
    }

    /** @return list<IssueAction> */
    private function actionsFor(PersonnelRequest $request, User $user): array
    {
        $note = ['name' => 'employee_note', 'label' => 'Note to the member (optional)', 'type' => 'textarea', 'required' => false, 'max' => 2000];
        $internal = ['name' => 'internal_note', 'label' => 'Internal note, staff only (optional)', 'type' => 'textarea', 'required' => false, 'max' => 2000];
        $reasonField = ['name' => 'employee_note', 'label' => 'Reason (shown to the member)', 'type' => 'textarea', 'required' => true, 'max' => DecisionReason::MAX_LENGTH];
        $can = fn (Status $to): bool => $to === Status::Completed ? $this->workflow->canComplete($request) : $this->workflow->canTransition($request, $to);
        $actions = [];

        if ($request->status->isTerminal()) {
            return app(RequestArchivalService::class)->canManage($user, $request)
                ? [new IssueAction('archive', 'Archive', 'secondary', [['name' => 'archive_reason', 'label' => 'Reason (optional)', 'type' => 'textarea', 'required' => false, 'max' => 2000]],
                    'Archive this request? It leaves the dashboards and the active request list. The record and its history stay.')]
                : [];
        }
        if ($can(Status::Acknowledged) && $request->status === Status::Pending) {
            $actions[] = new IssueAction('acknowledge', 'Acknowledge', 'primary', [$note, $internal], bulk: IssueAction::BULK_DIRECT);
        }
        if ($can(Status::NeedsInformation)) {
            $actions[] = new IssueAction('request_information', 'Ask the member for information', 'secondary', [
                ['name' => 'information_type', 'label' => 'What is needed', 'type' => 'select', 'required' => true, 'options' => self::INFORMATION_TYPES],
                ['name' => 'message', 'label' => 'Instructions for the member', 'type' => 'textarea', 'required' => true, 'max' => 2000],
                $internal,
            ]);
        }
        if ($can(Status::Acknowledged) && $request->status === Status::NeedsInformation) {
            $actions[] = new IssueAction('acknowledge', 'Back to in progress', 'secondary', [$note, $internal]);
        }
        foreach (['order' => [Status::Ordered, 'Mark ordered'], 'arrived' => [Status::Arrived, 'Mark arrived'], 'ready' => [Status::ReadyForPickup, 'Ready for pickup']] as $key => [$to, $label]) {
            if ($can($to)) {
                $actions[] = new IssueAction($key, $label, $key === 'ready' ? 'primary' : 'secondary', [$note, $internal],
                    $key === 'arrived' ? 'Mark every item arrived? Each item is recorded as arrived in full.' : null);
            }
        }
        if ($can(Status::Completed)) {
            $actions[] = new IssueAction('complete', 'Complete', 'primary', [$note, $internal], 'Mark this request completed? Every item has been issued.');
        }
        $actions[] = new IssueAction('add_note', 'Add note', 'secondary', [$note, $internal], bulk: IssueAction::BULK_FORM);
        if ($can(Status::Denied)) {
            $actions[] = new IssueAction('deny', 'Deny', 'danger', [$reasonField, $internal], 'Deny this request? The member sees your reason.');
        }
        if ($can(Status::Cancelled)) {
            $actions[] = new IssueAction('cancel', 'Cancel request', 'danger', [$reasonField, $internal], 'Cancel this request? The member sees your reason.');
        }

        return $actions;
    }

    // ----------------------------------------------------------------- perform

    public function perform(User $user, string $id, string $action, array $input, string $expectedVersion): ActionResult
    {
        if (! $this->visibleTo($user, false)) {
            return ActionResult::forbidden();
        }
        if (! array_key_exists($action, self::TRANSITIONS) && ! in_array($action, ['request_information', 'add_note', 'archive'], true)) {
            return ActionResult::invalid(['action' => 'This action is not available.']);
        }

        try {
            return DB::transaction(function () use ($user, $id, $action, $input, $expectedVersion): ActionResult {
                $request = $this->find($id, lock: true);
                if ($request === null) {
                    return ActionResult::gone('This request no longer exists.');
                }
                if (! Gate::forUser($user)->allows('update', $request)) {
                    return ActionResult::forbidden();
                }
                $note = self::text($input['employee_note'] ?? '') ?: null;
                $internal = self::text($input['internal_note'] ?? '') ?: null;
                $latest = PersonnelRequestUpdate::query()->where('personnel_request_id', $request->getKey())->orderByDesc('id')->first();
                if (self::versionOf($request, (int) $latest?->getKey()) !== $expectedVersion) {
                    return $this->isRepeat($request, $latest, $user, $action, $note, $internal) ? ActionResult::done('Already saved.') : ActionResult::conflict();
                }
                if ($action !== 'archive' && $request->isArchived()) {
                    return ActionResult::gone('This request was archived.');
                }

                return match ($action) {
                    'archive' => $this->archive($request, $user, self::text($input['archive_reason'] ?? '') ?: null),
                    'add_note' => $note === null && $internal === null
                        ? ActionResult::invalid(['employee_note' => 'Write a note for the member or an internal note.'])
                        : $this->saved(fn () => $this->workflow->addNote($request, $user, $note, $internal, null, self::actionKey($user, $request, $expectedVersion, $action, [$note, $internal])), 'Note added.'),
                    'request_information' => $this->saved(fn () => $this->workflow->requestInformation(
                        $request, $user, [self::text($input['information_type'] ?? '')], self::text($input['message'] ?? ''), $internal,
                    ), 'The member was asked for information.'),
                    default => $this->saved(fn () => $this->workflow->transition($request, self::TRANSITIONS[$action], $user, $note, $internal), match ($action) {
                        'acknowledge' => 'Request acknowledged.',
                        'order' => 'Marked ordered.',
                        'arrived' => 'Marked arrived.',
                        'ready' => 'Marked ready for pickup. The member is told.',
                        'complete' => 'Request completed.',
                        'deny' => 'Request denied.',
                        default => 'Request cancelled.',
                    }),
                };
            });
        } catch (ValidationException $exception) {
            return ActionResult::invalid(self::errorsOf($exception));
        } catch (AuthorizationException) {
            return ActionResult::forbidden();
        } catch (HttpExceptionInterface $exception) {
            return $exception->getStatusCode() === 403 ? ActionResult::forbidden() : throw $exception;
        }
    }

    private function saved(callable $operation, string $message): ActionResult
    {
        $operation();

        return ActionResult::done($message);
    }

    private function archive(PersonnelRequest $request, User $user, ?string $reason): ActionResult
    {
        if ($request->isArchived()) {
            return ActionResult::done('Already archived.');
        }
        if (! $request->status->isTerminal()) {
            return ActionResult::invalid(['action' => 'Only a completed, denied or cancelled request can be archived from here.']);
        }
        $service = app(RequestArchivalService::class);
        if (! $service->canManage($user, $request)) {
            return ActionResult::forbidden();
        }
        $service->archive($request, $user, $reason);

        return ActionResult::done('Archived. The record and its history stay in Personnel Requests.');
    }

    /** The same command, already saved by this admin: the newest history row is theirs, with this status and these notes. */
    private function isRepeat(PersonnelRequest $request, ?PersonnelRequestUpdate $latest, User $user, string $action, ?string $note, ?string $internal): bool
    {
        if ($latest === null || (int) $latest->changed_by_admin_id !== (int) $user->getKey()) {
            return false;
        }
        $target = self::TRANSITIONS[$action] ?? null;

        return match (true) {
            $target !== null => $request->status === $target && $latest->status === $target && $latest->employee_visible_note === $note && $latest->internal_note === $internal,
            $action === 'add_note' => $latest->event === 'note_added' && $latest->employee_visible_note === $note && $latest->internal_note === $internal,
            $action === 'archive' => $request->isArchived(),
            default => false,
        };
    }

    private function find(string $id, bool $lock = false): ?PersonnelRequest
    {
        if (preg_match('/^[1-9][0-9]{0,18}$/', $id) !== 1) {
            return null;
        }
        $query = PersonnelRequest::query();
        if ($lock) {
            $query->lockForUpdate();
        }
        $request = $query->find((int) $id);

        return $request instanceof PersonnelRequest ? $request : null;
    }

    /** @param list<?string> $payload */
    private static function actionKey(User $user, PersonnelRequest $request, string $version, string $action, array $payload): string
    {
        return 'dashboard:'.Uuid::uuid5(Uuid::NAMESPACE_URL, implode('|', ['mbfd-dashboard', $user->getKey(), $request->getKey(), $version, $action, json_encode($payload)]))->toString();
    }

    private static function lastEventSql(): string
    {
        return 'SELECT u.event FROM personnel_request_updates u WHERE u.personnel_request_id = personnel_requests.id ORDER BY u.id DESC LIMIT 1';
    }

    private static function versionOf(PersonnelRequest $request, int $latestUpdateId): string
    {
        return self::stamp($request->updated_at).'#'.$latestUpdateId;
    }

    private static function severityOf(PersonnelRequest $request): string
    {
        return $request->type === Type::Equipment || $request->over_allowance ? 'medium' : 'low';
    }

    private static function stage(Status $status): string
    {
        return match (true) {
            $status === Status::Pending => 'awaiting_response',
            $status->isTerminal() => 'resolved',
            default => 'in_progress',
        };
    }

    /** @param list<PersonnelRequestItem> $items "Class B shirt (L) ×2, Trousers (34x32) ×1 + 2 more" */
    private static function itemsLine(array $items): string
    {
        if ($items === []) {
            return 'No items listed';
        }
        $shown = array_map(static fn (PersonnelRequestItem $item): string => self::itemLabel($item), array_slice($items, 0, self::ITEMS_IN_TITLE));
        $more = count($items) - count($shown);

        return implode(', ', $shown).($more > 0 ? ' + '.$more.' more' : '');
    }

    private static function itemLabel(PersonnelRequestItem $item): string
    {
        $size = trim((string) $item->size);

        return trim((string) $item->item_name).($size !== '' ? ' ('.$size.')' : '').' ×'.(int) $item->quantity;
    }
}
