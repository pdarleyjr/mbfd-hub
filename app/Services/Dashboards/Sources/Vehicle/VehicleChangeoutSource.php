<?php

declare(strict_types=1);

namespace App\Services\Dashboards\Sources\Vehicle;

use App\Filament\Resources\ApparatusChangeoutResource;
use App\Models\ApparatusChangeout;
use App\Models\ApparatusFleetEvent;
use App\Models\User;
use App\Services\Dashboards\Support\ApparatusClass;
use App\Services\Dashboards\Support\StaleVersion;
use App\Services\Dashboards\Support\VehicleIssueSource;
use App\Services\Fleet\ApparatusChangeoutService;
use App\Support\Dashboards\ActionResult;
use App\Support\Dashboards\DashboardIssue;
use App\Support\Dashboards\IssueAction;
use App\Support\Dashboards\IssueDetail;
use App\Support\Fleet\ChangeoutAlertText;
use App\Support\OperationalDisplayWindow;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * A crew changed trucks. It already happened and nobody approves it; Fleet acknowledges having seen it, through
 * ApparatusChangeoutService::acknowledge.
 *
 * Visibility: the capability gate on ApparatusChangeout (admin.fleet.view), the rule the resource uses. Acknowledging is
 * for Fleet managers, and the service checks that again.
 *
 * Severity: always medium. Stage: awaiting_response until acknowledged, then resolved.
 * Row set: unacknowledged changes, plus changes acknowledged in the operational day.
 * Group: the truck the unit moved onto, with the rest of that unit's issues.
 *
 * Version: "open", or "acknowledged:{time}". A second acknowledgement is a no-op, never a conflict.
 *
 * @extends VehicleIssueSource<ApparatusChangeout>
 */
final class VehicleChangeoutSource extends VehicleIssueSource
{
    private const SEVERITY = "'medium'";

    private const STAGE = "CASE WHEN apparatus_changeouts.acknowledged_at IS NULL THEN 'awaiting_response' ELSE 'resolved' END";

    private const OCCURRED = 'COALESCE(apparatus_changeouts.acknowledged_at, apparatus_changeouts.effective_at, apparatus_changeouts.created_at)';

    private const STATUS_OPEN = 'unacknowledged';

    private const STATUS_DONE = 'acknowledged';

    private const VERSION_OPEN = 'open';

    public function type(): string
    {
        return 'vehicle_changeout';
    }

    public function visibleTo(User $user, bool $displayMode): bool
    {
        return Gate::forUser($user)->allows('viewAny', ApparatusChangeout::class);
    }

    protected function newQuery(): Builder
    {
        return ApparatusChangeout::query();
    }

