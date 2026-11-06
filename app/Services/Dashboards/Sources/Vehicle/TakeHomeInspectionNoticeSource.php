<?php

declare(strict_types=1);

namespace App\Services\Dashboards\Sources\Vehicle;

use App\Models\TakeHomeVehicleInspection;
use App\Models\User;
use App\Services\Dashboards\Support\ApparatusClass;
use App\Support\Dashboards\ActionResult;
use App\Support\Dashboards\DashboardIssue;
use App\Support\Dashboards\DashboardQuery;
use App\Support\Dashboards\DashboardView;
use App\Support\Dashboards\IssueDetail;
use App\Support\Dashboards\IssueSource;
use App\Support\Fleet\TakeHomeInspectionAccess;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Take Home Vehicle Inspections (take_home_vehicle_inspections, PR 456) as NOTICES on the Vehicle Checkout board and
 * the Overview's Apparatus lane. An inspection is approved when it is submitted and is immutable, so there is nothing
 * for staff to act on: each row says that one was submitted (vehicle, member, time, what the member found) and links
 * to the record.
 *
 * - Stage DashboardIssue::STAGE_NOTICE, severity low, no tags: never open, so it is not in "open", "awaiting response",
 *   "high priority" or any chip count, never in a stage queue, and listed after the open items (IssueGroups).
 * - No group key: each notice is its own row, so it never joins a unit's group page and is never offered for a bulk action.
 * - No actions; perform() refuses everything and writes nothing.
 * - Row set: submitted in the last dashboards.take_home_notice_hours (24 by default), so every notice is shown for one
 *   full day whatever time it came in, then drops off by itself. A chip filter (class, priority, stage) hides them.
 * - Take-home inspections never create an apparatus issue (TakeHomeInspectionSubmitter writes only the inspection and
 *   the vehicle roster changes), so a problem the member reports is not listed twice.
 * - Visibility: Fleet viewers, the same rule as the Take Home Inspections list (TakeHomeInspectionAccess::canViewAll).
 *   Never on the wall display (the notice names the member).
 */
final class TakeHomeInspectionNoticeSource implements IssueSource
{
    public const TYPE = 'take_home_inspection';

    public const STATUS = 'submitted';

    public const TITLE = 'Take-home inspection submitted';

    private const ROW_CAP = 200;

    private const COLUMNS = [
        'id', 'reference', 'inspector_name', 'driver_name', 'vehicle_snapshot', 'odometer', 'odometer_exception', 'odometer_flagged',
        'checks', 'has_issues', 'has_not_checked', 'keys_present', 'acceptable_for_use', 'comments', 'submitted_at',
    ];

    public function type(): string
    {
        return self::TYPE;
    }

    public function views(): array
    {
        return [DashboardView::VehicleCheckout, DashboardView::Overview];
    }

    public function visibleTo(User $user, bool $displayMode): bool
    {
        return ! $displayMode && TakeHomeInspectionAccess::canViewAll($user);
    }

    public static function noticeHours(): int
    {
        return max(1, (int) config('dashboards.take_home_notice_hours', 24));
    }

    public function rows(User $user, DashboardQuery $query, int $limit): array
    {
        $limit = max(0, min($limit, self::ROW_CAP));
        if ($limit === 0 || ! $this->visibleTo($user, $query->displayMode) || self::isFiltered($query)) {
            return [];
        }

        return $this->recent($query)
            ->select(self::COLUMNS)
            ->orderByDesc('submitted_at')->orderByDesc('id')
            ->limit($limit)->get()
            ->map(fn (TakeHomeVehicleInspection $inspection): DashboardIssue => $this->toIssue($inspection))
            ->values()->all();
    }

    /** Zero for every stat key (a notice is never open); "notices" says how many are on the board. */
    public function counts(User $user, DashboardQuery $query): array
    {
        $counts = ['open' => 0, 'awaiting_response' => 0, 'in_progress' => 0, 'high' => 0, 'resolved_today' => 0];
        foreach (ApparatusClass::TAGS as $tag) {
            $counts[$tag] = 0;
        }
        $counts['notices'] = $this->visibleTo($user, $query->displayMode) && ! self::isFiltered($query)
            ? $this->recent($query)->count()
            : 0;

        return $counts;
    }

    public function detail(User $user, string $id): ?IssueDetail
    {
        if (! $this->visibleTo($user, false) || ($inspection = $this->find($id)) === null) {
            return null;
        }
        $issues = self::issueLabels($inspection);

        return new IssueDetail(
            issue: $this->toIssue($inspection),
            body: filled($inspection->comments) ? (string) $inspection->comments : null,
            facts: array_values(array_filter([
                ['label' => 'Reference', 'value' => (string) $inspection->reference],
                ['label' => 'Vehicle', 'value' => self::vehicle($inspection)],
                ['label' => 'Submitted by', 'value' => (string) $inspection->inspector_name],
                ['label' => 'Driver', 'value' => $inspection->driverLabel()],
                ['label' => 'Submitted', 'value' => $inspection->submittedAtLocal()],
                ['label' => 'Odometer', 'value' => self::odometer($inspection)],
                ['label' => 'Keys present', 'value' => $inspection->keysLabel()],
                ['label' => 'Acceptable for use', 'value' => $inspection->acceptableLabel()],
                ['label' => 'Issues found', 'value' => $issues === [] ? 'None' : implode(', ', $issues)],
                ['label' => 'Not checked', 'value' => $inspection->uncheckedChecks()->isEmpty() ? '' : (string) $inspection->uncheckedChecks()->count()],
            ], static fn (array $fact): bool => $fact['value'] !== '')),
            timeline: [],
            actions: [],
            readOnlyReason: 'Take-home inspections are approved automatically when they are submitted, so there is nothing to act on here. '
                .'This notice clears itself '.self::noticeHours().' hours after submission. Open the full record for the photos and signature.',
        );
    }

