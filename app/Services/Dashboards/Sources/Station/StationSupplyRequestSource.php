<?php

declare(strict_types=1);

namespace App\Services\Dashboards\Sources\Station;

use App\Filament\Resources\StationSupplyRequestResource;
use App\Models\Station;
use App\Models\StationInventoryAudit;
use App\Models\StationSupplyRequest;
use App\Models\User;
use App\Services\Dashboards\Support\RecentlyClosedSource;
use App\Services\StationSupplyRequestWorkflowService;
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
 * Station supply requests a member sends from the station inventory screen (station_supply_requests), worked on the
 * Station Supply Requests list. Every change goes through StationSupplyRequestWorkflowService::update(), the same
 * call as that list's dialog (statuses open, ordered, replenished, denied; a denial needs a reason the member reads;
 * every change is written to the station inventory audit).
 *
 * Visibility: admin.stations.view; actions: admin.stations.manage (the service checks it again).
 * Stage: open -> awaiting_response; ordered -> in_progress; replenished / denied -> resolved. The table keeps no
 * closing time, so the last change (updated_at) of a closed request is when it closed. Severity: medium (no priority
 * on the form). Tag: supplies. One row per station.
 *
 * @extends RecentlyClosedSource<StationSupplyRequest>
 */
final class StationSupplyRequestSource extends RecentlyClosedSource
{
    private const OPEN = ['open', 'ordered'];

    private const CLOSED = ['replenished', 'denied'];

    private const LABELS = ['open' => 'Open', 'ordered' => 'Ordered', 'replenished' => 'Replenished', 'denied' => 'Denied'];

    public function __construct(private readonly StationSupplyRequestWorkflowService $workflow) {}

    public function type(): string
    {
        return 'station_supply_request';
    }

    public function views(): array
    {
        return [DashboardView::StationOperations, DashboardView::Overview];
    }

    /** Same gate as StationSupplyRequestResource::canViewAny(). Station supply rows may appear on the wall (no names there). */
    public function visibleTo(User $user, bool $displayMode): bool
    {
        return $user->can('admin.stations.view');
    }

    protected function baseQuery(): Builder
    {
        return StationSupplyRequest::query()->active();
    }

    protected function whereOpen(Builder $query): void
    {
        $query->whereIn('station_supply_requests.status', self::OPEN);
    }

    protected function whereStage(Builder $query, string $stage, OperationalDisplayWindow $window): void
    {
        match ($stage) {
            'awaiting_response' => $query->where('station_supply_requests.status', 'open'),
            'in_progress' => $query->where('station_supply_requests.status', 'ordered'),
            'resolved' => $query->whereIn('station_supply_requests.status', self::CLOSED)
                ->where(fn (Builder $q) => self::whereInWindow($q, 'station_supply_requests.updated_at', $window)),
            default => self::nothing($query),
        };
    }

    protected function whereClosedSince(Builder $query, CarbonImmutable $since): void
    {
        $query->whereIn('station_supply_requests.status', self::CLOSED)->where('station_supply_requests.updated_at', '>=', $since);
    }

    protected function whereSeverity(Builder $query, string $severity): void
    {
        if ($severity !== 'medium') {
            self::nothing($query);
        }
    }

    protected function requestTagConstraints(): array
    {
        return ['supplies' => fn (Builder $q) => $q->whereNotNull('station_supply_requests.id')];
    }

    protected function prepareRows(Builder $query): void
    {
        $query->with('station:id,station_number,name')
            ->orderByRaw("CASE WHEN station_supply_requests.status IN ('open','ordered') THEN 0 ELSE 1 END")
            ->orderByDesc('station_supply_requests.created_at')->orderByDesc('station_supply_requests.id');
    }

