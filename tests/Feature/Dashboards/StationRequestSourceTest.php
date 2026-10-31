<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboards;

use App\Filament\Resources\StationRequestResource\Pages\ListStationRequests;
use App\Models\StationRequestUpdate;
use App\Services\Dashboards\Sources\Station\StationRequestSource;
use App\Support\Dashboards\DashboardIssue;
use App\Support\Dashboards\DashboardQuery;
use App\Support\Dashboards\DashboardView;
use App\Support\Dashboards\IssueSourceRegistry;
use App\Support\OperationalDisplayWindow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class StationRequestSourceTest extends TestCase
{
    use RefreshDatabase;
    use StationDashboardFixtures;

    private function source(): StationRequestSource
    {
        return app(StationRequestSource::class);
    }

    public function test_it_is_registered_for_station_operations_and_the_overview(): void
    {
        $registry = app(IssueSourceRegistry::class);

        $this->assertSame($registry->get('station_request'), $registry->forView(DashboardView::StationOperations)[0]);
        $this->assertContains(DashboardView::Overview, $registry->get('station_request')->views());
    }

    public function test_visibility_follows_the_resource_permission(): void
    {
        $request = $this->stationRequest();
        $outsider = $this->adminWith(['admin.fleet.view']);
        $viewer = $this->adminWith(['admin.stations.view']);

        $this->assertSame([], $this->source()->rows($outsider, DashboardQuery::now(), 50));
        $this->assertSame(0, array_sum($this->source()->counts($outsider, DashboardQuery::now())));
        $this->assertNull($this->source()->detail($outsider, (string) $request->id));
        $this->assertSame('forbidden', $this->source()->perform($outsider, (string) $request->id, 'acknowledge', [], 'x')->outcome);

        $this->assertCount(1, $this->source()->rows($viewer, DashboardQuery::now(), 50));
        $detail = $this->source()->detail($viewer, (string) $request->id);
        $this->assertSame([], $detail->actions);
        $this->assertSame('You can view station requests but not update them.', $detail->readOnlyReason);
        $this->assertSame('forbidden', $this->source()->perform($viewer, (string) $request->id, 'acknowledge', [], $detail->issue->version)->outcome);
        $this->assertSame('pending', $request->fresh()->status);
    }

    public function test_rows_project_the_request_with_tags_severity_and_station_image(): void
    {
        $viewer = $this->adminWith(['admin.stations.view']);
        $repair = $this->stationRequest(['priority' => 'critical']);
        $equipment = $this->stationRequest(['station_id' => $this->station(5)->id, 'request_type' => 'equipment', 'priority' => 'low', 'title' => 'Radio']);

        $rows = collect($this->source()->rows($viewer, DashboardQuery::now(), 50))->keyBy('id');

        $this->assertSame(['facilities'], $rows[$repair->id]->tags);
        $this->assertSame('high', $rows[$repair->id]->severity);
        $this->assertSame('station:1', $rows[$repair->id]->imageKey);
        $this->assertSame('Station 1', $rows[$repair->id]->subject);
        $this->assertSame('Pat Requester', $rows[$repair->id]->actorLabel);
        $this->assertSame('awaiting_response', $rows[$repair->id]->stage);
        $this->assertStringContainsString('/admin/station-requests/'.$repair->id, (string) $rows[$repair->id]->sourceUrl);
        $this->assertSame(['supplies'], $rows[$equipment->id]->tags);
        $this->assertSame('low', $rows[$equipment->id]->severity);
        $this->assertNull($rows[$equipment->id]->imageKey);

        $wall = $this->source()->rows($viewer, DashboardQuery::now(displayMode: true), 50);
        $this->assertCount(2, $wall);
        $this->assertNull($wall[0]->actorLabel);
        $this->assertNull($wall[0]->summary);
    }

    public function test_counts_match_rows_and_respect_the_window(): void
    {
        $viewer = $this->adminWith(['admin.stations.view']);
        $this->stationRequest(['priority' => 'high']);
        $this->stationRequest(['status' => 'in_progress', 'request_type' => 'equipment']);
        $this->stationRequest(['status' => 'completed', 'completed_at' => now()]);
        $this->stationRequest(['status' => 'denied', 'denied_at' => now(), 'request_type' => 'equipment']);
        $this->stationRequest(['status' => 'completed', 'completed_at' => now()->subDays(3)]);
        $archived = $this->stationRequest(['priority' => 'critical']);
        $archived->forceFill(['archived_at' => now()])->save();

        $rows = $this->source()->rows($viewer, DashboardQuery::now(), 200);
        $counts = $this->source()->counts($viewer, DashboardQuery::now());
        $stages = collect($rows)->countBy(fn (DashboardIssue $i): string => $i->stage);

        $this->assertCount(4, $rows);
        $this->assertSame(['open' => 2, 'awaiting_response' => 1, 'in_progress' => 1, 'high' => 1, 'resolved_today' => 2, 'facilities' => 1, 'supplies' => 1, 'access_it' => 0, 'other' => 0], $counts);
        $this->assertSame($counts['resolved_today'], $stages['resolved']);
        $this->assertSame($counts['open'], count($rows) - $stages['resolved']);
        $this->assertSame('high', $rows[0]->severity, 'Open, most severe first.');

        $filtered = new DashboardQuery(OperationalDisplayWindow::forNow(), tags: ['supplies']);
        $this->assertCount(2, $this->source()->rows($viewer, $filtered, 50));
        $filteredCounts = $this->source()->counts($viewer, $filtered);
        $this->assertSame(1, $filteredCounts['open']);
        $this->assertSame(1, $filteredCounts['facilities'], 'Tag chips keep their own numbers under a tag filter.');
    }

    public function test_the_eight_oclock_boundary_holds_on_a_dst_date(): void
    {
        $viewer = $this->adminWith(['admin.stations.view']);
        // 2026-03-08: clocks spring forward at 02:00, so 08:00 EDT is 12:00 UTC.
        $request = $this->stationRequest(['status' => 'completed', 'completed_at' => '2026-03-08 11:59:59']);
        $query = new DashboardQuery(OperationalDisplayWindow::forNow('2026-03-08 08:30:00'));
        $this->assertSame(0, $this->source()->counts($viewer, $query)['resolved_today']);

        $request->forceFill(['completed_at' => '2026-03-08 12:00:00'])->save();
        $this->assertSame(1, $this->source()->counts($viewer, $query)['resolved_today']);

        // Before 08:00 the operational day is still the previous one.
        $early = new DashboardQuery(OperationalDisplayWindow::forNow('2026-03-08 07:59:00'));
        $this->assertSame(0, $this->source()->counts($viewer, $early)['resolved_today']);
        $this->assertSame([], $this->source()->rows($viewer, $early, 50));
    }

    public function test_acknowledge_runs_through_the_workflow_service_and_the_resource_sees_it(): void
    {
        $manager = $this->adminWith(['admin.stations.view', 'admin.stations.manage']);
        $request = $this->stationRequest();
        $detail = $this->source()->detail($manager, (string) $request->id);
        $this->assertSame(['acknowledge', 'approve', 'assign', 'set_status', 'post_update', 'complete', 'deny'], array_map(fn ($a) => $a->key, $detail->actions));

        $result = $this->source()->perform($manager, (string) $request->id, 'acknowledge', ['public_note' => 'We are on it.'], $detail->issue->version);

        $this->assertSame('done', $result->outcome);
        $fresh = $request->fresh();
        $this->assertSame('acknowledged', $fresh->status);
        $this->assertSame($manager->id, (int) $fresh->acknowledged_by);
        $this->assertSame('We are on it.', $fresh->current_public_response);
        $update = StationRequestUpdate::query()->where('station_request_id', $request->id)->sole();
        $this->assertSame(['event' => 'workflow_transition'], $update->metadata);

        $this->actingAs($manager);
        Livewire::test(ListStationRequests::class)->call('loadTable')
            ->filterTable('status', 'acknowledged')->assertCanSeeTableRecords([$fresh]);
    }

    public function test_an_identical_repeat_is_a_no_op_and_a_stale_write_is_a_conflict(): void
    {
        $manager = $this->adminWith(['admin.stations.view', 'admin.stations.manage']);
        $other = $this->adminWith(['admin.stations.view', 'admin.stations.manage']);
        $request = $this->stationRequest();
        $version = $this->source()->detail($manager, (string) $request->id)->issue->version;

        $this->assertSame('done', $this->source()->perform($manager, (string) $request->id, 'acknowledge', [], $version)->outcome);
        $this->assertSame('done', $this->source()->perform($manager, (string) $request->id, 'acknowledge', [], $version)->outcome);
        $this->assertSame(1, StationRequestUpdate::query()->where('station_request_id', $request->id)->count());

        $this->assertSame('conflict', $this->source()->perform($other, (string) $request->id, 'set_status', ['status' => 'in_progress'], $version)->outcome);
        $this->assertSame('acknowledged', $request->fresh()->status);
        $this->assertSame(1, StationRequestUpdate::query()->where('station_request_id', $request->id)->count());
    }

    public function test_deny_needs_a_reason_and_assign_needs_an_eligible_manager(): void
    {
        $manager = $this->adminWith(['admin.stations.view', 'admin.stations.manage']);
        $viewer = $this->adminWith(['admin.stations.view']);
        $request = $this->stationRequest();
        $version = $this->source()->detail($manager, (string) $request->id)->issue->version;

        $denied = $this->source()->perform($manager, (string) $request->id, 'deny', ['public_note' => ''], $version);
        $this->assertSame('invalid', $denied->outcome);
        $this->assertArrayHasKey('public_note', $denied->errors);

        $assign = collect($this->source()->detail($manager, (string) $request->id)->actions)->firstWhere('key', 'assign');
        $this->assertArrayHasKey($manager->id, $assign->fields[0]['options']);
        $this->assertArrayNotHasKey($viewer->id, $assign->fields[0]['options']);
        $this->assertSame('invalid', $this->source()->perform($manager, (string) $request->id, 'assign', ['assigned_to_user_id' => $viewer->id], $version)->outcome);
        $this->assertSame('done', $this->source()->perform($manager, (string) $request->id, 'assign', ['assigned_to_user_id' => $manager->id], $version)->outcome);
        $this->assertSame($manager->id, (int) $request->fresh()->assigned_to_user_id);
        $this->assertSame('pending', $request->fresh()->status);

        $version = $this->source()->detail($manager, (string) $request->id)->issue->version;
        $this->assertSame('done', $this->source()->perform($manager, (string) $request->id, 'deny', ['public_note' => 'Duplicate of an existing request.'], $version)->outcome);
        $this->assertSame('Denied: Duplicate of an existing request.', $request->fresh()->current_public_response);
        $closed = $this->source()->detail($manager, (string) $request->id);
        $this->assertSame([], $closed->actions);
        $this->assertNotNull($closed->readOnlyReason);
    }

    public function test_rows_use_a_fixed_number_of_queries_for_fifty_requests(): void
    {
        $viewer = $this->adminWith(['admin.stations.view']);
        $this->stationRequest();
        $this->source()->rows($viewer, DashboardQuery::now(), 50);
        $small = $this->queriesFor(fn () => $this->source()->rows($viewer, DashboardQuery::now(), 50));
        foreach (range(1, 49) as $i) {
            $this->stationRequest(['station_id' => $this->station($i % 6 + 1)->id, 'title' => 'Request '.$i]);
        }

        $large = $this->queriesFor(fn () => $this->assertCount(50, $this->source()->rows($viewer, DashboardQuery::now(), 50)));

        $this->assertSame($small, $large);
        $this->assertLessThanOrEqual(3, $large);
    }
}
