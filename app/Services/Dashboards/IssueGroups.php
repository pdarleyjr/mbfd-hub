<?php

declare(strict_types=1);

namespace App\Services\Dashboards;

use App\Support\Dashboards\DashboardIssue;

/**
 * Combines a dashboard's rows into one entry per apparatus, station or member, so a unit with a
 * dozen missing items is one line that opens all of them. Rows with no group key stay on their own.
 */
final class IssueGroups
{
    private const SEVERITY_ORDER = ['high' => 0, 'medium' => 1, 'low' => 2];

    /**
     * Groups with something still open come first, then the worst severity, then the newest.
     *
     * @param  list<DashboardIssue>  $issues
     * @return list<array{key: string, issues: list<DashboardIssue>}>
     */
    public static function of(array $issues): array
    {
        $groups = [];
        foreach ($issues as $issue) {
            $key = $issue->groupKey ?? $issue->ref();
            $groups[$key] ??= ['key' => $key, 'issues' => []];
            $groups[$key]['issues'][] = $issue;
        }

        $groups = array_values($groups);
        usort($groups, static fn (array $a, array $b): int => self::rank($a['issues']) <=> self::rank($b['issues']));

        return $groups;
    }

    /** @param  list<DashboardIssue>  $issues */
    public static function worstSeverity(array $issues): string
    {
        $worst = 'low';
        foreach (self::openFirst($issues) as $issue) {
            if (self::SEVERITY_ORDER[$issue->severity] < self::SEVERITY_ORDER[$worst]) {
                $worst = $issue->severity;
            }
        }

        return $worst;
    }

    /**
     * Only the open issues when any are open, so a closed one never raises a group's severity.
     *
     * @param  list<DashboardIssue>  $issues
     * @return list<DashboardIssue>
     */
    public static function openFirst(array $issues): array
    {
        $open = array_values(array_filter($issues, static fn (DashboardIssue $issue): bool => $issue->isOpen()));

        return $open === [] ? $issues : $open;
    }

    /**
     * @param  list<DashboardIssue>  $issues
     * @return array{0: int, 1: int, 2: int, 3: string}
     */
    private static function rank(array $issues): array
    {
        $newest = max(array_map(static fn (DashboardIssue $issue): int => $issue->occurredAt->getTimestamp(), $issues));
        $hasOpen = array_filter($issues, static fn (DashboardIssue $issue): bool => $issue->isOpen()) !== [];

        return [$hasOpen ? 0 : 1, self::SEVERITY_ORDER[self::worstSeverity($issues)], -$newest, $issues[0]->ref()];
    }
}
