<?php

declare(strict_types=1);

namespace App\Services\Dashboards;

use App\Filament\Pages\IssueGroupPage;
use App\Support\Dashboards\DashboardIssue;
use App\Support\Dashboards\DashboardView;

/**
 * What each of the four dashboards shows, as plain view data: exact counts from the sources (stat
 * cards, or the Overview's filter chips), the main lists combined per unit, and the stage queues. No
 * trend arrows or deltas: there is no grounded comparator for them.
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
     * Stage queue of the Member Requests board. The sub-stages are tags the request sources set from each record's real
     * status (Sources\Requests\RequestTags); "Member replied" only appears while something is in it.
     */
    private const REQUEST_STAGES = [
        ['key' => 'awaiting_response', 'label' => 'Needs review', 'icon' => 'heroicon-m-exclamation-circle', 'tone' => 'danger', 'countKey' => 'awaiting_response', 'stage' => 'awaiting_response'],
        ['key' => 'member_replied', 'label' => 'Member replied', 'icon' => 'heroicon-m-chat-bubble-left-ellipsis', 'tone' => 'violet', 'countKey' => 'member_replied', 'tag' => 'member_replied', 'hideEmpty' => true],
        ['key' => 'waiting_member', 'label' => 'Waiting on the member', 'icon' => 'heroicon-m-user', 'tone' => 'warning', 'countKey' => 'waiting_member', 'tag' => 'waiting_member'],
        ['key' => 'awaiting_pickup', 'label' => 'Ordered or awaiting pickup', 'icon' => 'heroicon-m-truck', 'tone' => 'info', 'countKey' => 'awaiting_pickup', 'tag' => 'awaiting_pickup'],
        ['key' => 'resolved', 'label' => 'Completed today', 'icon' => 'heroicon-m-check-circle', 'tone' => 'success', 'countKey' => 'resolved_today', 'stage' => 'resolved'],
    ];

    /**
     * @param  array{vehicle: ?string, station: ?string, messages: ?string, requests?: ?string}  $links
     * @return array{stats: list<array<string, mixed>>, main: list<array<string, mixed>>, side: list<array<string, mixed>>, primaryLane: ?string}
     */
    public static function for(DashboardView $view, DashboardSnapshot $snapshot, array $links, ?string $filterLabel = null): array
    {
        return match ($view) {
            DashboardView::Overview => self::overview($snapshot, $links, DashboardFilters::laneOf($view, $snapshot->filter)),
            DashboardView::VehicleCheckout => self::vehicle($snapshot, $links, $filterLabel),
            DashboardView::StationOperations => self::station($snapshot, $links, $filterLabel),
            DashboardView::MemberRequests => self::memberRequests($snapshot, $filterLabel),
        };
    }

    /** @return array<string, mixed> */
    private static function memberRequests(DashboardSnapshot $s, ?string $filterLabel): array
    {
        $lane = DashboardSnapshot::LANE_REQUESTS;
        $rows = $s->rowsIn($lane);
        $exact = $filterLabel === null;
        $groups = [];
        foreach (self::REQUEST_STAGES as $stage) {
            $inStage = array_values(array_filter($rows, static fn (DashboardIssue $row): bool => isset($stage['tag'])
                ? $row->isOpen() && in_array($stage['tag'], $row->tags, true)
                : $row->stage === $stage['stage']));
            $count = $exact && $s->hasCount($stage['countKey'], $lane) ? $s->count($stage['countKey'], $lane) : null;
            if (($stage['hideEmpty'] ?? false) && $inStage === [] && ! $count) {
                continue;
            }
            $groups[] = [
                'key' => $stage['key'], 'label' => $stage['label'], 'icon' => $stage['icon'], 'tone' => $stage['tone'],
                'rows' => self::rows(array_slice($inStage, 0, self::ROWS_PER_STAGE), $s),
                'count' => $count,
            ];
        }

        return [
            'primaryLane' => $lane,
            'stats' => [
                self::stat('open', 'Open requests', $s->count('open', $lane), 'Not completed, denied or cancelled', 'heroicon-o-inbox-stack', 'danger'),
                self::stat('awaiting_response', 'Needs review', $s->count('awaiting_response', $lane), 'No staff action yet', 'heroicon-o-clock', 'warning'),
                self::stat('awaiting_pickup', 'Ordered or awaiting pickup', $s->count('awaiting_pickup', $lane), 'Ordered, arrived or ready', 'heroicon-o-truck', 'info'),
                self::stat('resolved_today', 'Completed today', $s->count('resolved_today', $lane), 'This operational day', 'heroicon-o-check-circle', 'success'),
            ],
            'main' => [
                self::card('member-requests', 'Requests by member', $filterLabel ? 'Filtered: '.$filterLabel : 'One row per member, open requests first.', 'heroicon-o-clipboard-document-list', 'info',
                    self::groups($rows, $s), $filterLabel ? 'No member requests match “'.$filterLabel.'”.' : 'No open member requests.'),
            ],
            'side' => [
                ['type' => 'queue', 'id' => 'request-stages', 'title' => 'Where requests stand', 'note' => $filterLabel ? 'Filtered: '.$filterLabel : null,
                    'icon' => 'heroicon-o-queue-list', 'groups' => $groups],
            ],
        ];
    }

    /**
     * The filter chips carry the counts here, so the Overview has no separate stat cards.
     *
     * @param  array{vehicle: ?string, station: ?string, messages: ?string, requests?: ?string}  $links
     */
    private static function overview(DashboardSnapshot $s, array $links, ?string $onlyLane): array
    {
        $vehicle = DashboardSnapshot::LANE_VEHICLE;
        $station = DashboardSnapshot::LANE_STATION;
        $messages = DashboardSnapshot::LANE_MESSAGES;
        $cards = [
            $vehicle => self::card('lane-vehicle', 'Apparatus', 'Open checkout issues, one row per unit.', 'heroicon-o-truck', 'danger',
                self::groups($s->rowsIn($vehicle), $s), 'No open vehicle issues for this operational day.', $links['vehicle'], 'vehicle checkout issues', filterKey: 'apparatus'),
            $station => self::card('lane-station', 'Stations', 'Open requests, one row per station.', 'heroicon-o-building-office', 'warning',
                self::groups($s->rowsIn($station), $s), 'No open station requests for this operational day.', $links['station'], 'station requests', filterKey: 'stations'),
            $messages => self::card('lane-messages', 'Personnel', 'Messages and reports, one row per member.', 'heroicon-o-users', 'violet',
                self::groups($s->rowsIn($messages), $s), 'No open personnel items for this operational day.', $links['messages'], 'Support Services messages', filterKey: 'personnel'),
        ];
        // Its own lane, not inside Personnel: a request waits on staff to order, issue or decide, while a Personnel row
        // is a conversation. Only admins who can see a request source get the lane at all.
        $requests = DashboardSnapshot::LANE_REQUESTS;
        if ($s->hasLane($requests)) {
            $cards[$requests] = self::card('lane-requests', 'Requests', 'Uniforms, PPE, medical supplies and narcotics checks, one row per member.', 'heroicon-o-clipboard-document-list', 'info',
                self::groups($s->rowsIn($requests), $s), 'No open member requests.', $links['requests'] ?? null, 'member requests', filterKey: 'requests');
        }
        if ($onlyLane !== null && ! isset($cards[$onlyLane])) {
            $onlyLane = null;
        }

        return [
            'primaryLane' => $onlyLane,
            'stats' => [],
            'main' => $onlyLane === null ? array_values($cards) : [$cards[$onlyLane]],
            'side' => [],
        ];
    }

    /** @param  array{vehicle: ?string, station: ?string, messages: ?string, requests?: ?string}  $links */
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
                self::card('priority-apparatus', 'Priority apparatus alerts', $filterLabel ? 'Filtered: '.$filterLabel : 'One row per unit, most severe first.', 'heroicon-o-exclamation-circle', 'danger',
                    self::groups($rows, $s), $filterLabel ? 'No apparatus issues match “'.$filterLabel.'” for this operational day.' : 'No open vehicle issues for this operational day.'),
            ],
            'side' => [
                self::queue('apparatus-queue', 'Apparatus workflow queue', $filterLabel ? 'Filtered: '.$filterLabel : null, 'heroicon-o-cog-6-tooth', $s, $lane, $filterLabel === null),
                self::card('marine-special', 'Marine & specialty units', null, 'heroicon-o-lifebuoy', 'info',
                    self::groups($special, $s), 'No open marine or specialty unit issues.', null, null, true),
            ],
        ];
    }

    /** @param  array{vehicle: ?string, station: ?string, messages: ?string, requests?: ?string}  $links */
    private static function station(DashboardSnapshot $s, array $links, ?string $filterLabel): array
    {
        $lane = DashboardSnapshot::LANE_STATION;
        $side = [self::queue('station-queue', 'Facilities & work orders', $filterLabel ? 'Filtered: '.$filterLabel : 'Station requests by status.', 'heroicon-o-wrench-screwdriver', $s, $lane, $filterLabel === null)];
        if ($s->hasLane(DashboardSnapshot::LANE_MESSAGES)) {
            $side[] = self::card('station-messages', 'Station messages & notes', null, 'heroicon-o-chat-bubble-left-right', 'violet',
                self::groups($s->rowsIn(DashboardSnapshot::LANE_MESSAGES), $s), 'No open station messages.', $links['messages'], 'station messages', true);
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
                self::card('priority-station', 'Priority station alerts', $filterLabel ? 'Filtered: '.$filterLabel : 'One row per station, most severe first.', 'heroicon-o-bell-alert', 'danger',
                    self::groups($s->rowsIn($lane), $s), $filterLabel ? 'No station items match “'.$filterLabel.'” for this operational day.' : 'No open station requests for this operational day.'),
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
    private static function card(string $id, string $title, ?string $note, string $icon, string $tone, array $rows, string $empty, ?string $href = null, ?string $hrefLabel = null, bool $compact = false, ?string $filterKey = null): array
    {
        return ['type' => 'list'] + compact('id', 'title', 'note', 'icon', 'tone', 'rows', 'empty', 'href', 'hrefLabel', 'compact', 'filterKey');
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

    /**
     * One row per apparatus, station or member.
     *
     * @param  list<DashboardIssue>  $issues
     * @return list<array<string, mixed>>
     */
    private static function groups(array $issues, DashboardSnapshot $s): array
    {
        return array_map(
            static fn (array $group): array => IssuePresenter::groupRow($group, $s->laneFor($group['issues'][0]), IssueGroupPage::getUrl(['group' => $group['key']], panel: 'admin')),
            IssueGroups::of($issues),
        );
    }
}
