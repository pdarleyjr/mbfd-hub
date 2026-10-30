<?php

declare(strict_types=1);

namespace App\Services\Dashboards;

use App\Support\Dashboards\DashboardIssue;
use App\Support\Dashboards\DashboardView;
use App\Support\OperationalDisplayWindow;
use Carbon\CarbonImmutable;

/**
 * One load of a dashboard for one user: rows merged across the visible sources (most severe, then
 * newest first), exact counts summed per key, and the lane each row belongs to. Read-only.
 */
final readonly class DashboardSnapshot
{
    public const LANE_VEHICLE = 'vehicle';

    public const LANE_STATION = 'station';

    public const LANE_MESSAGES = 'messages';

    /**
     * @param  list<DashboardIssue>  $rows  rows matching the selected filter
     * @param  array<string, string>  $laneOf  ref => lane
     * @param  array<string, int>  $counts  unfiltered counts summed over every visible source
     * @param  array<string, array<string, int>>  $laneCounts  lane => summed counts
     * @param  list<string>  $lanes  lanes that have at least one visible source
     * @param  list<string>  $failedSources  source types that could not be read this time
     */
    public function __construct(
        public DashboardView $view,
        public string $filter,
        public array $rows,
        public array $laneOf,
        public array $counts,
        public array $laneCounts,
        public array $lanes,
        public array $failedSources,
        public OperationalDisplayWindow $window,
        public CarbonImmutable $loadedAt,
    ) {}

    public function count(string $key, ?string $lane = null): int
    {
        $counts = $lane === null ? $this->counts : ($this->laneCounts[$lane] ?? []);

        return (int) ($counts[$key] ?? 0);
    }

    public function hasCount(string $key, ?string $lane = null): bool
    {
        $counts = $lane === null ? $this->counts : ($this->laneCounts[$lane] ?? []);

        return array_key_exists($key, $counts);
    }

    public function hasLane(string $lane): bool
    {
        return in_array($lane, $this->lanes, true);
    }

    /** @return list<DashboardIssue> */
    public function rowsIn(string $lane): array
    {
        return array_values(array_filter($this->rows, fn (DashboardIssue $row): bool => ($this->laneOf[$row->ref()] ?? null) === $lane));
    }

    /**
     * @param  list<string>  $tags
     * @return list<DashboardIssue>
     */
    public function rowsTagged(array $tags): array
    {
        return array_values(array_filter($this->rows, static fn (DashboardIssue $row): bool => array_intersect($tags, $row->tags) !== []));
    }

    /** @return list<DashboardIssue> */
    public function rowsInStage(string $stage, ?string $lane = null): array
    {
        return array_values(array_filter(
            $lane === null ? $this->rows : $this->rowsIn($lane),
            static fn (DashboardIssue $row): bool => $row->stage === $stage,
        ));
    }

    public function laneFor(DashboardIssue $row): string
    {
        return $this->laneOf[$row->ref()] ?? self::LANE_STATION;
    }
}
