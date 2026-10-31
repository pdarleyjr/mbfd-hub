<?php

declare(strict_types=1);

namespace App\Services\Dashboards\Sources\Station;

use App\Enums\StationRequestStatus as Status;
use App\Enums\StationRequestType;
use App\Filament\Resources\StationRequestResource;
use App\Models\StationRequest;
use App\Models\StationRequestUpdate;
use App\Models\User;
use App\Services\StationRequestWorkflowService;
use App\Support\Dashboards\ActionResult;
use App\Support\Dashboards\DashboardIssue;
use App\Support\Dashboards\DashboardView;
use App\Support\Dashboards\IssueAction;
use App\Support\Dashboards\IssueDetail;
use App\Support\DecisionReason;
use App\Support\OperationalDisplayWindow;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Station requests (repair/service and equipment) from StationRequestResource.
 *
 * Tags come from the structured request_type: repair_service → "facilities", equipment →
 * "supplies", anything else → "other". Severity from priority: critical/high → high, normal →
 * medium, low → low. Stage from status: pending → awaiting_response; other open statuses →
 * in_progress; completed/denied/cancelled → resolved (in the window by completed_at/denied_at/
 * cancelled_at). Archived requests are out of scope. Every action goes through
 * StationRequestWorkflowService::transition(), exactly like the resource's "Update Status" action.
 *
 * @extends QueryBackedSource<StationRequest>
 */
final class StationRequestSource extends QueryBackedSource
{
    private const TYPE_TAGS = ['repair_service' => 'facilities', 'equipment' => 'supplies'];

    private const ACTIONS = ['acknowledge', 'approve', 'assign', 'set_status', 'post_update', 'complete', 'deny'];

    public function __construct(private readonly StationRequestWorkflowService $workflow) {}

    public function type(): string
    {
        return 'station_request';
    }

    public function views(): array
    {
        return [DashboardView::StationOperations, DashboardView::Overview];
    }

    /** Same gate as StationRequestResource::canViewAny(). Station requests may appear on the wall (no names). */
    public function visibleTo(User $user, bool $displayMode): bool
    {
        return $user->can('admin.stations.view');
    }

    protected function baseQuery(): Builder
    {
        return StationRequest::query()->active();
    }

    protected function whereOpen(Builder $query): void
    {
        $query->whereIn('status', Status::openValues());
    }

    protected function whereStage(Builder $query, string $stage, OperationalDisplayWindow $window): void
    {
        match ($stage) {
            'awaiting_response' => $query->where('status', Status::Pending->value),
            'in_progress' => $query->whereIn('status', array_values(array_diff(Status::openValues(), [Status::Pending->value]))),
            'resolved' => $query->where(function (Builder $q) use ($window): void {
                foreach ([Status::Completed->value => 'completed_at', Status::Denied->value => 'denied_at', Status::Cancelled->value => 'cancelled_at'] as $status => $column) {
                    $q->orWhere(function (Builder $one) use ($status, $column, $window): void {
                        $one->where('status', $status);
                        self::whereInWindow($one, $column, $window);
                    });
                }
            }),
            default => self::nothing($query),
        };
    }

    protected function whereSeverity(Builder $query, string $severity): void
    {
        match ($severity) {
            'high' => $query->whereIn('priority', ['critical', 'high']),
            'medium' => $query->where('priority', 'normal'),
            'low' => $query->whereNotIn('priority', ['critical', 'high', 'normal']),
            default => self::nothing($query),
        };
    }

    protected function tagConstraints(): array
    {
        return [
            'facilities' => fn (Builder $q) => $q->where('request_type', StationRequestType::RepairService->value),
            'supplies' => fn (Builder $q) => $q->where('request_type', StationRequestType::Equipment->value),
            'other' => fn (Builder $q) => $q->whereNotIn('request_type', array_keys(self::TYPE_TAGS)),
        ];
    }

