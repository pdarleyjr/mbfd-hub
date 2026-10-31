<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboards;

use App\Filament\Resources\DefectResource\Pages\ListDefects;
use App\Models\Apparatus;
use App\Models\ApparatusDefect;
use App\Models\ApparatusDefectEvent;
use App\Models\ApparatusInspection;
use App\Models\User;
use App\Services\Dashboards\Sources\Vehicle\ApparatusDefectSource;
use App\Support\Dashboards\DashboardView;
use App\Support\Dashboards\IssueSourceRegistry;
use App\Support\Fleet\DefectActionStatus;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Feature\Defects\Concerns\BuildsContinuityFixtures;
use Tests\TestCase;

final class VehicleApparatusDefectSourceTest extends TestCase
{
    use BuildsContinuityFixtures;
    use RefreshDatabase;
    use VehicleDashboardFixtures;

    private ApparatusDefectSource $source;

    private Apparatus $rig;

    private User $reporter;

    private ApparatusInspection $inspection;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->setUpVehicleDashboard();
        $this->source = app(IssueSourceRegistry::class)->get('apparatus_defect');
        $this->rig = $this->rig('Rescue 2', 'R2');
        $this->rig->update(['type' => 'Rescue', 'daily_checkout_template' => 'rescue']);
        $this->reporter = $this->member('Riley Reporter');
        $this->inspection = $this->inspectionBy($this->rig, $this->reporter, '2026-10-30 12:00:00');
    }

    private function defect(string $item = 'Portable radio battery', array $attributes = []): ApparatusDefect
    {
        $defect = $this->openDefect($this->rig, $this->inspection, $this->reporter, $item);
        if ($attributes !== []) {
            $defect->forceFill($attributes)->save();
        }

        return $defect->refresh();
    }

    private function versionOf(ApparatusDefect $defect): string
    {
        return $this->source->detail($this->personas()['super admin'], (string) $defect->id)->issue->version;
    }

    public function test_it_is_registered_for_the_overview_and_vehicle_checkout_dashboards(): void
    {
        $registry = app(IssueSourceRegistry::class);
        $this->assertContains($this->source, $registry->forView(DashboardView::Overview));
        $this->assertContains($this->source, $registry->forView(DashboardView::VehicleCheckout));
        $this->assertNotContains($this->source, $registry->forView(DashboardView::StationOperations));
    }

    public function test_every_admin_persona_sees_the_issue_and_an_ordinary_member_sees_nothing(): void
    {
        $defect = $this->defect();

        foreach ($this->personas() as $persona => $user) {
            $rows = $this->source->rows($user, $this->boardQuery(), 10);
            $this->assertCount(1, $rows, $persona);
            $this->assertSame('apparatus_defect:'.$defect->id, $rows[0]->ref());
            $this->assertSame(['rescue', 'inventory'], $rows[0]->tags);
            $this->assertSame('rescue', $rows[0]->imageKey);
            $this->assertSame('R2', $rows[0]->subject);
            $this->assertSame('Riley Reporter', $rows[0]->actorLabel);
            $this->assertNotNull($this->source->detail($user, (string) $defect->id), $persona);
        }

        $member = $this->plainMember();
        $this->assertFalse($this->source->visibleTo($member, false));
        $this->assertSame([], $this->source->rows($member, $this->boardQuery(), 10));
        $this->assertSame(0, array_sum($this->source->counts($member, $this->boardQuery())));
        $this->assertNull($this->source->detail($member, (string) $defect->id));
    }

    public function test_display_mode_drops_the_reporter_name_and_member_notes(): void
    {
        $this->defect(attributes: ['notes' => 'Left it at Station 3, sorry']);
        $admin = $this->personas()['admin'];

        $wall = $this->source->rows($admin, $this->boardQuery(display: true), 10)[0];
        $this->assertNull($wall->actorLabel);
        $this->assertSame('Cab', $wall->summary);

        $desk = $this->source->rows($admin, $this->boardQuery(), 10)[0];
        $this->assertSame('Riley Reporter', $desk->actorLabel);
        $this->assertSame('Cab — Left it at Station 3, sorry', $desk->summary);
    }

    public function test_counts_match_the_rows_they_summarise_and_rows_order_by_severity_then_recency(): void
    {
        $routine = $this->defect('Flashlight', ['issue_type' => 'expired', 'first_reported_at' => '2026-10-30 13:00:00']);
        $significant = $this->defect('Ladder latch', ['issue_type' => 'damaged', 'severity' => 'significant', 'first_reported_at' => '2026-10-30 12:00:00']);
        $missing = $this->defect('Halligan', ['first_reported_at' => '2026-10-30 12:30:00']);
        $acknowledged = $this->defect('SCBA mask', ['action_status' => DefectActionStatus::ACKNOWLEDGED, 'first_reported_at' => '2026-10-30 11:00:00']);
        $resolvedToday = $this->defect('Axe', ['action_status' => DefectActionStatus::RESOLVED]);
        $resolvedToday->forceFill(['resolved_at' => '2026-10-30 13:30:00'])->save();
        $resolvedYesterday = $this->defect('Pike pole', ['action_status' => DefectActionStatus::RESOLVED]);
        $resolvedYesterday->forceFill(['resolved_at' => '2026-10-29 11:00:00'])->save();
        $dismissedToday = $this->defect('Wrong item', ['action_status' => DefectActionStatus::DISMISSED]);
        $dismissedToday->forceFill(['resolved_at' => '2026-10-30 13:40:00'])->save();

        $admin = $this->personas()['admin'];
        $query = $this->boardQuery('2026-10-30 10:00:00');
        $rows = $this->allRows($this->source, $admin, $query);
        $ids = array_map(static fn ($row): int => (int) $row->id, $rows);

        $this->assertNotContains($resolvedYesterday->id, $ids);
        $this->assertSame($significant->id, $ids[0], 'high severity first');
        $this->assertSame([$missing->id, $acknowledged->id], array_slice($ids, 1, 2), 'then medium, newest first');
        $this->assertSame($routine->id, $ids[3]);
        $this->assertSame('resolved', collect($rows)->firstWhere('id', (string) $resolvedToday->id)->stage);
        $this->assertSame('other', collect($rows)->firstWhere('id', (string) $dismissedToday->id)->stage);

        $tags = ['rescue', 'engine_puc', 'ladder', 'marine', 'special', 'inventory'];
        $counts = $this->source->counts($admin, $query);
        $this->assertSame($this->countsFromRows($rows, $tags), array_intersect_key($counts, array_flip(['open', 'awaiting_response', 'in_progress', 'high', 'resolved_today', ...$tags])));
        $this->assertSame(['open' => 4, 'awaiting_response' => 3, 'high' => 1, 'resolved_today' => 1], array_intersect_key($counts, array_flip(['open', 'awaiting_response', 'high', 'resolved_today'])));
        $this->assertSame(2, $counts['inventory']);

        foreach ([$this->boardQuery(tags: ['inventory']), $this->boardQuery(severity: 'high'), $this->boardQuery(stage: 'in_progress'), $this->boardQuery(tags: ['marine'])] as $filtered) {
            $this->assertSame($this->countsFromRows($this->allRows($this->source, $admin, $filtered), $tags), array_intersect_key($this->source->counts($admin, $filtered), array_flip(['open', 'awaiting_response', 'in_progress', 'high', 'resolved_today', ...$tags])));
        }
        $this->assertSame([], $this->source->rows($admin, $this->boardQuery(tags: ['marine']), 10));
        $this->assertCount(2, $this->source->rows($admin, $this->boardQuery(), 2), 'the caller limit is honoured');
    }

    public function test_resolved_today_follows_the_eight_am_eastern_boundary_including_the_fall_dst_change(): void
    {
        $admin = $this->personas()['admin'];
        // 2026-11-01 is the fall-back day: the operational day starts 08:00 EST = 13:00 UTC.
        $before = $this->defect('Before boundary', ['action_status' => DefectActionStatus::RESOLVED]);
        $before->forceFill(['resolved_at' => CarbonImmutable::parse('2026-11-01 07:59:59', 'America/New_York')->utc()])->save();
        $at = $this->defect('At boundary', ['action_status' => DefectActionStatus::RESOLVED]);
        $at->forceFill(['resolved_at' => CarbonImmutable::parse('2026-11-01 08:00:00', 'America/New_York')->utc()])->save();
        $this->assertSame('2026-11-01 13:00:00', $at->fresh()->resolved_at->utc()->format('Y-m-d H:i:s'));

        $ids = fn (string $now): array => array_map(static fn ($row): int => (int) $row->id, $this->allRows($this->source, $admin, $this->boardQuery($now)));
        $this->assertSame([$at->id], $ids('2026-11-01 09:00:00'));
        $this->assertSame(1, $this->source->counts($admin, $this->boardQuery('2026-11-01 09:00:00'))['resolved_today']);
        // At 07:59 on the next morning the same window is still active; at 08:00 it has rolled over.
        $this->assertSame([$at->id], $ids('2026-11-02 07:59:00'));
        $this->assertSame([], $ids('2026-11-02 08:00:00'));
        // Before 08:00 on the 1st, the previous day (Oct 31 08:00 EDT onwards) holds the 07:59 resolution.
        $this->assertSame([$before->id], $ids('2026-11-01 07:59:00'));
    }

    public function test_acknowledge_goes_through_the_corrective_action_service_and_the_defects_list_shows_it(): void
    {
        $defect = $this->defect();
        $admin = $this->personas()['logistics admin'];
        $version = $this->versionOf($defect);

        $result = $this->source->perform($admin, (string) $defect->id, 'acknowledge', ['member_message' => 'On it.'], $version);

        $this->assertSame('done', $result->outcome, $result->message);
        $this->assertSame(DefectActionStatus::ACKNOWLEDGED, $defect->fresh()->action_status);
        $this->assertSame(1, ApparatusDefectEvent::query()->where('apparatus_defect_id', $defect->id)->where('event_type', 'acknowledged')->count());
        $this->assertDatabaseHas('apparatus_issue_messages', ['apparatus_defect_id' => $defect->id, 'body' => 'On it.']);
        $this->assertNotSame($version, $this->versionOf($defect));

        $this->actingAs($admin);
        Livewire::test(ListDefects::class)->assertSee('Acknowledged by');

        $detail = $this->source->detail($admin, (string) $defect->id);
        $this->assertNotContains('acknowledge', array_map(static fn ($action): string => $action->key, $detail->actions));
        $this->assertContains('add_note', array_map(static fn ($action): string => $action->key, $detail->actions));
    }

    public function test_assign_offers_only_logistics_reviewers_and_records_the_assignee(): void
    {
        $defect = $this->defect();
        $admin = $this->personas()['admin'];
        $member = $this->plainMember();
        $assign = collect($this->source->detail($admin, (string) $defect->id)->actions)->firstWhere('key', 'assign');
        $options = $assign->fields[0]['options'];
        $this->assertArrayHasKey($admin->id, $options);
        $this->assertArrayNotHasKey($member->id, $options);

        $refused = $this->source->perform($admin, (string) $defect->id, 'assign', ['assignee_id' => (string) $member->id], $this->versionOf($defect));
        $this->assertSame('invalid', $refused->outcome);

        $result = $this->source->perform($admin, (string) $defect->id, 'assign', ['assignee_id' => (string) $admin->id], $this->versionOf($defect));
        $this->assertSame('done', $result->outcome, $result->message);
        $this->assertSame($admin->id, $defect->fresh()->action_details['assigned_to']);
        $this->assertSame(DefectActionStatus::ACTION_PENDING, $defect->fresh()->action_status);
    }

    public function test_a_stale_version_is_a_conflict_and_writes_nothing(): void
    {
        $defect = $this->defect();
        $admin = $this->personas()['admin'];
        $stale = $this->versionOf($defect);
        $this->source->perform($admin, (string) $defect->id, 'add_note', [], $stale); // reported: add_note acknowledges
        $events = ApparatusDefectEvent::query()->count();

        $result = $this->source->perform($admin, (string) $defect->id, 'resolve', ['resolution_type' => 'spare_issued', 'resolution_notes' => 'Spare issued.'], $stale);

        $this->assertSame('conflict', $result->outcome);
        $this->assertSame($events, ApparatusDefectEvent::query()->count());
        $this->assertFalse($defect->fresh()->resolved);
    }

    public function test_unauthorized_viewers_are_forbidden_and_nothing_is_written(): void
    {
        $defect = $this->defect();
        $version = $this->versionOf($defect);

        foreach ([$this->plainMember(), $this->fleetViewer()] as $user) {
            $result = $this->source->perform($user, (string) $defect->id, 'resolve', ['resolution_type' => 'spare_issued', 'resolution_notes' => 'Spare issued.'], $version);
            $this->assertSame('forbidden', $result->outcome);
        }
        $this->assertSame(0, ApparatusDefectEvent::query()->where('apparatus_defect_id', $defect->id)->count());
        $this->assertSame(DefectActionStatus::REPORTED, $defect->fresh()->action_status);

        $viewer = $this->source->detail($this->fleetViewer(), (string) $defect->id);
        $this->assertSame([], $viewer->actions);
        $this->assertNotNull($viewer->readOnlyReason);
    }

    public function test_repeating_a_resolution_is_done_without_a_second_event_or_notification(): void
    {
        $defect = $this->defect();
        $admin = $this->personas()['admin'];
        $version = $this->versionOf($defect);
        $input = ['resolution_type' => 'spare_issued', 'resolution_notes' => 'Spare issued from stock.'];

        $first = $this->source->perform($admin, (string) $defect->id, 'resolve', $input, $version);
        $sent = count(Notification::sentNotifications());
        $second = $this->source->perform($admin, (string) $defect->id, 'resolve', $input, $version);

        $this->assertSame('done', $first->outcome, $first->message);
        $this->assertSame('done', $second->outcome);
        $this->assertSame(1, ApparatusDefectEvent::query()->where('apparatus_defect_id', $defect->id)->where('event_type', 'resolved')->count());
        $this->assertSame($sent, count(Notification::sentNotifications()));
        $this->assertSame(DefectActionStatus::RESOLVED, $defect->fresh()->action_status);
    }

    public function test_a_stale_resolution_with_different_notes_is_refused_and_the_first_notes_stand(): void
    {
        $defect = $this->defect();
        $admin = $this->personas()['admin'];
        $version = $this->versionOf($defect);

        $this->assertSame('done', $this->source->perform($admin, (string) $defect->id, 'resolve', ['resolution_type' => 'spare_issued', 'resolution_notes' => 'Spare issued from stock.'], $version)->outcome);
        $second = $this->source->perform($admin, (string) $defect->id, 'resolve', ['resolution_type' => 'spare_issued', 'resolution_notes' => 'Vendor repaired it.'], $version);

        $this->assertSame('conflict', $second->outcome);
        $this->assertSame('Spare issued from stock.', $defect->fresh()->resolution_notes);
    }

    public function test_invalid_input_and_unknown_actions_write_nothing(): void
    {
        $defect = $this->defect();
        $admin = $this->personas()['admin'];
        $version = $this->versionOf($defect);

        $this->assertSame('invalid', $this->source->perform($admin, (string) $defect->id, 'resolve', ['resolution_type' => 'spare_issued'], $version)->outcome);
        $this->assertSame('invalid', $this->source->perform($admin, (string) $defect->id, 'launch', [], $version)->outcome);
        $this->assertSame('gone', $this->source->perform($admin, '999999', 'resolve', [], $version)->outcome);
        $this->assertSame(0, ApparatusDefectEvent::query()->count());
    }

    public function test_html_in_member_text_is_returned_raw_for_the_view_to_escape(): void
    {
        $defect = $this->defect('<img src=x onerror=alert(1)>', ['notes' => '<script>alert("x")</script>']);
        $admin = $this->personas()['admin'];

        $row = $this->source->rows($admin, $this->boardQuery(), 10)[0];
        $this->assertSame('Missing: <img src=x onerror=alert(1)>', $row->title);
        $this->assertStringContainsString('<script>alert("x")</script>', (string) $row->summary);
        $this->assertSame('<script>alert("x")</script>', $this->source->detail($admin, (string) $defect->id)->body);
    }

    public function test_fifty_issues_load_in_a_bounded_number_of_queries(): void
    {
        $admin = $this->personas()['admin'];
        for ($i = 0; $i < 50; $i++) {
            $rig = $i % 5 === 0 ? $this->rig('Engine '.$i, 'E'.$i) : $this->rig;
            $inspection = $this->inspectionBy($rig, $this->reporter, '2026-10-30 12:00:00');
            $this->openDefect($rig, $inspection, $this->member('Member '.$i), 'Item '.$i);
        }
        $query = $this->boardQuery(tags: ['rescue', 'inventory']);
        $this->source->rows($admin, $query, 1); // warm permission caches

        $rows = [];
        $queries = $this->queriesDuring(function () use ($admin, $query, &$rows): void {
            $rows = $this->source->rows($admin, $query, 50);
            $this->source->counts($admin, $query);
        });

        $this->assertCount(50, $rows);
        $this->assertLessThanOrEqual(12, $queries, 'rows + counts must not grow with the number of issues');
    }
}
