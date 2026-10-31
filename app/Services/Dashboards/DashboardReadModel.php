<?php

declare(strict_types=1);

namespace App\Services\Dashboards;

use App\Models\User;
use App\Support\Dashboards\DashboardIssue;
use App\Support\Dashboards\DashboardQuery;
use App\Support\Dashboards\DashboardView;
use App\Support\Dashboards\IssueSource;
use App\Support\Dashboards\IssueSourceRegistry;
use App\Support\OperationalDisplayWindow;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository as Cache;
use Throwable;

/**
 * Aggregates the registered IssueSources for one dashboard view and one user.
 *
 * - Only sources whose visibleTo() admits the user are read; each source is asked once for rows
 *   (hard cap per source) and once for counts per load, so cost never scales with the row count.
 * - Rows are merged and ordered by severity (high, medium, low) then most recent first.
 * - Counts are summed by key, both overall and per lane (vehicle / station / messages).
 * - A load is cached per user + view + filter + operational day for a few seconds so a 30 s poll
 *   from several open tabs does not recompute; it is never shared across users. A load where a
 *   source failed is not cached, so the next poll retries.
 * - Nothing here writes: loading never marks anything read.
 */
final class DashboardReadModel
{
    /** Every row a source will return: rows are combined per unit, so a partial list would misstate how many a unit has. */
    public const ROWS_PER_SOURCE = 200;

    public const CACHE_SECONDS = 10;

    /** Source types whose rows are conversations rather than apparatus or station records. */
    private const MESSAGE_TYPES = ['support_message'];

    /** Source types the Overview files under Personnel: a member's own report, tied to no station. */
    private const OVERVIEW_PERSONNEL_TYPES = ['hub_support_ticket'];

    private const SEVERITY_ORDER = ['high' => 0, 'medium' => 1, 'low' => 2];

    public function __construct(
        private readonly IssueSourceRegistry $registry,
        private readonly Cache $cache,
    ) {}

    public function load(User $user, DashboardView $view, string $filter = DashboardFilters::ALL, bool $fresh = false): DashboardSnapshot
    {
        if (! DashboardFilters::isValid($view, $filter)) {
            $filter = DashboardFilters::ALL;
        }

        $window = OperationalDisplayWindow::forNow();
        $key = self::cacheKey($user, $view, $filter, $window);

        if (! $fresh) {
            $cached = $this->cache->get($key);
            if ($cached instanceof DashboardSnapshot) {
                return $cached;
            }
        }

        $snapshot = $this->compute($user, $view, $filter, $window);
        if ($snapshot->failedSources === []) {
            $this->cache->put($key, $snapshot, self::CACHE_SECONDS);
        }

        return $snapshot;
    }

    /** Drop this user's cached loads for every view (after an action changed a record). */
    public function forget(User $user): void
    {
        $window = OperationalDisplayWindow::forNow();
        foreach (DashboardView::cases() as $view) {
            foreach ([DashboardFilters::ALL, ...array_column(DashboardFilters::chips($view), 'key')] as $filter) {
                $this->cache->forget(self::cacheKey($user, $view, $filter, $window));
            }
        }
    }

    /** Lane a source's rows belong to on the overview and in the side panels. */
    public static function laneOf(IssueSource $source, ?DashboardView $on = null): string
    {
        if (in_array($source->type(), self::MESSAGE_TYPES, true)) {
            return DashboardSnapshot::LANE_MESSAGES;
        }
        if ($on === DashboardView::Overview && in_array($source->type(), self::OVERVIEW_PERSONNEL_TYPES, true)) {
            return DashboardSnapshot::LANE_MESSAGES;
        }

        $views = $source->views();
        if (in_array(DashboardView::VehicleCheckout, $views, true)) {
            return DashboardSnapshot::LANE_VEHICLE;
        }
        if (in_array(DashboardView::StationOperations, $views, true)) {
            return DashboardSnapshot::LANE_STATION;
        }

        return DashboardSnapshot::LANE_MESSAGES;
    }

    private function compute(User $user, DashboardView $view, string $filter, OperationalDisplayWindow $window): DashboardSnapshot
    {
        $base = new DashboardQuery($window);
        $filtered = DashboardFilters::query($view, $filter, $window);

        $rows = [];
        $laneOf = [];
        $counts = [];
        $laneCounts = [];
        $lanes = [];
        $failed = [];
        $moreAvailable = false;

        foreach ($this->registry->forView($view) as $source) {
            try {
                if (! $source->visibleTo($user, false)) {
                    continue;
                }

                $lane = self::laneOf($source, $view);
                $sourceCounts = $source->counts($user, $base);
                $sourceRows = array_slice($source->rows($user, $filtered, self::ROWS_PER_SOURCE), 0, self::ROWS_PER_SOURCE);
            } catch (Throwable $exception) {
                report($exception);
                $failed[] = $source->type();

                continue;
            }

            $lanes[$lane] = true;
            $moreAvailable = $moreAvailable || count($sourceRows) >= self::ROWS_PER_SOURCE;
            foreach ($sourceCounts as $countKey => $value) {
                $counts[$countKey] = ($counts[$countKey] ?? 0) + (int) $value;
                $laneCounts[$lane][$countKey] = ($laneCounts[$lane][$countKey] ?? 0) + (int) $value;
            }
            foreach ($sourceRows as $row) {
                if (isset($laneOf[$row->ref()])) {
                    continue;
                }
                $laneOf[$row->ref()] = $lane;
                $rows[] = $row;
            }
        }

        usort($rows, static function (DashboardIssue $a, DashboardIssue $b): int {
            return [self::SEVERITY_ORDER[$a->severity], -$a->occurredAt->getTimestamp(), $a->ref()]
                <=> [self::SEVERITY_ORDER[$b->severity], -$b->occurredAt->getTimestamp(), $b->ref()];
        });

        return new DashboardSnapshot(
            view: $view,
            filter: $filter,
            rows: $rows,
            laneOf: $laneOf,
            counts: $counts,
            laneCounts: $laneCounts,
            lanes: array_keys($lanes),
            failedSources: $failed,
            window: $window,
            moreAvailable: $moreAvailable,
            loadedAt: CarbonImmutable::now(OperationalDisplayWindow::TIMEZONE),
        );
    }

    private static function cacheKey(User $user, DashboardView $view, string $filter, OperationalDisplayWindow $window): string
    {
        // v2: rows carry a group key. A v1 entry cached across a release would combine nothing.
        return 'admin-dashboards:v2:'.$user->getKey().':'.$view->value.':'.$filter.':'.$window->start->getTimestamp();
    }
}