    /** @param StationSupplyRequest $record */
    protected function toIssue(Model $record, bool $displayMode): DashboardIssue
    {
        $status = (string) $record->status;
        $open = in_array($status, self::OPEN, true);

        return new DashboardIssue(
            type: $this->type(),
            id: (string) $record->getKey(),
            title: str((string) $record->request_text)->squish()->limit(120)->toString() ?: 'Supply request',
            summary: $displayMode ? null : (trim((string) $record->public_response) !== '' ? 'Reply: '.str((string) $record->public_response)->squish()->limit(120) : null),
            severity: 'medium',
            status: $status,
            statusLabel: self::LABELS[$status] ?? ucfirst($status),
            stage: match ($status) {
                'open' => 'awaiting_response',
                'ordered' => 'in_progress',
                default => in_array($status, self::CLOSED, true) ? 'resolved' : 'other',
            },
            tags: ['supplies'],
            subject: StationImageKey::label(self::stationOf($record)),
            imageKey: StationImageKey::for(self::stationOf($record)),
            actorLabel: $displayMode ? null : (trim((string) $record->created_by_name) ?: null),
            categoryLabel: 'Station supply request',
            occurredAt: self::at($open ? $record->created_at : $record->updated_at),
            version: self::stamp($record->updated_at),
            sourceUrl: StationSupplyRequestResource::getUrl('index', panel: 'admin'),
            groupKey: (int) $record->station_id > 0 ? 'station:'.(int) $record->station_id : null,
        );
    }

    public function detail(User $user, string $id): ?IssueDetail
    {
        if (! $this->visibleTo($user, false) || ($request = $this->find($id)) === null) {
            return null;
        }
        $request->loadMissing('station:id,station_number,name');
        $issue = $this->toIssue($request, false);
        $history = StationInventoryAudit::query()->where('station_id', $request->station_id)
            ->whereIn('action', ['request_updated', 'request_archived', 'request_restored'])
            ->orderBy('id')->limit(200)->get()
            ->filter(static fn (StationInventoryAudit $audit): bool => (int) ($audit->to_value['request_id'] ?? 0) === (int) $request->getKey());
        $timeline = $history->map(static fn (StationInventoryAudit $audit): array => [
            'at' => CarbonImmutable::instance($audit->created_at),
            'actor' => $audit->actor_name ?: 'Staff',
            'text' => implode("\n", array_filter([
                str((string) $audit->action)->replace('_', ' ')->ucfirst().(isset($audit->to_value['status']) ? ' · '.(self::LABELS[$audit->to_value['status']] ?? $audit->to_value['status']) : ''),
                filled($audit->to_value['public_response'] ?? null) ? 'To the station: '.$audit->to_value['public_response'] : null,
                filled($audit->to_value['admin_notes'] ?? null) ? 'Internal note: '.$audit->to_value['admin_notes'] : null,
            ])),
        ])->values()->all();
        $facts = array_values(array_filter([
            ['label' => 'Station', 'value' => (string) StationImageKey::label(self::stationOf($request))],
            ['label' => 'Requested by', 'value' => trim(implode(' · ', array_filter([(string) $request->created_by_name, (string) $request->created_by_shift])))],
            ['label' => 'Sent', 'value' => (string) $request->created_at?->timezone(OperationalDisplayWindow::TIMEZONE)->format('M j, Y g:i A')],
            ['label' => 'Status', 'value' => $issue->statusLabel],
            ['label' => 'Reply to the station', 'value' => (string) $request->public_response],
            ['label' => 'Internal note', 'value' => (string) $request->admin_notes],
        ], static fn (array $fact): bool => trim($fact['value']) !== ''));

        $manage = $user->can('admin.stations.manage');

        return new IssueDetail($issue, (string) $request->request_text, $facts, $timeline, $manage ? $this->actionsFor($request) : [],
            $manage ? null : 'You can see station supply requests but not update them.');
    }

