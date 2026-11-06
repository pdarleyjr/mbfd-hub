<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboards;

use App\Livewire\Dashboards\DashboardBoard;
use App\Livewire\Dashboards\IssueActionPanel;
use App\Models\TakeHomeVehicleInspection;
use App\Models\UnitMasterVehicle;
use App\Models\User;
use App\Services\Dashboards\DashboardReadModel;
use App\Services\Dashboards\DashboardSnapshot;
use App\Services\Dashboards\Sources\Vehicle\TakeHomeInspectionNoticeSource;
use App\Support\Dashboards\DashboardIssue;
use App\Support\Dashboards\DashboardView;
use App\Support\Dashboards\IssueSourceRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/** Take Home Vehicle Inspections on the admin dashboards: approved on submission, so a notice that clears itself. */
final class VehicleTakeHomeNoticeSourceTest extends TestCase
{
    use RefreshDatabase;
    use VehicleDashboardFixtures;

    private TakeHomeInspectionNoticeSource $source;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-05 10:30:00', 'America/New_York'));
        Cache::flush();
        $this->setUpVehicleDashboard();
        $this->withoutVite();
        $this->source = app(IssueSourceRegistry::class)->get(TakeHomeInspectionNoticeSource::TYPE);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @param  array<string, mixed>  $overrides */
    private function inspection(array $overrides = []): TakeHomeVehicleInspection
    {
        $this->sequence++;
        $vehicle = UnitMasterVehicle::query()->create(['veh_number' => 'T-'.(100 + $this->sequence), 'make' => 'Ford', 'model' => 'Explorer', 'year' => 2022]);
        $inspector = User::factory()->create(['name' => 'Inspector '.$this->sequence]);

        return TakeHomeVehicleInspection::query()->create(array_merge([
            'reference' => sprintf('THI-261105-%06d', $this->sequence),
            'client_submission_id' => (string) Str::uuid(),
            'unit_master_vehicle_id' => $vehicle->getKey(),
            'inspector_user_id' => $inspector->getKey(),
            'inspector_name' => 'Lt. Dana Reyes',
            'driver_name' => 'Lt. Dana Reyes',
            'vehicle_snapshot' => ['veh_number' => $vehicle->veh_number, 'year' => 2022, 'make' => 'Ford', 'model' => 'Explorer'],
            'odometer' => 41250,
            'checks' => [
                ['key' => 'exterior_body', 'label' => 'Exterior body', 'status' => 'issue', 'issue_description' => 'Dent on door', 'notes' => null],
                ['key' => 'tires_wheels', 'label' => 'Tires & wheels', 'status' => 'good', 'issue_description' => null, 'notes' => null],
            ],
            'has_issues' => true,
            'has_not_checked' => false,
            'keys_present' => true,
            'acceptable_for_use' => true,
            'acknowledged_at' => now(),
            'signature_path' => 'take-home/signature.png',
            'signature_sha256' => str_repeat('a', 64),
            'submitted_at' => now()->subHours(2),
        ], $overrides));
    }

    public function test_it_is_registered_on_the_vehicle_board_and_the_overview_apparatus_lane(): void
    {
        $this->assertContains(TakeHomeInspectionNoticeSource::class, config('dashboards.vehicle_sources'));
        $this->assertSame([DashboardView::VehicleCheckout, DashboardView::Overview], $this->source->views());
        $this->assertSame(DashboardSnapshot::LANE_VEHICLE, DashboardReadModel::laneOf($this->source, DashboardView::Overview));
    }

    public function test_a_submission_is_a_notice_with_vehicle_member_and_time_and_is_never_counted(): void
    {
        $inspection = $this->inspection();
        $viewer = $this->fleetViewer();

        $rows = $this->source->rows($viewer, $this->boardQuery('2026-11-05 10:30:00'), 50);
        $this->assertCount(1, $rows);
        $row = $rows[0];
        $this->assertSame(DashboardIssue::STAGE_NOTICE, $row->stage);
        $this->assertTrue($row->isNotice());
        $this->assertFalse($row->isOpen());
        $this->assertSame('Take-home inspection submitted', $row->title);
        $this->assertSame('#T-101 2022 Ford Explorer', $row->subject);
        $this->assertSame('Lt. Dana Reyes', $row->actorLabel);
        $this->assertSame('Issue found: Exterior body', $row->summary);
        $this->assertSame([], $row->tags);
        $this->assertNull($row->groupKey, 'a notice never joins a unit group, so it is never offered for a bulk action');
        $this->assertSame('/take-home-vehicle-inspections/'.$inspection->reference, $row->sourceUrl);

        $counts = $this->source->counts($viewer, $this->boardQuery('2026-11-05 10:30:00'));
        foreach (['open', 'awaiting_response', 'in_progress', 'high', 'resolved_today', 'rescue', 'engine_puc', 'marine'] as $key) {
            $this->assertSame(0, $counts[$key], $key);
        }
        $this->assertSame(1, $counts['notices']);

        $snapshot = app(DashboardReadModel::class)->load($viewer, DashboardView::VehicleCheckout, fresh: true);
        $this->assertSame(0, $snapshot->count('open', DashboardSnapshot::LANE_VEHICLE));
        $this->assertSame(0, $snapshot->count('awaiting_response', DashboardSnapshot::LANE_VEHICLE));
        $this->assertSame(0, $snapshot->count('high', DashboardSnapshot::LANE_VEHICLE));
        $this->assertSame(['take_home_inspection:'.$inspection->id], array_map(static fn (DashboardIssue $i): string => $i->ref(), $snapshot->rowsIn(DashboardSnapshot::LANE_VEHICLE)));
    }

