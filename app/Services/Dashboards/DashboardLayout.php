<?php

declare(strict_types=1);

namespace App\Services\Dashboards;

use App\Support\Dashboards\DashboardIssue;
use App\Support\Dashboards\DashboardView;

/**
 * What each of the three dashboards shows, as plain view data: four stat cards with exact counts
 * from the sources, the main lists and the stage queues. No trend arrows or deltas: there is no
 * grounded comparator for them.
 */
final class DashboardLayout
{
    /** The stage queue shows the first few per stage; the full list is the main card. */
    private const ROWS_PER_STAGE = 2;

    private const STAGES = [
        ['key' => 'awaiting_response', 'label' => 'Needs review', 'icon' => 'heroicon-m-exclamation-circle', 'tone' => 'danger', 'countKey' => 'awaiting_response'],
        ['key' => 'in_progress', 'label' => 'In progress', 'icon' => 'heroicon-m-wrench', 'tone' => 'info', 'countKey' => 'in_progress'],
        ['key' => 'resolved', 'label' => 'Completed today', 'icon' => 'heroicon-m-check-circle', 'tone' => 'success', 'countKey' => 'resolved_today'],
    ];

    /**
     * @param  array{vehicle: ?string, station: ?string, messages: ?string}  $links
     * @return array{stats: list<array<string, mixed>>, main: list<array<string, mixed>>, side: list<array<string, mixed>>, primaryLane: ?string}
     */
    public static function for(DashboardView $view, DashboardSnapshot $snapshot, array $links, ?string $filterLabel = null): array
    {
        return match ($view) {
            DashboardView::Overview => self::overview($snapshot, $links),
            DashboardView::VehicleCheckout => self::vehicle($snapshot, $links, $filterLabel),
            DashboardView::StationOperations => self::station($snapshot, $links, $filterLabel),
        };
    }

    /** @param  array{vehicle: ?string, station: ?string, messages: ?string}  $links */
    private static function overview(DashboardSnapshot $s, array $links): array
    {
        $vehicle = DashboardSnapshot::LANE_VEHICLE;
        $station = DashboardSnapshot::LANE_STATION;
        $messages = DashboardSnapshot::LANE_MESSAGES;

        return [
            'primaryLane' => null,
            'stats' => [
                self::stat('open', 'Open items', $s->count('open'), 'Across all areas', 'heroicon-o-exclamation-circle', 'danger'),
                self::stat('vehicle_open', 'Vehicle issues', $s->count('open', $vehicle), 'Need attention', 'heroicon-o-truck', 'info', $links['vehicle']),
                self::stat('station_open', 'Station requests', $s->count('open', $station), 'Open requests', 'heroicon-o-building-office', 'warning', $links['station']),
                self::stat('messages_awaiting', 'Support messages', $s->count('awaiting_response', $messages), 'Awaiting response', 'heroicon-o-chat-bubble-left-right', 'violet', $links['messages']),
            ],
            'main' => [
                self::card('lane-vehicle', 'Checkout alerts', 'Daily vehicle checkout issues.', 'heroicon-o-truck', 'danger',
                    self::rows($s->rowsIn($vehicle), $s), 'No open vehicle issues for this operational day.', $links['vehicle'], 'vehicle checkout issues'),
                self::card('lane-station', 'Station requests', 'Requests and facility issues.', 'heroicon-o-building-office', 'warning',
                    self::rows($s->rowsIn($station), $s), 'No open station requests for this operational day.', $links['station'], 'station requests'),
                self::card('lane-messages', 'Support messages', 'Conversations with Support Services.', 'heroicon-o-chat-bubble-left-right', 'violet',
                    self::rows($s->rowsIn($messages), $s), 'No open Support Services messages for this operational day.', $links['messages'], 'Support Services messages'),
            ],
            'side' => [],
        ];
    }

