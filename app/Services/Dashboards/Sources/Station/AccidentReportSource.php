<?php

declare(strict_types=1);

namespace App\Services\Dashboards\Sources\Station;

use App\Filament\Resources\AccidentReportResource;
use App\Models\AccidentReport;
use App\Models\User;
use App\Services\Accidents\AccidentReportService;
use App\Support\Dashboards\ActionResult;
use App\Support\Dashboards\DashboardIssue;
use App\Support\Dashboards\DashboardView;
use App\Support\Dashboards\IssueAction;
use App\Support\Dashboards\IssueDetail;
use App\Support\HubTimezone;
use App\Support\OperationalDisplayWindow;
use App\Support\Personnel\PersonnelAccess;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Accident reports on the Admin Overview's Personnel card: a red "Accident reported. Action required." row from the
 * moment the first part is submitted until the accident is resolved. Acknowledging records who has seen it; it
 * does not remove the row. Every administrator sees the row and opens the full report.
 *
 * Stage: not yet acknowledged → awaiting_response; acknowledged and open → in_progress; resolved inside the
 * operational day → resolved. Every open accident is severity "high".
 *
 * @extends QueryBackedSource<AccidentReport>
 */
final class AccidentReportSource extends QueryBackedSource
{
    public function __construct(private readonly AccidentReportService $service) {}

    public function type(): string
    {
        return 'accident_report';
    }

    public function views(): array
    {
        return [DashboardView::Overview];
    }

    public function visibleTo(User $user, bool $displayMode): bool
    {
        // Never on a wall display: it names members. Every administrator sees the alert and can open the report.
        return ! $displayMode && PersonnelAccess::canViewAccidents($user);
    }

    protected function baseQuery(): Builder
    {
        return AccidentReport::query()->submitted();
    }

    protected function whereOpen(Builder $query): void
    {
        $query->whereNull('resolved_at');
    }

    protected function whereStage(Builder $query, string $stage, OperationalDisplayWindow $window): void
    {
        match ($stage) {
            'awaiting_response' => $query->whereNull('resolved_at')->whereNull('alert_acknowledged_at'),
            'in_progress' => $query->whereNull('resolved_at')->whereNotNull('alert_acknowledged_at'),
            'resolved' => $query->whereNotNull('resolved_at')->where(fn (Builder $q) => self::whereInWindow($q, 'resolved_at', $window)),
            default => self::nothing($query),
        };
    }

    protected function whereSeverity(Builder $query, string $severity): void
    {
        if ($severity !== 'high') {
            self::nothing($query);
        }
    }

    protected function tagConstraints(): array
    {
        return [];
    }

    protected function prepareRows(Builder $query): void
    {
        $query->with(['subject:id,name,employee_id', 'reporter:id,name', 'vehicle:id,veh_number', 'vehicle.apparatus:id,unit_master_vehicle_id,designation'])
            ->orderByRaw('CASE WHEN resolved_at IS NULL AND alert_acknowledged_at IS NULL THEN 0 WHEN resolved_at IS NULL THEN 1 ELSE 2 END')
            ->orderByDesc('submitted_at')->orderByDesc('id');
    }

    /** @param AccidentReport $record */
    protected function toIssue(Model $record, bool $displayMode): DashboardIssue
    {
        $open = $record->resolved_at === null;
        $acknowledged = $record->alert_acknowledged_at !== null;
        $vehicle = $record->vehicle ? trim('Vehicle '.$record->vehicle->veh_number.' '.($record->vehicle->apparatus->designation ?? '')) : null;
        $summary = collect([
            $record->subject?->name ? 'Employee: '.$record->subject->name : null,
            $vehicle,
            $record->station_label,
            $record->occurred_at ? 'Accident '.$record->occurred_at->copy()->setTimezone(HubTimezone::NAME)->format('M j, g:i A') : null,
        ])->filter()->implode(' · ');

        return new DashboardIssue(
            type: $this->type(),
            id: (string) $record->getKey(),
            title: $open && ! $acknowledged ? 'Accident reported. Action required.' : $record->typeLabel().' '.$record->reference,
            summary: $displayMode || $summary === '' ? null : $summary,
            severity: 'high',
            status: $record->status,
            statusLabel: $record->statusLabel(),
            stage: ! $open ? 'resolved' : ($acknowledged ? 'in_progress' : 'awaiting_response'),
            tags: [],
            subject: null,
            imageKey: null,
            actorLabel: $displayMode ? null : $record->reporter?->name,
            categoryLabel: $record->typeLabel(),
            occurredAt: CarbonImmutable::instance($record->submitted_at),
            version: self::versionOf($record),
            sourceUrl: AccidentReportResource::getUrl('view', ['record' => $record], panel: 'admin'),
            groupKey: null,
        );
    }