    public function test_a_notice_clears_itself_24_hours_after_submission(): void
    {
        $this->inspection(['submitted_at' => now()->subHours(23)]);
        $this->inspection(['submitted_at' => now()->subHours(25)]);
        $viewer = $this->fleetViewer();

        $rows = $this->source->rows($viewer, $this->boardQuery('2026-11-05 10:30:00'), 50);
        $this->assertSame(['#T-101 2022 Ford Explorer'], array_map(static fn (DashboardIssue $i): ?string => $i->subject, $rows));
        $this->assertSame(1, $this->source->counts($viewer, $this->boardQuery('2026-11-05 10:30:00'))['notices']);

        // Two hours later the first one has had its day too.
        $this->assertSame([], $this->source->rows($viewer, $this->boardQuery('2026-11-05 12:30:00'), 50));
    }

    public function test_only_fleet_viewers_see_notices_never_on_the_wall_and_never_under_a_filter(): void
    {
        $this->inspection();
        $viewer = $this->fleetViewer();

        $this->assertSame([], $this->source->rows($this->plainMember(), $this->boardQuery('2026-11-05 10:30:00'), 50));
        $this->assertFalse($this->source->visibleTo($viewer, true));
        $this->assertSame([], $this->source->rows($viewer, $this->boardQuery('2026-11-05 10:30:00', display: true), 50));
        $this->assertSame([], $this->source->rows($viewer, $this->boardQuery('2026-11-05 10:30:00', tags: ['rescue']), 50));
        $this->assertSame([], $this->source->rows($viewer, $this->boardQuery('2026-11-05 10:30:00', severity: 'high'), 50));
        $this->assertSame([], $this->source->rows($viewer, $this->boardQuery('2026-11-05 10:30:00', stage: 'awaiting_response'), 50));
    }

    public function test_the_detail_links_the_record_and_clear_is_the_only_thing_that_can_be_done_to_it(): void
    {
        $inspection = $this->inspection(['acceptable_for_use' => false, 'comments' => 'Rear tire looks soft.']);
        $viewer = $this->fleetViewer();

        $detail = $this->source->detail($viewer, (string) $inspection->id);
        $this->assertNotNull($detail);
        // Clearing (archiving, restorable) is covered in Tests\Feature\Fleet\TakeHomeInspectionEditAndClearTest.
        $this->assertSame([TakeHomeInspectionNoticeSource::ACTION_CLEAR], array_map(static fn ($action): string => $action->key, $detail->actions));
        $this->assertSame([], $detail->timeline);
        $this->assertStringContainsString('approved automatically', (string) $detail->readOnlyReason);
        $this->assertSame('Rear tire looks soft.', $detail->body);
        $facts = array_column($detail->facts, 'value', 'label');
        $this->assertSame($inspection->reference, $facts['Reference']);
        $this->assertSame('No', $facts['Acceptable for use']);
        $this->assertSame('41,250', $facts['Odometer']);
        $this->assertSame('Issue found: Exterior body · Not acceptable for use', $detail->issue->summary);

        $this->assertSame('invalid', $this->source->perform($viewer, (string) $inspection->id, 'acknowledge', [], $detail->issue->version)->outcome);
        $this->assertSame('forbidden', $this->source->perform($this->plainMember(), (string) $inspection->id, 'acknowledge', [], 'x')->outcome);
        $this->assertSame('gone', $this->source->perform($viewer, '999999', 'acknowledge', [], 'x')->outcome);
        $this->assertNull($this->source->detail($this->plainMember(), (string) $inspection->id));
        $this->assertNull($this->source->detail($viewer, 'abc'));

        $this->actingAs($viewer);
        Livewire::test(IssueActionPanel::class)
            ->call('openIssue', $detail->issue->ref())
            ->assertSee('Take-home inspection submitted')
            ->assertSee('Notice')
            ->assertSee('Open full record')
            ->assertSee('approved automatically');
    }

    public function test_the_vehicle_board_shows_a_notice_card_and_the_overview_lists_it_in_the_apparatus_lane(): void
    {
        $this->inspection();
        $viewer = $this->fleetViewer();
        $this->actingAs($viewer);

        $vehicle = Livewire::test(DashboardBoard::class, ['dashboard' => 'vehicle-checkout']);
        $html = $vehicle->html();
        $this->assertStringContainsString('data-card="take-home-notices"', $html);
        $this->assertStringContainsString('Take-home inspection submitted', $html);
        $this->assertStringContainsString('data-notice-row', $html);
        $this->assertStringContainsString('data-notice>Notice</span>', $html);
        $this->assertMatchesRegularExpression('#data-stat="open".*?mbfd-dash-stat__value">0<#s', $html);
        // Not in the issues list: that card stays empty.
        $this->assertMatchesRegularExpression('#data-card="priority-apparatus".*?No open vehicle issues for this operational day\.#s', $html);
        // A chip filter is about checkout issues, so the notice card steps aside.
        $this->assertStringNotContainsString('data-card="take-home-notices"', $vehicle->call('setFilter', 'high')->html());

        $overview = Livewire::test(DashboardBoard::class, ['dashboard' => 'overview'])->html();
        $this->assertMatchesRegularExpression('#data-card="lane-vehicle".*?Take-home inspection submitted#s', $overview);
        $this->assertMatchesRegularExpression('#Apparatus</span>\s*<span class="mbfd-dash-chip__count">· 0<#s', $overview);
    }

    public function test_a_member_without_fleet_access_gets_no_notice_card(): void
    {
        $this->inspection();
        $this->actingAs($this->persona(null, ['admin.access', 'admin.stations.view']));

        $html = Livewire::test(DashboardBoard::class, ['dashboard' => 'vehicle-checkout'])->html();
        $this->assertStringNotContainsString('data-card="take-home-notices"', $html);
        $this->assertStringNotContainsString('Take-home inspection submitted', $html);
    }
}
