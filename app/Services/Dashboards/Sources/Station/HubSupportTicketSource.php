<?php

declare(strict_types=1);

namespace App\Services\Dashboards\Sources\Station;

use App\Enums\HubSupportTicketCategory as Category;
use App\Enums\HubSupportTicketImpact as Impact;
use App\Enums\HubSupportTicketStatus as Status;
use App\Filament\Resources\HubSupportTicketResource;
use App\Models\HubSupportTicket;
use App\Models\HubSupportTicketUpdate;
use App\Models\User;
use App\Services\HubSupport\HubSupportTicketWorkflowService;
use App\Support\Dashboards\ActionResult;
use App\Support\Dashboards\DashboardIssue;
use App\Support\Dashboards\DashboardView;
use App\Support\Dashboards\IssueAction;
use App\Support\Dashboards\IssueDetail;
use App\Support\OperationalDisplayWindow;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * "Report a Hub problem" tickets (HubSupportTicketResource): problems with the Hub itself, so
 * every category is tagged "access_it". Visibility is HubSupportTicketPolicy (admin.support.view /
 * .manage); never shown on the wall display because reports name and quote members.
 *
 * Severity from impact: feature_unavailable → high, task_blocking → medium, minor → low.
 * Stage: new → awaiting_response; acknowledged/in_progress/waiting_for_reporter → in_progress;
 * resolved/closed → resolved (in the window by resolved_at/closed_at). Every action goes through
 * HubSupportTicketWorkflowService::transition() with a deterministic client_action_id, so an
 * identical repeat is replayed by the domain itself.
 *
 * @extends QueryBackedSource<HubSupportTicket>
 */
final class HubSupportTicketSource extends QueryBackedSource
{
    /** action => target status (null: keep the current status) */
    private const ACTIONS = [
        'acknowledge' => Status::Acknowledged,
        'start' => Status::InProgress,
        'request_info' => Status::WaitingForReporter,
        'resolve' => Status::Resolved,
        'close' => Status::Closed,
        'reply' => null,
        'assign' => null,
    ];

    public function __construct(private readonly HubSupportTicketWorkflowService $workflow) {}

    public function type(): string
    {
        return 'hub_support_ticket';
    }

    public function views(): array
    {
        return [DashboardView::StationOperations, DashboardView::Overview];
    }

    public function visibleTo(User $user, bool $displayMode): bool
    {
        return ! $displayMode && $user->can('viewAny', HubSupportTicket::class);
    }

    protected function baseQuery(): Builder
    {
        return HubSupportTicket::query()->active();
    }

    protected function whereOpen(Builder $query): void
    {
        $query->whereNotIn('status', [Status::Resolved->value, Status::Closed->value]);
    }