    protected function prepareRows(Builder $query): void
    {
        $terminal = implode(',', array_map(fn (string $s): string => "'".$s."'", Status::terminalValues()));
        $query->with('station:id,station_number,name')->withMax('updates', 'id')
            ->orderByRaw("CASE WHEN status IN ({$terminal}) THEN 1 ELSE 0 END")
            ->orderByRaw("CASE priority WHEN 'critical' THEN 0 WHEN 'high' THEN 1 WHEN 'normal' THEN 2 ELSE 3 END")
            ->orderByDesc('created_at')->orderByDesc('id');
    }

    /** @param StationRequest $record */
    protected function toIssue(Model $record, bool $displayMode): DashboardIssue
    {
        $status = Status::from($record->status);
        $type = StationRequestType::tryFrom((string) $record->request_type);

        return new DashboardIssue(
            type: $this->type(),
            id: (string) $record->getKey(),
            title: (string) $record->title,
            summary: $displayMode ? null : (str((string) $record->description)->squish()->limit(160)->toString() ?: null),
            severity: self::severity((string) $record->priority),
            status: $status->value,
            statusLabel: $status->label(),
            stage: self::stage($status),
            tags: [self::TYPE_TAGS[$record->request_type] ?? 'other'],
            subject: StationImageKey::label($record->station),
            imageKey: StationImageKey::for($record->station),
            actorLabel: $displayMode ? null : ($record->requester_name_snapshot ?: null),
            categoryLabel: $type?->label(),
            occurredAt: CarbonImmutable::instance($record->created_at),
            version: self::versionOf($record, (int) $record->getAttribute('updates_max_id')),
            sourceUrl: StationRequestResource::getUrl('view', ['record' => $record->getKey()], panel: 'admin'),
        );
    }

    public function detail(User $user, string $id): ?IssueDetail
    {
        if (! $this->visibleTo($user, false)) {
            return null;
        }
        $request = StationRequest::query()->with([
            'station:id,station_number,name', 'room:id,name', 'assignedTo:id,name', 'acknowledgedBy:id,name',
            'items:id,station_request_id,item_name,quantity', 'updates.changedBy:id,name',
        ])->find($id);
        if (! $request instanceof StationRequest) {
            return null;
        }

        $status = Status::from($request->status);
        $timeline = $request->updates->map(fn (StationRequestUpdate $update): array => [
            'at' => CarbonImmutable::instance($update->created_at),
            'actor' => $update->changedBy->name ?? 'System',
            'text' => collect([
                'Status: '.(Status::tryFrom((string) $update->status)?->label() ?? (string) $update->status),
                filled($update->public_note) ? 'Update to station: '.$update->public_note : null,
                filled($update->internal_note) ? 'Internal note: '.$update->internal_note : null,
            ])->filter()->implode("\n"),
        ])->values()->all();

        $facts = array_values(array_filter([
            ['label' => 'Request', 'value' => (string) $request->request_number],
            ['label' => 'Station', 'value' => StationImageKey::label($request->station) ?? 'Unknown'],
            ['label' => 'Room', 'value' => $request->room?->name ?: ($request->room_name_snapshot ?: 'Station-wide')],
            ['label' => 'Type', 'value' => StationRequestType::tryFrom((string) $request->request_type)?->label() ?? (string) $request->request_type],
            ['label' => 'Priority', 'value' => ucfirst((string) $request->priority)],
            ['label' => 'Status', 'value' => $status->label()],
            ['label' => 'Requested by', 'value' => (string) $request->requester_name_snapshot],
            ['label' => 'Assigned to', 'value' => $request->assignedTo?->name ?: ($request->assigned_vendor ?: 'Unassigned')],
            ['label' => 'Submitted', 'value' => $request->created_at?->timezone(OperationalDisplayWindow::TIMEZONE)->format('M j, Y g:i A') ?? ''],
            $request->items->isEmpty() ? null : ['label' => 'Items', 'value' => $request->items->map(fn ($item): string => $item->quantity.'× '.$item->item_name)->implode(', ')],
            filled($request->current_public_response) ? ['label' => 'Latest update to station', 'value' => (string) $request->current_public_response] : null,
        ]));

        $issue = $this->toIssue($request->setAttribute('updates_max_id', $request->updates->max('id')), false);
        $reason = match (true) {
            $request->isArchived() => 'This request is archived. Restore it from the full record to work on it.',
            ! $request->is_open => 'This request is '.strtolower($status->label()).'. Closed requests cannot be changed.',
            ! $this->canManage($user) => 'You can view station requests but not update them.',
            default => null,
        };

        return new IssueDetail($issue, (string) $request->description, $facts, $timeline, $reason === null ? $this->actionsFor($request) : [], $reason);
    }