    /** @return list<IssueAction> */
    private function actionsFor(StationSupplyRequest $request): array
    {
        $reply = fn (bool $required, string $label = 'Reply to the station (optional)'): array => ['name' => 'public_response', 'label' => $label, 'type' => 'textarea', 'required' => $required, 'max' => 5000];
        $internal = ['name' => 'admin_notes', 'label' => 'Internal note, staff only (optional)', 'type' => 'textarea', 'required' => false, 'max' => 5000];
        if (in_array($request->status, self::CLOSED, true)) {
            return [
                new IssueAction('reopen', 'Reopen', 'secondary', [$reply(false), $internal]),
                new IssueAction('archive', 'Archive', 'secondary', [], 'Archive this request? It leaves the dashboards; the record stays in Station Supply Requests.'),
            ];
        }

        return array_values(array_filter([
            $request->status === 'open' ? new IssueAction('order', 'Mark ordered', 'primary', [$reply(false), $internal], bulk: IssueAction::BULK_DIRECT) : null,
            new IssueAction('replenish', 'Mark replenished', $request->status === 'ordered' ? 'primary' : 'secondary', [$reply(false), $internal], bulk: IssueAction::BULK_FORM),
            new IssueAction('reply', 'Reply to the station', 'secondary', [$reply(true, 'Reply to the station'), $internal]),
            new IssueAction('deny', 'Deny', 'danger', [$reply(true, 'Reason (shown to the member)'), $internal], 'Deny this request? The member sees your reason.'),
        ]));
    }

    public function perform(User $user, string $id, string $action, array $input, string $expectedVersion): ActionResult
    {
        if (! $this->visibleTo($user, false) || ! $user->can('admin.stations.manage')) {
            return ActionResult::forbidden();
        }
        $target = match ($action) {
            'order' => 'ordered',
            'replenish' => 'replenished',
            'deny' => 'denied',
            'reopen' => 'open',
            'reply', 'archive' => null,
            default => false,
        };
        if ($target === false) {
            return ActionResult::invalid(['action' => 'This action is not available.']);
        }
        $request = $this->find($id);
        if ($request === null) {
            return ActionResult::gone('This request no longer exists or was archived.');
        }
        $reply = self::text($input['public_response'] ?? '') ?: null;
        $notes = self::text($input['admin_notes'] ?? '') ?: null;
        if ($target !== null && $request->status === $target && $reply === null && $notes === null) {
            return ActionResult::done('Already saved.');
        }
        if (self::stamp($request->updated_at) !== $expectedVersion) {
            return ActionResult::conflict();
        }
        if ($action === 'reply' && $reply === null) {
            return ActionResult::invalid(['public_response' => 'Write the reply for the station.']);
        }
        if ($action === 'deny' && mb_strlen((string) $reply) < DecisionReason::MIN_LENGTH) {
            return ActionResult::invalid(['public_response' => 'Say why in at least '.DecisionReason::MIN_LENGTH.' characters. The member reads it.']);
        }

        try {
            DB::transaction(function () use ($request, $user, $action, $target, $reply, $notes): void {
                if ($action === 'archive') {
                    $this->workflow->archive($request, $user);

                    return;
                }
                $this->workflow->update($request, $user, array_filter([
                    'status' => $target ?? $request->status,
                    'public_response' => $reply ?? $request->public_response,
                    'admin_notes' => $notes ?? $request->admin_notes,
                ], static fn ($value): bool => $value !== null));
            });
        } catch (ValidationException $exception) {
            return ActionResult::invalid(self::errorsOf($exception));
        } catch (HttpExceptionInterface $exception) {
            return match ($exception->getStatusCode()) {
                403 => ActionResult::forbidden(),
                409 => ActionResult::conflict($exception->getMessage() ?: 'This request changed. Reload it and try again.'),
                default => throw $exception,
            };
        }

        return ActionResult::done(match ($action) {
            'order' => 'Marked ordered.',
            'replenish' => 'Marked replenished.',
            'deny' => 'Request denied.',
            'reopen' => 'Request reopened.',
            'archive' => 'Archived. The record stays in Station Supply Requests.',
            default => 'Reply sent to the station.',
        });
    }

    private static function stationOf(StationSupplyRequest $request): ?Station
    {
        $station = $request->getRelationValue('station');

        return $station instanceof Station ? $station : null;
    }

    private function find(string $id): ?StationSupplyRequest
    {
        if (preg_match('/^[1-9][0-9]{0,18}$/', $id) !== 1) {
            return null;
        }
        $request = StationSupplyRequest::query()->active()->find((int) $id);

        return $request instanceof StationSupplyRequest ? $request : null;
    }
}
