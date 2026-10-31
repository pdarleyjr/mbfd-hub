<?php

declare(strict_types=1);

namespace App\Services\Dashboards\Sources\Vehicle;

use App\Enums\ApparatusServiceTicketCategory;
use App\Enums\ApparatusServiceTicketPriority;
use App\Enums\ApparatusServiceTicketStatus as Status;
use App\Filament\Resources\ApparatusServiceTicketResource;
use App\Models\ApparatusServiceTicket;
use App\Models\ApparatusServiceTicketUpdate;
use App\Models\User;
use App\Services\ApparatusServiceTicketWorkflowService;
use App\Services\Dashboards\Support\ApparatusClass;
use App\Services\Dashboards\Support\StaleVersion;
use App\Services\Dashboards\Support\VehicleIssueSource;
use App\Support\Dashboards\ActionResult;
use App\Support\Dashboards\DashboardIssue;
use App\Support\Dashboards\IssueAction;
use App\Support\Dashboards\IssueDetail;
use App\Support\HubTimezone;
use App\Support\OperationalDisplayWindow;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Apparatus service / maintenance tickets, worked through ApparatusServiceTicketWorkflowService
 * (transition, addNote) exactly as ViewApparatusServiceTicket does, so its append-only update,
 * requester notification and cache side effects fire unchanged.
 *
 * Visibility and actions: ApparatusServiceTicketPolicy viewAny / update (the resource's rule).
 *
 * Severity (priority): urgent -> high, attention -> medium, routine -> low.
 * Stage (status): submitted -> awaiting_response; acknowledged / scheduled / in progress /
 * waiting for parts -> in_progress; completed -> resolved; cancelled -> other.
 * Row set: unarchived open tickets, plus tickets completed (completed_at) or cancelled
 * (cancelled_at) in the operational day. Logged preventive maintenance (origin "pm") is a service
 * record that is created already completed, never an open issue, so it is not on the board.
 * Source tag "repair": every service ticket.
 *
 * Version: "{status}:{last update id}". Every workflow write appends an update row, so the token
 * changes with every change of state or note; perform() compares it with the row locked.
 *
 * @extends VehicleIssueSource<ApparatusServiceTicket>
 */
final class ApparatusServiceTicketSource extends VehicleIssueSource
{
    private const SEVERITY = "CASE apparatus_service_tickets.priority WHEN 'urgent' THEN 'high' WHEN 'attention' THEN 'medium' ELSE 'low' END";

    private const STAGE = "CASE WHEN apparatus_service_tickets.status = 'submitted' THEN 'awaiting_response'"
        ." WHEN apparatus_service_tickets.status = 'completed' THEN 'resolved' WHEN apparatus_service_tickets.status = 'cancelled' THEN 'other' ELSE 'in_progress' END";

    private const OCCURRED = "CASE apparatus_service_tickets.status WHEN 'completed' THEN apparatus_service_tickets.completed_at"
        ." WHEN 'cancelled' THEN apparatus_service_tickets.cancelled_at ELSE apparatus_service_tickets.created_at END";

    /** Dashboard action key => target status. */
    private const TRANSITIONS = [
        'acknowledge' => Status::Acknowledged,
        'schedule' => Status::Scheduled,
        'start_work' => Status::InProgress,
        'wait_for_parts' => Status::WaitingForParts,
        'complete' => Status::Completed,
        'cancel' => Status::Cancelled,
    ];

    /** Roles ApparatusServiceTicketPolicy lets manage tickets: the people offered in "Assign to". */
    private const MANAGER_ROLES = ['super_admin', 'admin', 'logistics_admin'];

    public function type(): string
    {
        return 'service_ticket';
    }

    public function visibleTo(User $user, bool $displayMode): bool
    {
        return Gate::forUser($user)->allows('viewAny', ApparatusServiceTicket::class);
    }

    protected function newQuery(): Builder
    {
        return ApparatusServiceTicket::query();
    }

    protected function scopeToBoard(Builder $query, OperationalDisplayWindow $window): void
    {
        [$start, $end] = [$window->databaseStart(), $window->databaseEnd()];
        $query->whereNull('apparatus_service_tickets.archived_at')
            ->where(fn (Builder $origin) => $origin->whereNull('apparatus_service_tickets.origin')->orWhere('apparatus_service_tickets.origin', '<>', 'pm'))
            ->where(fn (Builder $board) => $board
                ->whereIn('apparatus_service_tickets.status', Status::openValues())
                ->orWhere(fn (Builder $done) => $done->where('apparatus_service_tickets.status', Status::Completed->value)
                    ->where('apparatus_service_tickets.completed_at', '>=', $start)->where('apparatus_service_tickets.completed_at', '<', $end))
                ->orWhere(fn (Builder $cancelled) => $cancelled->where('apparatus_service_tickets.status', Status::Cancelled->value)
                    ->where('apparatus_service_tickets.cancelled_at', '>=', $start)->where('apparatus_service_tickets.cancelled_at', '<', $end)));
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
        return ['repair' => '1 = 1'];
    }

    protected function eagerLoads(): array
    {
        return ['apparatus:'.implode(',', ApparatusClass::COLUMNS)];
    }

    protected function rowSelects(): array
    {
        return ['dashboard_last_update_id' => ApparatusServiceTicketUpdate::query()->selectRaw('MAX(id)')
            ->whereColumn('apparatus_service_ticket_updates.apparatus_service_ticket_id', 'apparatus_service_tickets.id')];
    }

    protected function toIssue(Model $record, bool $displayMode, array $projection): DashboardIssue
    {
        $status = Status::tryFrom((string) $record->status);

        return new DashboardIssue(
            type: $this->type(),
            id: (string) $record->getKey(),
            title: trim((string) $record->title) ?: (string) $record->ticket_number,
            summary: $displayMode ? null : (Str::limit(trim((string) $record->description), 280) ?: null),
            severity: $projection['severity'],
            status: (string) $record->status,
            statusLabel: $status?->label() ?? str((string) $record->status)->headline()->toString(),
            stage: $projection['stage'],
            tags: $projection['tags'],
            subject: $projection['subject'] ?? (trim((string) $record->unit_designation_snapshot) ?: null),
            imageKey: $projection['imageKey'],
            actorLabel: $displayMode ? null : (trim((string) $record->requester_name_snapshot) ?: null),
            categoryLabel: ApparatusServiceTicketCategory::options()[(string) $record->category] ?? 'Service ticket',
            occurredAt: $projection['occurredAt'],
            version: $record->status.':'.(int) $record->getAttribute('dashboard_last_update_id'),
            sourceUrl: ApparatusServiceTicketResource::getUrl('view', ['record' => $record->getKey()], panel: 'admin'),
        );
    }

    // ------------------------------------------------------------------ detail

    public function detail(User $user, string $id): ?IssueDetail
    {
        if (! $this->visibleTo($user, false) || ($fresh = $this->freshIssue($id)) === null) {
            return null;
        }
        $ticket = $fresh['record'];
        assert($ticket instanceof ApparatusServiceTicket);
        if (! Gate::forUser($user)->allows('view', $ticket)) {
            return null;
        }
        $ticket->load(['assignedTo:id,name,display_name', 'updates.changedByUser:id,name,display_name', 'updates.changedByEmployee:id,name']);
        $status = Status::from((string) $ticket->status);
        $canUpdate = ! $ticket->isArchived() && Gate::forUser($user)->allows('update', $ticket);

        $facts = array_values(array_filter([
            ['label' => 'Ticket', 'value' => (string) $ticket->ticket_number],
            ['label' => 'Unit', 'value' => (string) ($fresh['issue']->subject ?? $ticket->unit_designation_snapshot)],
            ['label' => 'Category', 'value' => (string) $fresh['issue']->categoryLabel],
            ['label' => 'Priority', 'value' => ApparatusServiceTicketPriority::options()[(string) $ticket->priority] ?? (string) $ticket->priority],
            ['label' => 'Status', 'value' => $status->label()],
            ['label' => 'Requested by', 'value' => trim((string) $ticket->requester_name_snapshot)],
            ['label' => 'Assigned to', 'value' => (string) self::personName($ticket->assignedTo)],
            ['label' => 'Vendor', 'value' => trim((string) $ticket->assigned_vendor)],
            ['label' => 'Service type', 'value' => trim((string) $ticket->service_type)],
            ['label' => 'Scheduled for', 'value' => (string) self::local($ticket->scheduled_for)],
            ['label' => 'Service location', 'value' => trim((string) $ticket->scheduled_location)],
            ['label' => 'Expected return', 'value' => (string) self::local($ticket->expected_return_at)],
            ['label' => 'Current public update', 'value' => trim((string) $ticket->current_public_response)],
            ['label' => 'Resolution', 'value' => trim((string) $ticket->resolution_summary)],
            ['label' => 'Opened', 'value' => (string) self::local($ticket->created_at)],
        ], static fn (array $fact): bool => $fact['value'] !== ''));

        $timeline = $ticket->updates->map(static function (ApparatusServiceTicketUpdate $update): array {
            $to = Status::tryFrom((string) $update->status)?->label() ?? (string) $update->status;
            $from = $update->previous_status !== null ? (Status::tryFrom((string) $update->previous_status)?->label() ?? (string) $update->previous_status) : null;

            return [
                'at' => CarbonImmutable::parse($update->created_at),
                'actor' => self::personName($update->changedByUser) ?? (trim((string) $update->changedByEmployee?->name) ?: null),
                'text' => implode("\n", array_filter([
                    $from === null || $from === $to ? 'Status: '.$to : 'Status: '.$from.' → '.$to,
                    filled($update->public_note) ? 'Public update: '.$update->public_note : null,
                    filled($update->internal_note) ? 'Internal note: '.$update->internal_note : null,
                ])),
            ];
        })->values()->all();

        return new IssueDetail(
            issue: $fresh['issue'],
            body: trim((string) $ticket->description) ?: null,
            facts: $facts,
            timeline: $timeline,
            actions: $canUpdate ? $this->actionsFor($status) : [],
            readOnlyReason: match (true) {
                $ticket->isArchived() => 'This ticket is archived.',
                ! $canUpdate => 'Only Fleet ticket managers can update service tickets.',
                default => null,
            },
        );
    }

    /** @return list<IssueAction> */
    private function actionsFor(Status $status): array
    {
        $notes = [
            self::textarea('public_note', 'Public update, seen by station staff (optional)', max: 5000),
            self::textarea('internal_note', 'Internal note, Fleet only (optional)', max: 10000),
        ];
        $assign = self::select('assigned_to_user_id', 'Assign to (optional)', $this->managers(), false);
        $actions = [];
        foreach (self::TRANSITIONS as $key => $target) {
            if (! $status->canTransitionTo($target)) {
                continue;
            }
            $actions[] = match ($target) {
                Status::Acknowledged => new IssueAction($key, 'Acknowledge', 'primary', [$assign, ...$notes], bulk: IssueAction::BULK_DIRECT),
                Status::Scheduled => new IssueAction($key, 'Schedule', 'secondary', [
                    ['name' => 'scheduled_for', 'label' => 'Scheduled for (Eastern time)', 'type' => 'date', 'required' => true],
                    ['name' => 'service_type', 'label' => 'Service type', 'type' => 'text', 'required' => true, 'max' => 255],
                    ['name' => 'scheduled_location', 'label' => 'Service location', 'type' => 'text', 'required' => true, 'max' => 255],
                    $assign,
                    self::textarea('public_note', 'Public update, seen by station staff', true, 5000),
                    $notes[1],
                ]),
                Status::InProgress => new IssueAction($key, 'Start work', 'secondary', [$assign, ...$notes]),
                Status::WaitingForParts => new IssueAction($key, 'Waiting for parts', 'secondary', $notes),
                Status::Completed => new IssueAction($key, 'Complete', 'primary', [self::textarea('resolution_summary', 'Resolution summary', true, 10000), ...$notes]),
                // No default arm on purpose: a transition added to TRANSITIONS without its own arm is reported by
                // PHPStan (match.unhandled) and fails loudly at runtime, instead of rendering as the wrong button.
                Status::Cancelled => new IssueAction($key, 'Cancel ticket', 'danger', $notes, 'Cancel this service ticket? It cannot be reopened.'),
            };
        }
        $actions[] = new IssueAction('add_note', 'Add note', 'secondary', $notes, bulk: IssueAction::BULK_FORM);

        return $actions;
    }

    /** @return array<int, string> active accounts holding a ticket-managing role */
    private function managers(): array
    {
        return User::query()
            ->whereHas('roles', fn ($roles) => $roles->whereIn('name', self::MANAGER_ROLES))
            ->orderBy('name')
            ->get()
            ->filter(static fn (User $user): bool => $user->isAuthenticationAllowed())
            ->mapWithKeys(static fn (User $user): array => [(int) $user->getKey() => (string) self::personName($user)])
            ->all();
    }

    // ----------------------------------------------------------------- perform

    public function perform(User $user, string $id, string $action, array $input, string $expectedVersion): ActionResult
    {
        return $this->attempt(function () use ($user, $id, $action, $input, $expectedVersion): ActionResult {
            $ticketId = self::intId($id);
            $ticket = $ticketId === null ? null : ApparatusServiceTicket::query()->find($ticketId);
            if (! $ticket instanceof ApparatusServiceTicket || $ticket->isArchived()) {
                return ActionResult::gone();
            }
            if (! Gate::forUser($user)->allows('update', $ticket)) {
                return ActionResult::forbidden();
            }
            if ($action !== 'add_note' && ! isset(self::TRANSITIONS[$action])) {
                return ActionResult::invalid(['action' => 'That action is not available for this ticket.'], 'That action is not available for this ticket.');
            }
            $data = $this->payload($action, $input);

            return DB::transaction(function () use ($user, $ticketId, $action, $data, $expectedVersion): ActionResult {
                $locked = ApparatusServiceTicket::query()->lockForUpdate()->findOrFail($ticketId);
                $target = self::TRANSITIONS[$action] ?? null;
                if ($target !== null && $locked->status === $target->value) {
                    // A repeat tap changes nothing, but what an admin typed must not vanish behind "already".
                    return array_filter($data, static fn (mixed $value): bool => $value !== null) === []
                        ? ActionResult::done('Already '.mb_strtolower($target->label()).'.')
                        : ActionResult::conflict();
                }
                $lastUpdate = (int) ApparatusServiceTicketUpdate::query()->where('apparatus_service_ticket_id', $locked->getKey())->max('id');
                if (! hash_equals($locked->status.':'.$lastUpdate, $expectedVersion)) {
                    throw new StaleVersion;
                }
                $workflow = app(ApparatusServiceTicketWorkflowService::class);
                if ($target === null) {
                    $workflow->addNote($locked, $user, $data);

                    return ActionResult::done('Ticket note added.');
                }
                $workflow->transition($locked, $user, $target, $data);

                return ActionResult::done('Ticket marked '.$target->label().'.');
            }, 3);
        });
    }

    /**
     * The same fields the ticket page's action form sends. Times are entered in Eastern time and
     * stored in UTC, as the admin date-time picker does.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function payload(string $action, array $input): array
    {
        $data = ['public_note' => self::text($input, 'public_note'), 'internal_note' => self::text($input, 'internal_note')];
        if ($action === 'add_note') {
            return $data;
        }
        if (($assignee = self::text($input, 'assigned_to_user_id')) !== null) {
            if (! array_key_exists((int) $assignee, $this->managers()) || self::intId($assignee) === null) {
                throw ValidationException::withMessages(['assigned_to_user_id' => 'Choose someone from the list.']);
            }
            $data['assigned_to_user_id'] = (int) $assignee;
        }
        if ($action === 'schedule') {
            if (self::text($input, 'public_note') === null) {
                throw ValidationException::withMessages(['public_note' => 'Enter the public update for station staff.']);
            }
            $data['scheduled_for'] = self::utc(self::text($input, 'scheduled_for'), 'scheduled_for');
            $data['service_type'] = self::text($input, 'service_type');
            $data['scheduled_location'] = self::text($input, 'scheduled_location');
        }
        if ($action === 'complete') {
            $data['resolution_summary'] = self::text($input, 'resolution_summary');
            if ($data['resolution_summary'] === null) {
                throw ValidationException::withMessages(['resolution_summary' => 'Describe how the work was completed.']);
            }
        }

        return array_filter($data, static fn ($value): bool => $value !== null);
    }

    private static function utc(?string $value, string $field): ?string
    {
        if ($value === null) {
            return null;
        }
        try {
            return CarbonImmutable::parse($value, HubTimezone::NAME)->utc()->format('Y-m-d H:i:s');
        } catch (Throwable) {
            throw ValidationException::withMessages([$field => 'Enter a valid date and time.']);
        }
    }
}