    public function perform(User $user, string $id, string $action, array $input, string $expectedVersion): ActionResult
    {
        if (! $this->visibleTo($user, false) || ! $this->canManage($user)) {
            return ActionResult::forbidden();
        }
        if (! in_array($action, self::ACTIONS, true)) {
            return ActionResult::invalid(['action' => 'This action is not available.']);
        }

        return DB::transaction(function () use ($user, $id, $action, $input, $expectedVersion): ActionResult {
            $request = StationRequest::query()->lockForUpdate()->find($id);
            if (! $request instanceof StationRequest || $request->isArchived()) {
                return ActionResult::gone('This request no longer exists or was archived.');
            }
            $latest = StationRequestUpdate::query()->where('station_request_id', $request->getKey())->orderByDesc('id')->first();
            $data = $this->commandData($request, $action, $input);

            if (self::versionOf($request, (int) $latest?->getKey()) !== $expectedVersion) {
                return $this->isRepeat($request, $latest, $user, $data)
                    ? ActionResult::done('Already saved.')
                    : ActionResult::conflict();
            }
            if (! $request->is_open) {
                return ActionResult::invalid(['status' => 'This request is closed and cannot be changed.']);
            }
            $errors = $this->validateCommand($request, $action, $data);
            if ($errors !== []) {
                return ActionResult::invalid($errors);
            }

            try {
                $this->workflow->transition($request, $data, $user);
            } catch (ValidationException $exception) {
                return ActionResult::invalid(self::errorsOf($exception));
            } catch (HttpExceptionInterface $exception) {
                if ($exception->getStatusCode() === 403) {
                    return ActionResult::forbidden();
                }
                throw $exception;
            }

            return ActionResult::done(match ($action) {
                'acknowledge' => 'Request acknowledged.',
                'approve' => 'Request approved.',
                'assign' => 'Request assigned.',
                'post_update' => 'Update sent to the station.',
                'complete' => 'Request completed.',
                'deny' => 'Request denied.',
                default => 'Status updated.',
            });
        });
    }

    /** @return list<IssueAction> */
    private function actionsFor(StationRequest $request): array
    {
        $note = fn (string $label, bool $required = false, int $max = 5000): array => ['name' => 'public_note', 'label' => $label, 'type' => 'textarea', 'required' => $required, 'max' => $max];
        $internal = ['name' => 'internal_note', 'label' => 'Internal note (admins only)', 'type' => 'textarea', 'required' => false, 'max' => 5000];
        $status = Status::from($request->status);
        $actions = [];

        if ($status === Status::Pending) {
            $actions[] = new IssueAction('acknowledge', 'Acknowledge', 'primary', [$note('Update to station (optional)')]);
        }
        if (in_array($status, [Status::Pending, Status::Acknowledged, Status::UnderReview], true)) {
            $actions[] = new IssueAction('approve', 'Approve', $status === Status::Pending ? 'secondary' : 'primary', [$note('Update to station (optional)')]);
        }
        $actions[] = new IssueAction('assign', 'Assign', 'secondary', [[
            'name' => 'assigned_to_user_id', 'label' => 'Assign to', 'type' => 'select', 'required' => true,
            'options' => $this->assignees(), 'value' => $request->assigned_to_user_id,
        ]]);
        $actions[] = new IssueAction('set_status', 'Set progress', 'secondary', [
            ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'required' => true, 'options' => collect(Status::openValues())
                ->reject(fn (string $value): bool => $value === $status->value)
                ->mapWithKeys(fn (string $value): array => [$value => Status::from($value)->label()])->all()],
            $note('Update to station (optional)'),
            $internal,
        ]);
        $actions[] = new IssueAction('post_update', 'Send update to station', 'secondary', [$note('Update to station', true), $internal]);
        $actions[] = new IssueAction('complete', 'Mark completed', 'primary', [$note('Update to station (optional)'), $internal],
            'Mark this request completed? Recording room assets is done from the full record.');
        $actions[] = new IssueAction('deny', 'Deny', 'danger', [$note('Reason (shown to the station)', true, DecisionReason::MAX_LENGTH), $internal],
            'Deny this request? The station sees your reason.');

