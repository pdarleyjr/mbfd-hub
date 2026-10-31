<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboards;

use App\Services\Dashboards\DashboardSnapshot;
use App\Services\Dashboards\IssueGroups;
use App\Services\Dashboards\IssuePresenter;
use App\Support\Dashboards\DashboardIssue;
use Carbon\CarbonImmutable;
use Tests\Support\Dashboards\FakeIssueSource;
use Tests\TestCase;

final class IssueGroupsTest extends TestCase
{
    /** @param  array<string, mixed>  $overrides */
    private function issue(string $id, ?string $group, array $overrides = []): DashboardIssue
    {
        $issue = FakeIssueSource::issue('fake', $id, $overrides + ['subject' => 'E 2', 'title' => "Missing: item {$id}"]);

        return $group === null ? $issue : $issue->inGroup($group);
    }

    public function test_one_units_issues_become_one_group_and_ungrouped_rows_stand_alone(): void
    {
        $groups = IssueGroups::of([
            $this->issue('1', 'apparatus:2'),
            $this->issue('2', 'apparatus:3'),
            $this->issue('3', 'apparatus:2'),
            $this->issue('4', null),
        ]);

        $sizes = array_column(array_map(static fn (array $g): array => ['key' => $g['key'], 'n' => count($g['issues'])], $groups), 'n', 'key');
        $this->assertSame(['apparatus:2' => 2, 'apparatus:3' => 1, 'fake:4' => 1], array_intersect_key($sizes, ['apparatus:2' => 1, 'apparatus:3' => 1, 'fake:4' => 1]));
        $this->assertCount(3, $groups);
    }

    public function test_groups_put_something_open_first_then_the_worst_then_the_newest(): void
    {
        $now = CarbonImmutable::parse('2026-10-31 12:00:00', 'UTC');
        $groups = IssueGroups::of([
            $this->issue('1', 'apparatus:1', ['stage' => 'resolved', 'severity' => 'high', 'occurredAt' => $now]),
            $this->issue('2', 'apparatus:2', ['severity' => 'low', 'occurredAt' => $now]),
            $this->issue('3', 'apparatus:3', ['severity' => 'high', 'occurredAt' => $now->subHours(5)]),
            $this->issue('4', 'apparatus:4', ['severity' => 'high', 'occurredAt' => $now->subHour()]),
        ]);

        $this->assertSame(['apparatus:4', 'apparatus:3', 'apparatus:2', 'apparatus:1'], array_column($groups, 'key'));
    }

    public function test_a_closed_issue_never_raises_the_severity_of_a_group_with_open_issues(): void
    {
        $this->assertSame('low', IssueGroups::worstSeverity([
            $this->issue('1', 'apparatus:1', ['stage' => 'resolved', 'severity' => 'high']),
            $this->issue('2', 'apparatus:1', ['severity' => 'low']),
        ]));
    }

    public function test_a_group_row_names_the_unit_counts_open_issues_and_links_to_all_of_them(): void
    {
        $row = IssuePresenter::groupRow(['key' => 'apparatus:2', 'issues' => [
            $this->issue('1', 'apparatus:2', ['severity' => 'high', 'title' => 'Missing: 3 inch Fog Nozzle', 'actorLabel' => 'Driver Example']),
            $this->issue('2', 'apparatus:2', ['severity' => 'medium', 'actorLabel' => 'Driver Example']),
            $this->issue('3', 'apparatus:2', ['severity' => 'medium', 'actorLabel' => 'Driver Example']),
        ]], DashboardSnapshot::LANE_VEHICLE, '/admin/dashboards/item?group=apparatus:2');

        $this->assertSame('E 2', $row['subject']);
        $this->assertSame('3 open issues', $row['title']);
        $this->assertSame('Missing: 3 inch Fog Nozzle + 2 more', $row['summary']);
        $this->assertSame('high', $row['severity']);
        $this->assertSame('Driver Example', $row['actor']);
        $this->assertSame('Review all', $row['cta']);
        $this->assertSame('/admin/dashboards/item?group=apparatus:2', $row['href']);
    }

    public function test_a_single_issue_stays_an_ordinary_row_that_opens_the_panel(): void
    {
        $row = IssuePresenter::groupRow(['key' => 'apparatus:2', 'issues' => [$this->issue('1', 'apparatus:2')]], DashboardSnapshot::LANE_VEHICLE, '/unused');

        $this->assertNull($row['href']);
        $this->assertSame('fake:1', $row['ref']);
        $this->assertSame('Missing: item 1', $row['title']);
    }

    public function test_a_group_of_member_items_is_named_for_the_member(): void
    {
        $row = IssuePresenter::groupRow(['key' => 'member:9', 'issues' => [
            $this->issue('1', 'member:9', ['subject' => null, 'actorLabel' => 'Sam Lee']),
            $this->issue('2', 'member:9', ['subject' => null, 'actorLabel' => 'Sam Lee']),
        ]], DashboardSnapshot::LANE_MESSAGES, '/x');

        $this->assertSame('Sam Lee', $row['subject']);
        $this->assertSame('2 open items', $row['title']);
        $this->assertNull($row['actor']);
    }
}
