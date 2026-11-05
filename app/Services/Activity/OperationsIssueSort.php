<?php

declare(strict_types=1);

namespace App\Services\Activity;

use App\Services\Dashboards\DashboardSnapshot;
use App\Services\Dashboards\IssueGroups;
use App\Support\Dashboards\DashboardIssue;

/**
 * The order of the Admin Overview lanes, chosen per person (user_dashboard_preferences.issue_sort).
 *
 * The read model's rows are grouped and ranked afterwards by IssueGroups::of(), so the order is applied to the
 * finished lane cards here, using the snapshot's exact rows for each group's newest, oldest and worst values.
 * The default (priority first, then newest) is left exactly as the board builds it. Reordering never adds, drops
 * or edits a row.
 */
final class OperationsIssueSort
{
    public const DEFAULT = 'newest_reported';

    /** @var array<string, string> */
    public const OPTIONS = [
        self::DEFAULT => 'Priority, then newest',
        'latest_update' => 'Latest update',
        'oldest_unresolved' => 'Oldest unresolved',
        'highest_priority' => 'Highest priority, longest waiting',
        'location' => 'Location (unit or station)',
    ];

    private const SEVERITY = ['high' => 0, 'medium' => 1, 'low' => 2];

    public static function normalize(?string $sort): string
    {
        return $sort !== null && array_key_exists($sort, self::OPTIONS) ? $sort : self::DEFAULT;
    }

    /**
     * @param  array<string, mixed>  $layout  DashboardLayout::for() result
     * @return array<string, mixed>
     */
    public static function apply(array $layout, DashboardSnapshot $snapshot, string $sort): array
    {
        $sort = self::normalize($sort);
        if ($sort === self::DEFAULT) {
            return $layout;
        }
        $byRef = [];
        $byGroup = [];
        foreach ($snapshot->rows as $issue) {
            $byRef[$issue->ref()] = $issue;
            if ($issue->groupKey !== null) {
                $byGroup[$issue->groupKey][] = $issue;
            }
        }
        foreach (['main', 'side'] as $zone) {
            foreach ((array) ($layout[$zone] ?? []) as $index => $card) {
                if (($card['type'] ?? null) !== 'list' || ! isset($card['rows'])) {
                    continue;
                }
                $layout[$zone][$index]['rows'] = self::order((array) $card['rows'], $byRef, $byGroup, $sort);
            }
        }

        return $layout;
    }

    /**
     * @param  list<array<string, mixed>>  $rows  presenter rows (ref = group key or issue ref)
     * @param  array<string, DashboardIssue>  $byRef
     * @param  array<string, list<DashboardIssue>>  $byGroup
     * @return list<array<string, mixed>>
     */
    private static function order(array $rows, array $byRef, array $byGroup, string $sort): array
    {
        $keyed = [];
        foreach ($rows as $position => $row) {
            $ref = (string) ($row['ref'] ?? '');
            $members = $byGroup[$ref] ?? (isset($byRef[$ref]) ? [$byRef[$ref]] : []);
            $keyed[] = ['row' => $row, 'position' => $position, 'facts' => self::facts($members)];
        }
        usort($keyed, static fn (array $a, array $b): int => self::compare($a, $b, $sort));

        return array_map(static fn (array $item): array => $item['row'], $keyed);
    }

    /**
     * @param  list<DashboardIssue>  $members
     * @return array{open: bool, severity: int, newest: int, oldest: int, place: string}
     */
    private static function facts(array $members): array
    {
        if ($members === []) {
            return ['open' => false, 'severity' => 3, 'newest' => 0, 'oldest' => 0, 'place' => ''];
        }
        $relevant = IssueGroups::openFirst($members);

        return [
            'open' => $relevant[0]->isOpen(),
            'severity' => self::SEVERITY[IssueGroups::worstSeverity($members)],
            'newest' => max(array_map(static fn (DashboardIssue $issue): int => $issue->occurredAt->getTimestamp(), $members)),
            'oldest' => min(array_map(static fn (DashboardIssue $issue): int => $issue->occurredAt->getTimestamp(), $relevant)),
            'place' => trim((string) ($members[0]->subject ?? $members[0]->actorLabel ?? '')),
        ];
    }

    /**
     * @param  array{position: int, facts: array{open: bool, severity: int, newest: int, oldest: int, place: string}}  $a
     * @param  array{position: int, facts: array{open: bool, severity: int, newest: int, oldest: int, place: string}}  $b
     */
    private static function compare(array $a, array $b, string $sort): int
    {
        $result = $sort === 'location'
            ? (($a['facts']['place'] === '') <=> ($b['facts']['place'] === '') ?: strnatcasecmp($a['facts']['place'], $b['facts']['place']))
            : self::rank($a['facts'], $sort) <=> self::rank($b['facts'], $sort);

        return $result !== 0 ? $result : $a['position'] <=> $b['position'];
    }

    /**
     * Smaller sorts first. Closed groups always follow open ones, except in "Latest update", which is purely by time.
     *
     * @param  array{open: bool, severity: int, newest: int, oldest: int, place: string}  $facts
     * @return list<int>
     */
    private static function rank(array $facts, string $sort): array
    {
        $closed = $facts['open'] ? 0 : 1;

        return match ($sort) {
            'latest_update' => [-$facts['newest']],
            'oldest_unresolved' => [$closed, $facts['open'] ? $facts['oldest'] : -$facts['newest']],
            'highest_priority' => [$closed, $facts['severity'], $facts['oldest']],
            default => [0],
        };
    }
}