    /** @param  array{vehicle: ?string, station: ?string, messages: ?string}  $links */
    private static function vehicle(DashboardSnapshot $s, array $links, ?string $filterLabel): array
    {
        $lane = DashboardSnapshot::LANE_VEHICLE;
        $rows = $s->rowsIn($lane);
        $special = array_values(array_filter($rows, static fn (DashboardIssue $r): bool => array_intersect(['marine', 'special'], $r->tags) !== []));

        return [
            'primaryLane' => $lane,
            'stats' => [
                self::stat('open', 'Open apparatus issues', $s->count('open', $lane), 'Require attention', 'heroicon-o-exclamation-circle', 'danger'),
                self::stat('high', 'High priority', $s->count('high', $lane), 'Highest severity', 'heroicon-o-fire', 'warning'),
                self::stat('awaiting_response', 'Awaiting response', $s->count('awaiting_response', $lane), 'Not yet picked up', 'heroicon-o-clock', 'info'),
                self::stat('resolved_today', 'Completed today', $s->count('resolved_today', $lane), 'This operational day', 'heroicon-o-check-circle', 'success'),
            ],
            'main' => [
                self::card('priority-apparatus', 'Priority apparatus alerts', $filterLabel ? 'Filtered: '.$filterLabel : 'Most severe first, then most recent.', 'heroicon-o-exclamation-circle', 'danger',
                    self::rows($rows, $s), $filterLabel ? 'No apparatus issues match “'.$filterLabel.'” for this operational day.' : 'No open vehicle issues for this operational day.'),
            ],
            'side' => [
                self::queue('apparatus-queue', 'Apparatus workflow queue', $filterLabel ? 'Filtered: '.$filterLabel : null, 'heroicon-o-cog-6-tooth', $s, $lane, $filterLabel === null),
                self::card('marine-special', 'Marine & specialty units', null, 'heroicon-o-lifebuoy', 'info',
                    self::rows($special, $s), 'No open marine or specialty unit issues.', null, null, true),
            ],
        ];
    }

    /** @param  array{vehicle: ?string, station: ?string, messages: ?string}  $links */
    private static function station(DashboardSnapshot $s, array $links, ?string $filterLabel): array
    {
        $lane = DashboardSnapshot::LANE_STATION;
        $side = [self::queue('station-queue', 'Facilities & work orders', $filterLabel ? 'Filtered: '.$filterLabel : 'Station requests by status.', 'heroicon-o-wrench-screwdriver', $s, $lane, $filterLabel === null)];
        if ($s->hasLane(DashboardSnapshot::LANE_MESSAGES)) {
            $side[] = self::card('station-messages', 'Station messages & notes', null, 'heroicon-o-chat-bubble-left-right', 'violet',
                self::rows($s->rowsIn(DashboardSnapshot::LANE_MESSAGES), $s), 'No open station messages.', $links['messages'], 'station messages', true);
        }

        return [
            'primaryLane' => $lane,
            'stats' => [
                self::stat('open', 'Open station issues', $s->count('open', $lane), 'Require attention', 'heroicon-o-exclamation-circle', 'danger'),
                self::stat('facilities', 'Facilities / maintenance', $s->count('facilities', $lane), 'Building and facility issues', 'heroicon-o-wrench-screwdriver', 'info'),
                self::stat('supplies', 'Supply requests', $s->count('supplies', $lane), 'Station supply needs', 'heroicon-o-cube', 'warning'),
                self::stat('access_it', 'Access / IT requests', $s->count('access_it', $lane), 'Keys, access and IT support', 'heroicon-o-key', 'violet'),
            ],
            'main' => [
                self::card('priority-station', 'Priority station alerts', $filterLabel ? 'Filtered: '.$filterLabel : 'Most severe first, then most recent.', 'heroicon-o-bell-alert', 'danger',
                    self::rows($s->rowsIn($lane), $s), $filterLabel ? 'No station items match “'.$filterLabel.'” for this operational day.' : 'No open station requests for this operational day.'),
            ],
            'side' => $side,
        ];
    }

    /** @return array<string, mixed> */
    private static function stat(string $key, string $label, int $value, string $note, string $icon, string $tone, ?string $href = null): array
    {
        return compact('key', 'label', 'value', 'note', 'icon', 'tone', 'href');
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private static function card(string $id, string $title, ?string $note, string $icon, string $tone, array $rows, string $empty, ?string $href = null, ?string $hrefLabel = null, bool $compact = false): array
    {
        return ['type' => 'list'] + compact('id', 'title', 'note', 'icon', 'tone', 'rows', 'empty', 'href', 'hrefLabel', 'compact');
    }

    /** @return array<string, mixed> */
    private static function queue(string $id, string $title, ?string $note, string $icon, DashboardSnapshot $s, string $lane, bool $exactCounts): array
    {
        $groups = [];
        foreach (self::STAGES as $stage) {
            $groups[] = [
                ...$stage,
                'rows' => self::rows(array_slice($s->rowsInStage($stage['key'], $lane), 0, self::ROWS_PER_STAGE), $s),
                'count' => $exactCounts && $s->hasCount($stage['countKey'], $lane) ? $s->count($stage['countKey'], $lane) : null,
            ];
        }

        return ['type' => 'queue'] + compact('id', 'title', 'note', 'icon', 'groups');
    }

    /**
     * @param  list<DashboardIssue>  $issues
     * @return list<array<string, mixed>>
     */
    private static function rows(array $issues, DashboardSnapshot $s): array
    {
        return array_map(static fn (DashboardIssue $issue): array => IssuePresenter::row($issue, $s->laneFor($issue)), $issues);
    }
}