    protected function whereStage(Builder $query, string $stage, OperationalDisplayWindow $window): void
    {
        match ($stage) {
            'awaiting_response' => $query->where('status', Status::New->value),
            'in_progress' => $query->whereIn('status', [Status::Acknowledged->value, Status::InProgress->value, Status::WaitingForReporter->value]),
            'resolved' => $query->where(function (Builder $q) use ($window): void {
                foreach ([Status::Resolved->value => 'resolved_at', Status::Closed->value => 'closed_at'] as $status => $column) {
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
            'high' => $query->where('impact', Impact::FeatureUnavailable->value),
            'medium' => $query->where('impact', Impact::TaskBlocking->value),
            'low' => $query->where('impact', Impact::Minor->value),
            default => self::nothing($query),
        };
    }

    protected function tagConstraints(): array
    {
        return ['access_it' => fn (Builder $q) => $q->whereIn('category', array_map(fn (Category $c): string => $c->value, Category::cases()))];
    }

    protected function prepareRows(Builder $query): void
    {
        $query->withMax('updates', 'id')
            ->orderByRaw("CASE WHEN status IN ('resolved','closed') THEN 1 ELSE 0 END")
            ->orderByRaw("CASE impact WHEN 'feature_unavailable' THEN 0 WHEN 'task_blocking' THEN 1 ELSE 2 END")
            ->orderByDesc('created_at')->orderByDesc('id');
    }

    /** @param HubSupportTicket $record */
    protected function toIssue(Model $record, bool $displayMode): DashboardIssue
    {
        return new DashboardIssue(
            type: $this->type(),
            id: (string) $record->getKey(),
            title: (string) $record->generated_title,
            summary: str((string) $record->description)->squish()->limit(160)->toString() ?: null,
            severity: match ($record->impact) {
                Impact::FeatureUnavailable => 'high',
                Impact::TaskBlocking => 'medium',
                default => 'low',
            },
            status: $record->status->value,
            statusLabel: self::statusLabel($record->status),
            stage: match ($record->status) {
                Status::New => 'awaiting_response',
                Status::Resolved, Status::Closed => 'resolved',
                default => 'in_progress',
            },
            tags: ['access_it'],
            subject: null,
            imageKey: null,
            actorLabel: $displayMode ? null : ($record->reporter_name_snapshot ?: null),
            categoryLabel: $record->category->label(),
            occurredAt: CarbonImmutable::instance($record->created_at),
            version: self::stamp($record->updated_at).'#'.(int) $record->getAttribute('updates_max_id'),
            sourceUrl: HubSupportTicketResource::getUrl('view', ['record' => $record->getKey()], panel: 'admin'),
            groupKey: 'member:'.(int) $record->reported_by_user_id,
        );
    }

    public function detail(User $user, string $id): ?IssueDetail
    {
        $ticket = HubSupportTicket::withTrashed()->with(['assignedTo:id,name', 'updates.changedBy:id,name'])->find($id);
        if (! $ticket instanceof HubSupportTicket || ! $this->visibleTo($user, false) || ! $user->can('view', $ticket)) {
            return null;
        }

        $timeline = $ticket->updates->map(fn (HubSupportTicketUpdate $update): array => [
            'at' => CarbonImmutable::instance($update->created_at),
            'actor' => $update->changedBy->name ?? 'System',
            'text' => collect([
                'Status: '.self::statusLabel($update->status),
                filled($update->public_response) ? 'Reply to member: '.$update->public_response : null,
                filled($update->internal_note) ? 'Internal note: '.$update->internal_note : null,
                filled($update->metadata['resolution_summary'] ?? null) ? 'Resolution: '.$update->metadata['resolution_summary'] : null,
            ])->filter()->implode("\n"),
        ])->values()->all();
        $facts = [
            ['label' => 'Report', 'value' => (string) $ticket->ticket_number],
            ['label' => 'Category', 'value' => $ticket->category->label()],
            ['label' => 'Impact', 'value' => $ticket->impact->label()],
            ['label' => 'Status', 'value' => self::statusLabel($ticket->status)],
            ['label' => 'Reported by', 'value' => (string) $ticket->reporter_name_snapshot],
            ['label' => 'Assigned to', 'value' => $ticket->assignedTo->name ?? 'Unassigned'],
            ['label' => 'Page', 'value' => (string) ($ticket->page_path ?: '—')],
        ];

        $issue = $this->toIssue($ticket->setAttribute('updates_max_id', $ticket->updates->max('id')), false);
        $reason = match (true) {
            $ticket->trashed() => 'This report is in the trash. Restore it from the full record first.',
            $ticket->isArchived() => 'This report is archived. Restore it from the full record first.',
            ! $user->can('update', $ticket) => 'You can view Hub problem reports but not manage them.',
            default => null,
        };

        return new IssueDetail($issue, (string) $ticket->description, $facts, $timeline, $reason === null ? $this->actionsFor($ticket) : [], $reason);
    }

    public function perform(User $user, string $id, string $action, array $input, string $expectedVersion): ActionResult
    {
        if (! $this->visibleTo($user, false)) {
            return ActionResult::forbidden();
        }
        if (! array_key_exists($action, self::ACTIONS)) {
            return ActionResult::invalid(['action' => 'This action is not available.']);
        }

        return DB::transaction(function () use ($user, $id, $action, $input, $expectedVersion): ActionResult {
            $ticket = HubSupportTicket::query()->lockForUpdate()->find($id);
            if (! $ticket instanceof HubSupportTicket || $ticket->isArchived()) {
                return ActionResult::gone('This report no longer exists or was archived.');
            }
            if (! $user->can('update', $ticket)) {
                return ActionResult::forbidden();
            }
            $data = $this->commandData($action, $input);
            $actionId = Uuid::uuid5(Uuid::NAMESPACE_URL, implode('|', ['mbfd-dashboard', $user->getKey(), $ticket->getKey(), $expectedVersion, $action, json_encode($data)]))->toString();
            $replayed = HubSupportTicketUpdate::query()->where('hub_support_ticket_id', $ticket->getKey())->where('client_action_id', $actionId)->first();
            if ($replayed !== null) {
                return (int) $replayed->changed_by_user_id === (int) $user->getKey() ? ActionResult::done('Already saved.') : ActionResult::conflict();
            }
            $latestUpdateId = (int) HubSupportTicketUpdate::query()->where('hub_support_ticket_id', $ticket->getKey())->max('id');
            if (self::stamp($ticket->updated_at).'#'.$latestUpdateId !== $expectedVersion) {
                return ActionResult::conflict();
            }

            $target = self::ACTIONS[$action] ?? $ticket->status;
            if ($target !== $ticket->status && ! $ticket->status->canTransitionTo($target)) {
                return ActionResult::invalid(['status' => 'This status change is not available right now.']);
            }
            $errors = array_filter([
                'public_response' => $action === 'reply' && ($data['public_response'] ?? '') === '' ? 'Write the reply for the member.' : null,
                'assigned_to_user_id' => $action === 'assign' && ! array_key_exists((int) ($data['assigned_to_user_id'] ?? 0), $this->assignees($ticket))
                    ? 'Choose someone who manages Hub problem reports.' : null,
            ]);
            if ($errors !== []) {
                return ActionResult::invalid($errors);
            }

            try {
                $this->workflow->transition($ticket, $user, $target, $data + ['client_action_id' => $actionId, 'expected_update_id' => $latestUpdateId]);
            } catch (ValidationException $exception) {
                return ActionResult::invalid(self::errorsOf($exception));
            } catch (HttpExceptionInterface $exception) {
                if ($exception->getStatusCode() === 403) {
                    return ActionResult::forbidden();
                }
                throw $exception;
            }

            return ActionResult::done(match ($action) {
                'reply' => 'Reply sent to the member.',
                'assign' => 'Report assigned.',
                default => 'Report marked '.strtolower(self::statusLabel($target)).'.',
            });
        });
    }

    /** @return list<IssueAction> */
    private function actionsFor(HubSupportTicket $ticket): array
    {
        $reply = fn (bool $required): array => ['name' => 'public_response', 'label' => $required ? 'Message to the member' : 'Message to the member (optional)', 'type' => 'textarea', 'required' => $required, 'max' => 5000];
        $internal = ['name' => 'internal_note', 'label' => 'Internal note (admins only)', 'type' => 'textarea', 'required' => false, 'max' => 10000];
        $actions = [];
        foreach (self::ACTIONS as $key => $target) {
            if ($target === null || ! $ticket->status->canTransitionTo($target)) {
                continue;
            }
            $actions[] = match ($target) {
                Status::Acknowledged => new IssueAction($key, 'Acknowledge', 'primary', [$reply(false), $internal], bulk: IssueAction::BULK_DIRECT),
                Status::InProgress => new IssueAction($key, in_array($ticket->status, [Status::Resolved, Status::Closed], true) ? 'Reopen' : 'Start work', 'secondary', [$reply(false), $internal]),
                Status::WaitingForReporter => new IssueAction($key, 'Ask the member for details', 'secondary', [$reply(true), $internal]),
                Status::Resolved => new IssueAction($key, 'Resolve', 'primary', [
                    ['name' => 'resolution_summary', 'label' => 'How it was fixed', 'type' => 'textarea', 'required' => true, 'max' => 10000], $reply(false), $internal,
                ]),
                default => new IssueAction($key, 'Close', 'secondary', [$reply(false), $internal], 'Close this report?'),
            };
        }
        $actions[] = new IssueAction('reply', 'Reply to member', 'secondary', [$reply(true), $internal]);
        $actions[] = new IssueAction('assign', 'Assign', 'secondary', [[
            'name' => 'assigned_to_user_id', 'label' => 'Assign to', 'type' => 'select', 'required' => true,
            'options' => $this->assignees($ticket), 'value' => $ticket->assigned_to_user_id,
        ]], bulk: IssueAction::BULK_FORM);

        return $actions;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function commandData(string $action, array $input): array
    {
        if ($action === 'assign') {
            return ['assigned_to_user_id' => (int) self::text($input['assigned_to_user_id'] ?? '') ?: null];
        }
        $data = array_filter([
            'public_response' => self::text($input['public_response'] ?? ''),
            'internal_note' => self::text($input['internal_note'] ?? ''),
        ], fn (string $value): bool => $value !== '');
        if ($action === 'resolve') {
            $data['resolution_summary'] = self::text($input['resolution_summary'] ?? '');
        }
        if ($action === 'reply') {
            $data['public_response'] ??= '';
        }

        return $data;
    }

    /** Same eligibility as the resource's "Assign / Update" action. @return array<int, string> */
    private function assignees(HubSupportTicket $ticket): array
    {
        return User::query()->where(fn (Builder $q) => $q
            ->whereHas('permissions', fn (Builder $p) => $p->where('name', 'admin.support.manage'))
            ->orWhereHas('roles', fn (Builder $r) => $r->where('name', 'super_admin')))
            ->orderBy('name')->limit(200)->get()
            ->filter(fn (User $u): bool => $u->isAuthenticationAllowed() && $u->hasCurrentAdminPanelEntitlement() && $u->can('update', $ticket))
            ->mapWithKeys(fn (User $u): array => [(int) $u->getKey() => (string) $u->name])->all();
    }

    private static function statusLabel(Status $status): string
    {
        return match ($status) {
            Status::New => 'New',
            Status::WaitingForReporter => 'Waiting for the member',
            default => ucfirst(str_replace('_', ' ', $status->value)),
        };
    }
}
