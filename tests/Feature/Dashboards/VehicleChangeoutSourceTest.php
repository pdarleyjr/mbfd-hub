<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboards;

use App\Livewire\Dashboards\IssueGroupBoard;
use App\Models\Apparatus;
use App\Models\ApparatusChangeout;
use App\Services\Dashboards\DashboardReadModel;
use App\Services\Dashboards\IssueGroups;
use App\Services\Dashboards\Sources\Vehicle\VehicleChangeoutSource;
use App\Services\Fleet\ApparatusChangeoutService;
use App\Support\Dashboards\DashboardView;
use App\Support\Dashboards\IssueAction;
use App\Support\Dashboards\IssueSourceRegistry;
use App\Support\OperationalDisplayWindow;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Feature\Fleet\Concerns\BuildsVehicleSwapFixtures;
use Tests\TestCase;

final class VehicleChangeoutSourceTest extends TestCase
{
    use BuildsVehicleSwapFixtures, VehicleDashboardFixtures {
        BuildsVehicleSwapFixtures::fleetViewer insteadof VehicleDashboardFixtures;
    }
    use RefreshDatabase;

    private VehicleChangeoutSource $source;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSwapFixtures();
        $this->setUpVehicleDashboard();
        $this->source = app(IssueSourceRegistry::class)->get('vehicle_changeout');
    }

    private function change(): ApparatusChangeout
    {
        return app(ApparatusChangeoutService::class)->submit($this->unit->fresh(), $this->member, [
            'client_submission_id' => (string) Str::uuid(),
            'expected_vehicle_number' => 'V-202',
            'expected_unit_slug' => 'e2',
            'to_apparatus_id' => $this->reserve->id,
            'exceptions' => [['item_key' => $this->firstItem(), 'handling' => 'leave']],
        ])['changeout'];
    }

    private function firstItem(): string
    {
        return (string) app(\App\Services\Fleet\ApparatusChangeoutPlanner::class)->context($this->unit->fresh())['equipment']['compartments'][0]['items'][0]['item_key'];
    }

    private function now(): \App\Support\Dashboards\DashboardQuery
    {
        return new \App\Support\Dashboards\DashboardQuery(OperationalDisplayWindow::forNow());
    }

    public function test_it_is_registered_on_the_vehicle_lane(): void
    {
        $this->assertContains(VehicleChangeoutSource::class, config('dashboards.vehicle_sources'));
        $this->assertSame(\App\Services\Dashboards\DashboardSnapshot::LANE_VEHICLE, DashboardReadModel::laneOf($this->source));
    }

    public function test_an_unacknowledged_change_is_one_issue_for_fleet_and_nothing_for_a_member(): void
    {
        $changeout = $this->change();
        $left = $changeout->leftBehindItems()->sole()->item_name;

        foreach ($this->personas() as $persona => $user) {
            $rows = $this->source->rows($user, $this->now(), 10);
            $this->assertCount(1, $rows, $persona);
            $issue = $rows[0];
            $this->assertSame('E2 changed trucks: V-202 to V-909', $issue->title);
            $this->assertSame(['medium', 'awaiting_response', 'E2'], [$issue->severity, $issue->stage, $issue->subject]);
            $this->assertSame('apparatus:'.$this->reserve->id, $issue->groupKey);
            $this->assertStringContainsString($this->member->name.' moved E2 from truck V-202 to truck V-909', (string) $issue->summary);
            $this->assertStringContainsString('Truck V-202 is marked Out of Service.', (string) $issue->summary);
            $this->assertStringContainsString('Left behind: '.$left, (string) $issue->summary);
            $this->assertStringEndsWith('/apparatus-changeouts/'.$changeout->id, (string) $issue->sourceUrl);
            $counts = $this->source->counts($user, $this->now());
            $this->assertSame([1, 1, 0, 0, 0], [$counts['open'], $counts['awaiting_response'], $counts['in_progress'], $counts['high'], $counts['resolved_today']], $persona);
        }

        $member = $this->plainMember();
        $this->assertSame([], $this->source->rows($member, $this->now(), 10));
        $this->assertNull($this->source->detail($member, (string) $changeout->id));
        $this->assertSame(0, array_sum($this->source->counts($member, $this->now())));
    }

    public function test_acknowledge_clears_it_and_it_stays_as_resolved_for_the_day(): void
    {
        $changeout = $this->change();
        $admin = $this->personas()['admin'];
        $detail = $this->source->detail($admin, (string) $changeout->id);
        $this->assertSame(['acknowledge'], array_map(static fn (IssueAction $action): string => $action->key, $detail->actions));
        $this->assertSame(IssueAction::BULK_DIRECT, $detail->actions[0]->bulk);

        $result = $this->source->perform($admin, (string) $changeout->id, 'acknowledge', [], $detail->issue->version);

        $this->assertTrue($result->succeeded());
        $fresh = $changeout->fresh();
        $this->assertSame($admin->id, $fresh->acknowledged_by_user_id);
        $rows = $this->source->rows($admin, $this->now(), 10);
        $this->assertSame(['resolved', 'Acknowledged'], [$rows[0]->stage, $rows[0]->statusLabel]);
        $counts = $this->source->counts($admin, $this->now());
        $this->assertSame([0, 1], [$counts['open'], $counts['resolved_today']]);
        $this->assertSame([], $this->source->detail($admin, (string) $changeout->id)->actions);

        $this->assertTrue($this->source->perform($admin, (string) $changeout->id, 'acknowledge', [], $detail->issue->version)->succeeded(), 'A repeat is a no-op.');
    }

    public function test_it_leaves_the_board_after_the_operational_day(): void
    {
        $changeout = $this->change();
        $admin = $this->personas()['admin'];
        app(ApparatusChangeoutService::class)->acknowledge($changeout->id, $admin);

        $this->travel(3)->days();

        $this->assertSame([], $this->source->rows($admin, $this->now(), 10));
    }

    public function test_a_viewer_sees_it_read_only_and_is_refused_acknowledging(): void
    {
        $changeout = $this->change();
        $viewer = $this->fleetViewer();

        $detail = $this->source->detail($viewer, (string) $changeout->id);
        $this->assertSame([], $detail->actions);
        $this->assertNotNull($detail->readOnlyReason);

        $this->assertSame('forbidden', $this->source->perform($viewer, (string) $changeout->id, 'acknowledge', [], $detail->issue->version)->outcome);
        $this->assertNull($changeout->fresh()->acknowledged_at);
    }

    public function test_a_stale_version_writes_nothing_and_an_unknown_action_is_refused(): void
    {
        $changeout = $this->change();
        $admin = $this->personas()['admin'];

        $this->assertSame('conflict', $this->source->perform($admin, (string) $changeout->id, 'acknowledge', [], 'other')->outcome);
        $this->assertSame('invalid', $this->source->perform($admin, (string) $changeout->id, 'approve', [], 'open')->outcome);
        $this->assertSame('gone', $this->source->perform($admin, '999999', 'acknowledge', [], 'open')->outcome);
        $this->assertNull($changeout->fresh()->acknowledged_at);
    }

    public function test_display_mode_names_nobody(): void
    {
        $this->change();

        $issue = $this->source->rows($this->personas()['admin'], new \App\Support\Dashboards\DashboardQuery(OperationalDisplayWindow::forNow(), true), 10)[0];

        $this->assertNull($issue->actorLabel);
        $this->assertNull($issue->summary);
    }

    public function test_the_row_query_does_not_grow_with_the_rows(): void
    {
        $first = $this->change();
        $admin = $this->personas()['admin'];
        foreach (range(1, 4) as $n) {
            $reserve = Apparatus::query()->create(['station_id' => $this->reserve->station_id, 'unit_id' => 'RX'.$n, 'designation' => 'RX'.$n, 'name' => 'Reserve '.$n, 'type' => 'Engine', 'vehicle_number' => 'X-'.$n, 'status' => 'Reserve', 'daily_checkout_requirement' => 'reserve']);
            ApparatusChangeout::query()->create([...$first->only(['unit_slug', 'unit_designation', 'from_apparatus_id', 'from_vehicle_number', 'status', 'handoff_status', 'submitted_by_name', 'payload_hash']), 'client_submission_id' => (string) Str::uuid(), 'to_apparatus_id' => $reserve->id, 'to_vehicle_number' => 'X-'.$n, 'effective_at' => now()]);
        }

        DB::enableQueryLog();
        $rows = $this->source->rows($admin, $this->now(), 200);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(5, $rows);
        $this->assertLessThanOrEqual(10, count($queries));
    }

    public function test_it_groups_with_the_rest_of_the_units_issues_and_bulk_acknowledge_works(): void
    {
        $first = $this->change();
        $second = ApparatusChangeout::query()->create([...$first->only(['unit_slug', 'unit_designation', 'from_apparatus_id', 'to_apparatus_id', 'from_vehicle_number', 'to_vehicle_number', 'status', 'handoff_status', 'submitted_by_name', 'payload_hash']), 'client_submission_id' => (string) Str::uuid(), 'effective_at' => now()]);
        $admin = $this->personas()['admin'];
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $snapshot = app(DashboardReadModel::class)->load($admin, DashboardView::VehicleCheckout, fresh: true);
        $group = collect(IssueGroups::of($snapshot->rows))->firstWhere('key', 'apparatus:'.$this->reserve->id);
        $this->assertNotNull($group);
        $this->assertCount(2, array_filter($group['issues'], static fn ($issue): bool => $issue->type === 'vehicle_changeout'));

        $board = Livewire::test(IssueGroupBoard::class, ['group' => 'apparatus:'.$this->reserve->id]);
        $section = collect($board->get('sections'))->firstWhere('type', 'vehicle_changeout');
        $this->assertSame('Vehicle changes', $section['label']);
        $this->assertSame('acknowledge', $section['actions'][0]['key']);

        $board->call('apply', 'vehicle_changeout', 'acknowledge', $board->get('token'), false);

        $this->assertNotNull($first->fresh()->acknowledged_at);
        $this->assertNotNull($second->fresh()->acknowledged_at);
        $this->assertSame('success', $board->get('notice')['tone']);
    }
}