        return $actions;
    }

    /**
     * Translate a dashboard command into the resource form's data for transition().
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function commandData(StationRequest $request, string $action, array $input): array
    {
        $data = [
            'status' => match ($action) {
                'acknowledge' => Status::Acknowledged->value,
                'approve' => Status::Approved->value,
                'set_status' => self::text($input['status'] ?? ''),
                'complete' => Status::Completed->value,
                'deny' => Status::Denied->value,
                default => $request->status,
            },
            'public_note' => self::text($input['public_note'] ?? '') ?: null,
            'internal_note' => self::text($input['internal_note'] ?? '') ?: null,
        ];
        if ($action === 'assign') {
            $data = ['status' => $request->status, 'public_note' => null, 'internal_note' => null,
                'assigned_to_user_id' => (int) self::text($input['assigned_to_user_id'] ?? '') ?: null];
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, string>
     */
    private function validateCommand(StationRequest $request, string $action, array $data): array
    {
        return array_filter([
            'status' => $action === 'set_status' && ! in_array($data['status'], Status::openValues(), true)
                ? 'Choose one of the listed statuses.' : null,
            'public_note' => match (true) {
                $action === 'post_update' && $data['public_note'] === null => 'Write the update for the station.',
                mb_strlen((string) $data['public_note']) > 5000 => 'Updates can be up to 5,000 characters.',
                default => null,
            },
            'internal_note' => mb_strlen((string) $data['internal_note']) > 5000 ? 'Notes can be up to 5,000 characters.' : null,
            'assigned_to_user_id' => $action === 'assign' && ! array_key_exists((int) $data['assigned_to_user_id'], $this->assignees())
                ? 'Choose someone who manages station requests.' : null,
        ]);
    }

    /** An identical command this admin already saved: the newest history row is theirs and matches. */
    private function isRepeat(StationRequest $request, ?StationRequestUpdate $latest, User $user, array $data): bool
    {
        return $latest !== null
            && (int) $latest->changed_by_user_id === (int) $user->getKey()
            && $request->status === $data['status'] && $latest->status === $data['status']
            && $latest->public_note === $data['public_note'] && $latest->internal_note === $data['internal_note']
            && (! array_key_exists('assigned_to_user_id', $data) || (int) $request->assigned_to_user_id === (int) $data['assigned_to_user_id']);
    }

    private function canManage(User $user): bool
    {
        return $user->can('admin.stations.manage', 'web');
    }

    /** @return array<int, string> active admins who manage station requests */
    private function assignees(): array
    {
        return User::query()
            ->where(fn (Builder $q) => $q
                ->whereHas('permissions', fn (Builder $p) => $p->where('name', 'admin.stations.manage'))
                ->orWhereHas('roles', fn (Builder $r) => $r->where(fn (Builder $any) => $any->where('name', 'super_admin')
                    ->orWhereHas('permissions', fn (Builder $p) => $p->where('name', 'admin.stations.manage')))))
            ->with(['roles.permissions', 'permissions'])->orderBy('name')->limit(200)->get()
            ->filter(fn (User $u): bool => $u->isAuthenticationAllowed() && $u->can('admin.stations.manage', 'web'))
            ->mapWithKeys(fn (User $u): array => [(int) $u->getKey() => (string) $u->name])->all();
    }

    private static function versionOf(StationRequest $request, int $latestUpdateId): string
    {
        return self::stamp($request->updated_at).'#'.$latestUpdateId;
    }

    private static function severity(string $priority): string
    {
        return match ($priority) {
            'critical', 'high' => 'high',
            'normal' => 'medium',
            default => 'low',
        };
    }

    private static function stage(Status $status): string
    {
        return match (true) {
            $status === Status::Pending => 'awaiting_response',
            in_array($status->value, Status::terminalValues(), true) => 'resolved',
            default => 'in_progress',
        };
    }
}