    public function detail(User $user, string $id): ?IssueDetail
    {
        if (! $this->visibleTo($user, false)) {
            return null;
        }
        $report = AccidentReport::query()->submitted()->with(['subject', 'reporter', 'supervisor', 'vehicle.apparatus', 'acknowledgedBy'])->find((int) $id);
        if (! $report instanceof AccidentReport) {
            return null;
        }
        $at = fn ($moment): ?string => $moment?->copy()->setTimezone(HubTimezone::NAME)->format('M j, Y g:i A');
        $facts = array_values(array_filter([
            ['label' => 'Report', 'value' => $report->typeLabel().' '.$report->reference],
            ['label' => 'Status', 'value' => $report->statusLabel()],
            ['label' => 'Employee involved', 'value' => $report->subject->name ?? 'Not recorded'],
            ['label' => 'Reported by', 'value' => $report->reporter->name ?? 'Not recorded'],
            $report->supervisor ? ['label' => 'Supervisor', 'value' => $report->supervisor->name] : null,
            $report->vehicle ? ['label' => 'Vehicle', 'value' => trim($report->vehicle->veh_number.' '.($report->vehicle->apparatus->designation ?? ''))] : null,
            $report->station_label ? ['label' => 'Station / assignment', 'value' => $report->station_label] : null,
            ['label' => 'Accident', 'value' => $at($report->occurred_at) ?? 'Not recorded'],
            filled($report->driver_data['location'] ?? $report->driver_data['place'] ?? null) ? ['label' => 'Location', 'value' => (string) ($report->driver_data['location'] ?? $report->driver_data['place'])] : null,
            filled($report->driver_data['injuries'] ?? $report->driver_data['type_of_injury'] ?? null) ? ['label' => 'Injuries', 'value' => (string) ($report->driver_data['injuries'] ?? $report->driver_data['type_of_injury'])] : null,
            ['label' => 'Submitted', 'value' => (string) $at($report->submitted_at)],
            ['label' => 'Acknowledged', 'value' => $report->alert_acknowledged_at ? $at($report->alert_acknowledged_at).' by '.($report->acknowledgedBy->name ?? 'administrator') : 'Not yet'],
        ]));
        $actions = [];
        if (PersonnelAccess::canAcknowledgeAccidents($user) && $report->alert_acknowledged_at === null && $report->resolved_at === null) {
            $actions[] = new IssueAction('acknowledge', 'Acknowledge', 'primary', confirm: 'Acknowledging records that you have seen this report. The accident stays open until it is resolved.');
        }
        $reason = $actions === [] ? 'Open the report to review it, record follow-up or resolve it.' : null;

        return new IssueDetail($this->toIssue($report, false), 'Every entry, the official PDF and follow-up actions are on the report page.', $facts, [], $actions, $reason);
    }

    public function perform(User $user, string $id, string $action, array $input, string $expectedVersion): ActionResult
    {
        if (! $this->visibleTo($user, false) || ! PersonnelAccess::canAcknowledgeAccidents($user)) {
            return ActionResult::forbidden();
        }
        if ($action !== 'acknowledge') {
            return ActionResult::invalid(['action' => 'This action is not available.']);
        }

        return DB::transaction(function () use ($user, $id, $expectedVersion): ActionResult {
            $report = AccidentReport::query()->submitted()->lockForUpdate()->find((int) $id);
            if (! $report instanceof AccidentReport) {
                return ActionResult::gone('This report is no longer available.');
            }
            if ($report->alert_acknowledged_at !== null) {
                return ActionResult::done('Already acknowledged.');
            }
            if (self::versionOf($report) !== $expectedVersion) {
                return ActionResult::conflict('This report changed. It has been reloaded; review it and try again.');
            }
            $this->service->acknowledge($user, $report);

            return ActionResult::done('Acknowledged. The accident stays open until it is resolved.');
        });
    }

    private static function versionOf(AccidentReport $report): string
    {
        return 'r'.$report->revision.'-a'.($report->alert_acknowledged_at ? 1 : 0).'-s'.($report->resolved_at ? 1 : 0);
    }
}