    public function perform(User $user, string $id, string $action, array $input, string $expectedVersion): ActionResult
    {
        if (! $this->visibleTo($user, false)) {
            return ActionResult::forbidden();
        }
        if ($this->find($id) === null) {
            return ActionResult::gone();
        }

        return ActionResult::invalid(['action' => 'Take-home inspections are approved automatically; there is nothing to act on.'],
            'Take-home inspections are approved automatically; there is nothing to act on.');
    }

    /** @return Builder<TakeHomeVehicleInspection> */
    private function recent(DashboardQuery $query): Builder
    {
        $now = $query->window->now->utc();

        return TakeHomeVehicleInspection::query()
            ->where('submitted_at', '>', $now->subHours(self::noticeHours()))
            ->where('submitted_at', '<=', $now);
    }

    /** Class, priority and stage chips are about checkout issues; a notice matches none of them. */
    private static function isFiltered(DashboardQuery $query): bool
    {
        return $query->tags !== [] || $query->stage !== null || $query->severity !== null;
    }

    private function find(string $id): ?TakeHomeVehicleInspection
    {
        if (preg_match('/^[1-9][0-9]{0,18}$/', $id) !== 1) {
            return null;
        }

        return TakeHomeVehicleInspection::query()->select(self::COLUMNS)->find((int) $id);
    }

    private function toIssue(TakeHomeVehicleInspection $inspection): DashboardIssue
    {
        return new DashboardIssue(
            type: self::TYPE,
            id: (string) $inspection->getKey(),
            title: self::TITLE,
            summary: self::summary($inspection),
            severity: 'low',
            status: self::STATUS,
            statusLabel: 'Approved automatically',
            stage: DashboardIssue::STAGE_NOTICE,
            tags: [],
            subject: self::vehicle($inspection),
            imageKey: null,
            actorLabel: filled($inspection->inspector_name) ? (string) $inspection->inspector_name : null,
            categoryLabel: 'Take-home inspection',
            occurredAt: CarbonImmutable::instance($inspection->submitted_at),
            // Inspections are immutable, so the id alone identifies this version.
            version: self::STATUS.':'.$inspection->getKey(),
            sourceUrl: route('take-home-inspections.show', ['inspection' => $inspection->reference], absolute: false),
        );
    }

    private static function vehicle(TakeHomeVehicleInspection $inspection): string
    {
        $label = $inspection->vehicleLabel();

        return $label !== '' ? $label : 'Take-home vehicle';
    }

    /** "Issue found: Tires & wheels · Not acceptable for use", or "All checks good". */
    private static function summary(TakeHomeVehicleInspection $inspection): string
    {
        $issues = self::issueLabels($inspection);
        $unchecked = $inspection->uncheckedChecks()->count();
        $parts = array_values(array_filter([
            $issues === [] ? null : 'Issue found: '.implode(', ', $issues),
            $unchecked > 0 ? $unchecked.' not checked' : null,
            $inspection->acceptable_for_use === false ? 'Not acceptable for use' : null,
            $inspection->odometer_flagged ? 'Odometer flagged' : null,
        ]));

        return Str::limit($parts === [] ? 'All checks good' : implode(' · ', $parts), 200);
    }

    /** @return list<string> labels of the checks the member marked "Issue found" */
    private static function issueLabels(TakeHomeVehicleInspection $inspection): array
    {
        $labels = [];
        // Stored as a list of ['key', 'label', 'status', ...] in TakeHomeVehicleInspection::CHECKS order.
        foreach ((array) ($inspection->checks ?? []) as $check) {
            if (is_array($check) && ($check['status'] ?? null) === TakeHomeVehicleInspection::STATUS_ISSUE) {
                $key = (string) ($check['key'] ?? '');
                $labels[] = TakeHomeVehicleInspection::CHECKS[$key][0] ?? (filled($check['label'] ?? null) ? (string) $check['label'] : Str::headline($key));
            }
        }

        return $labels;
    }

    private static function odometer(TakeHomeVehicleInspection $inspection): string
    {
        if ($inspection->odometer !== null) {
            return number_format((int) $inspection->odometer).($inspection->odometer_flagged ? ' (flagged)' : '');
        }

        return TakeHomeVehicleInspection::ODOMETER_EXCEPTIONS[(string) $inspection->odometer_exception] ?? '';
    }
}