    protected function scopeToBoard(Builder $query, OperationalDisplayWindow $window): void
    {
        [$start, $end] = [$window->databaseStart(), $window->databaseEnd()];
        $query->where('apparatus_changeouts.status', ApparatusChangeout::STATUS_COMPLETED)->where(fn (Builder $board) => $board
            ->whereNull('apparatus_changeouts.acknowledged_at')
            ->orWhere(fn (Builder $done) => $done->where('apparatus_changeouts.acknowledged_at', '>=', $start)->where('apparatus_changeouts.acknowledged_at', '<', $end)));
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

    protected function apparatusColumn(): string
    {
        return 'to_apparatus_id';
    }

    protected function eagerLoads(): array
    {
        return ['apparatus:'.implode(',', ApparatusClass::COLUMNS), 'leftBehindItems'];
    }

    protected function toIssue(Model $record, bool $displayMode, array $projection): DashboardIssue
    {
        return new DashboardIssue(
            type: $this->type(),
            id: (string) $record->getKey(),
            title: $record->headline(),
            summary: $displayMode ? null : Str::limit(ChangeoutAlertText::body($record), 280),
            severity: $projection['severity'],
            status: $record->isAcknowledged() ? self::STATUS_DONE : self::STATUS_OPEN,
            statusLabel: $record->isAcknowledged() ? 'Acknowledged' : 'Not yet acknowledged',
            stage: $projection['stage'],
            tags: $projection['tags'],
            subject: $projection['subject'] ?? $record->unit_designation,
            imageKey: $projection['imageKey'],
            actorLabel: $displayMode ? null : $record->submitted_by_name,
            categoryLabel: 'Vehicle change',
            occurredAt: $projection['occurredAt'],
            version: self::versionOf($record),
            sourceUrl: ApparatusChangeoutResource::getUrl('view', ['record' => $record->getKey()], panel: 'admin'),
        );
    }

    public function detail(User $user, string $id): ?IssueDetail
    {
        if (! $this->visibleTo($user, false) || ($fresh = $this->freshIssue($id)) === null) {
            return null;
        }
        $changeout = $fresh['record'];
        assert($changeout instanceof ApparatusChangeout);
        $changeout->load(['acknowledgedBy:id,name,display_name', 'events']);
        $canAcknowledge = ! $changeout->isAcknowledged() && $user->canManageFleetSwaps();

        return new IssueDetail(
            issue: $fresh['issue'],
            body: ChangeoutAlertText::body($changeout),
            facts: array_values(array_filter([
                ['label' => 'Unit', 'value' => $changeout->unit_designation],
                ['label' => 'From truck', 'value' => (string) $changeout->from_vehicle_number],
                ['label' => 'To truck', 'value' => $changeout->to_vehicle_number],
                ['label' => 'By', 'value' => $changeout->submitted_by_name],
                ['label' => 'When', 'value' => (string) self::local($changeout->effective_at)],
                ['label' => 'Acknowledged by', 'value' => (string) self::personName($changeout->acknowledgedBy)],
                ['label' => 'Acknowledged', 'value' => (string) self::local($changeout->acknowledged_at)],
            ], static fn (array $fact): bool => $fact['value'] !== '')),
            timeline: $changeout->events->map(static fn (ApparatusFleetEvent $event): array => [
                'at' => CarbonImmutable::parse($event->created_at),
                'actor' => $event->actor_name,
                'text' => str($event->event)->replace('_', ' ')->ucfirst()->toString(),
            ])->values()->all(),
            actions: $canAcknowledge ? [new IssueAction('acknowledge', 'Acknowledge', 'primary', bulk: IssueAction::BULK_DIRECT)] : [],
            readOnlyReason: match (true) {
                $changeout->isAcknowledged() => 'This change has been acknowledged.',
                ! $canAcknowledge => 'Only Fleet managers can acknowledge a vehicle change.',
                default => null,
            },
        );
    }

    public function perform(User $user, string $id, string $action, array $input, string $expectedVersion): ActionResult
    {
        return $this->attempt(function () use ($user, $id, $action, $expectedVersion): ActionResult {
            $changeoutId = self::intId($id);
            $changeout = $changeoutId === null ? null : ApparatusChangeout::query()->find($changeoutId);
            if (! $changeout instanceof ApparatusChangeout) {
                return ActionResult::gone();
            }
            if (! $user->canManageFleetSwaps()) {
                return ActionResult::forbidden();
            }
            if ($action !== 'acknowledge') {
                return ActionResult::invalid(['action' => 'That action is not available here.'], 'That action is not available here.');
            }
            if ($changeout->isAcknowledged()) {
                return ActionResult::done('Already acknowledged.');
            }
            if (! hash_equals(self::versionOf($changeout), $expectedVersion)) {
                throw new StaleVersion;
            }
            app(ApparatusChangeoutService::class)->acknowledge($changeoutId, $user);

            return ActionResult::done('Vehicle change acknowledged.');
        });
    }

    private static function versionOf(ApparatusChangeout $changeout): string
    {
        return $changeout->isAcknowledged() ? self::STATUS_DONE.':'.$changeout->acknowledged_at?->getTimestamp() : self::VERSION_OPEN;
    }
}
