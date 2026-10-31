<?php

declare(strict_types=1);

namespace Tests\Feature\Fleet\OperationalAlerts;

use App\Enums\AccountStatus;
use App\Models\ApparatusChangeout;
use App\Models\ApparatusOperationalAlert;
use App\Models\User;
use App\Support\HubTimezone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Fleet\OperationalAlerts\Concerns\BuildsOperationalAlertFixtures;
use Tests\TestCase;

final class OperationalAlertStationFeedTest extends TestCase
{
    use BuildsOperationalAlertFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->setUpAlertFixtures();
    }

    private function feed(int $stationId): TestResponse
    {
        return $this->getJson("/api/public/stations/{$stationId}/operational-alerts");
    }

    /** @return array<string, mixed> */
    private function onlyAlert(int $stationId, ?User $viewer = null): array
    {
        $alerts = $this->alerts()->forStation($stationId, $viewer ?? $this->memberWithProfile());
        $this->assertCount(1, $alerts);

        return $alerts[0];
    }

    private int $members = 0;

    private function memberWithProfile(): User
    {
        $this->members++;

        return $this->actingAsCanonicalFixture('ALERT-'.$this->members, 'Alert Member '.$this->members);
    }

    public function test_each_station_sees_only_its_own_units_through_the_route(): void
    {
        $r44 = $this->alert($this->r44, ['title' => 'R44 change']);
        $e1 = $this->alert($this->e1, ['title' => 'E1 change']);
        $this->memberWithProfile();

        $station4 = $this->feed($this->station4->id)->assertOk();
        $this->assertSame([$r44->id], array_column($station4->json('alerts'), 'id'));
        $this->assertStringContainsString('no-store', (string) $station4->headers->get('Cache-Control'));
        $this->assertSame([$e1->id], array_column($this->feed($this->station1->id)->assertOk()->json('alerts'), 'id'));
    }

    public function test_an_unknown_station_is_a_404_and_a_signed_out_request_is_refused(): void
    {
        $this->getJson("/api/public/stations/{$this->station4->id}/operational-alerts")->assertUnauthorized();

        $this->memberWithProfile();
        $this->feed(999999)->assertNotFound();
    }

    public function test_the_response_carries_the_contract_shape_and_truck_details(): void
    {
        $alert = $this->alert($this->r44, [
            'title' => 'Upcoming Apparatus Changeout: Rescue 44',
            'reason' => 'Exhaust repair',
            'scheduled_date' => '2026-11-01',
            'scheduled_window' => 'afternoon',
        ]);

        $row = $this->onlyAlert($this->station4->id);

        $this->assertSame([
            'id', 'category', 'category_label', 'title', 'message', 'reason', 'instructions', 'unit_designation',
            'vehicle_number', 'current_vehicle_number', 'current_apparatus_id', 'schedule_label', 'scheduled_date',
            'scheduled_window', 'scheduled_at', 'date_passed', 'workflow', 'action',
        ], array_keys($row));
        $this->assertSame('Upcoming vehicle change', $row['category_label']);
        $this->assertSame('R 44', $row['unit_designation']);
        $this->assertSame('17503', $row['current_vehicle_number']);
        $this->assertSame($this->r44->id, $row['current_apparatus_id']);
        $this->assertSame('Sunday, November 1, afternoon', $row['schedule_label']);
        $this->assertSame('2026-11-01', $row['scheduled_date']);
        $this->assertNull($row['scheduled_at']);
        $this->assertSame(['state' => 'planned', 'label' => 'Planned', 'changeout_id' => null], $row['workflow']);
        $this->assertSame(['kind' => 'start', 'label' => 'Start Changeout', 'apparatus_id' => $this->r44->id, 'alert_id' => $alert->id, 'changeout_id' => null], $row['action']);
    }

    public function test_an_additional_station_also_sees_the_alert_and_other_stations_do_not(): void
    {
        $alert = $this->alert($this->r44, ['additional_station_ids' => [$this->station1->id]]);

        $this->assertSame($alert->id, $this->onlyAlert($this->station1->id)['id']);
        $this->assertSame($alert->id, $this->onlyAlert($this->station4->id)['id']);
        $this->assertSame([], $this->alerts()->forStation(999999, null));
    }

    public function test_a_member_with_no_action_rights_still_sees_the_alert_with_no_action(): void
    {
        $alert = $this->alert();
        $noProfile = User::factory()->create(['account_status' => AccountStatus::Active]);
        $none = ['kind' => 'none', 'label' => null, 'apparatus_id' => null, 'alert_id' => $alert->id, 'changeout_id' => null];

        $this->assertSame($none, $this->onlyAlert($this->station4->id, $noProfile)['action']);
        $this->assertSame($none, $this->alerts()->forStation($this->station4->id, null)[0]['action']);
    }

    public function test_a_fleet_manager_with_no_profile_gets_the_action(): void
    {
        $this->alert();

        $this->assertSame('start', $this->onlyAlert($this->station4->id, $this->fleetManager())['action']['kind']);
    }

    public function test_other_categories_never_offer_an_action(): void
    {
        foreach (['maintenance', 'recall', 'service_interruption', 'general'] as $category) {
            $alert = $this->alert($this->e1, ['category' => $category]);
            $this->assertSame('none', $this->onlyAlert($this->station1->id)['action']['kind'], $category);
            $this->alerts()->close($alert, $this->fleetManager());
        }
    }

    public function test_workflow_moves_from_planned_through_each_state(): void
    {
        $alert = $this->alert();
        $this->assertSame('planned', $this->onlyAlert($this->station4->id)['workflow']['state']);

        $pending = $this->changeout($alert, ['status' => ApparatusChangeout::STATUS_PENDING_FLEET]);
        $row = $this->onlyAlert($this->station4->id);
        $this->assertSame(['state' => 'awaiting_fleet', 'label' => 'Waiting on Fleet', 'changeout_id' => $pending->id], $row['workflow']);
        $this->assertSame(['view', 'View Changeout', $pending->id], [$row['action']['kind'], $row['action']['label'], $row['action']['changeout_id']]);

        $pending->update(['status' => ApparatusChangeout::STATUS_REJECTED]);
        $this->assertSame('planned', $this->onlyAlert($this->station4->id)['workflow']['state']);

        $this->moveR44ToSpare();
        $row = $this->onlyAlert($this->station4->id);
        $this->assertSame('vehicle_changed', $row['workflow']['state']);
        $this->assertSame(['handoff', 'Record Equipment Handoff', $this->spareRescue->id], [$row['action']['kind'], $row['action']['label'], $row['action']['apparatus_id']]);
        $this->assertSame('1033', $row['current_vehicle_number']);
        $this->assertSame('17503', $row['vehicle_number'], 'the alert keeps the truck it was raised on');
        $this->assertSame($this->spareRescue->id, $row['current_apparatus_id']);

        $done = $this->changeout($alert, ['handoff_status' => ApparatusChangeout::HANDOFF_PENDING_VERIFICATION]);
        $row = $this->onlyAlert($this->station4->id);
        $this->assertSame(['state' => 'awaiting_verification', 'label' => 'Waiting on verification', 'changeout_id' => $done->id], $row['workflow']);
        $this->assertSame('view', $row['action']['kind']);

        $done->update(['handoff_status' => ApparatusChangeout::HANDOFF_COMPLETE]);
        $this->assertTrue($this->alerts()->completeFromChangeout($alert, $done, null));
        $this->assertSame([], $this->alerts()->forStation($this->station4->id, null), 'a completed alert leaves the page');
    }

    public function test_a_unit_on_a_truck_other_than_the_planned_one_has_no_action_because_only_fleet_can_resolve_it(): void
    {
        $planned = $this->truck($this->station4, 'R-1034', 'r1034', '1034', 'Rescue', 'Reserve');
        $alert = $this->alert(null, ['planned_replacement_apparatus_id' => $planned->id]);
        $this->assertSame('start', $this->onlyAlert($this->station4->id, $this->fleetManager())['action']['kind'], 'before the move it is an ordinary plan');

        $this->moveR44ToSpare();

        foreach ([$this->memberWithProfile(), $this->fleetManager()] as $viewer) {
            $row = $this->onlyAlert($this->station4->id, $viewer);
            $this->assertSame('vehicle_changed', $row['workflow']['state']);
            $this->assertSame('1033', $row['current_vehicle_number']);
            $this->assertSame(['kind' => 'none', 'label' => null, 'apparatus_id' => null, 'alert_id' => $alert->id, 'changeout_id' => null], $row['action']);
        }
    }

    public function test_a_unit_on_the_planned_truck_records_the_handoff(): void
    {
        $this->alert(null, ['planned_replacement_apparatus_id' => $this->spareRescue->id]);

        $this->moveR44ToSpare();

        $row = $this->onlyAlert($this->station4->id);
        $this->assertSame('vehicle_changed', $row['workflow']['state']);
        $this->assertSame('handoff', $row['action']['kind']);
    }

    public function test_a_pending_changeout_for_the_unit_counts_even_with_no_alert_on_it(): void
    {
        $alert = $this->alert();
        $pending = $this->changeout($alert, ['alert_id' => null, 'status' => ApparatusChangeout::STATUS_PENDING_FLEET]);

        $this->assertSame(['state' => 'awaiting_fleet', 'label' => 'Waiting on Fleet', 'changeout_id' => $pending->id], $this->onlyAlert($this->station4->id)['workflow']);
    }

    public function test_an_expired_notice_drops_and_one_ending_today_stays(): void
    {
        $this->alert($this->r44, ['category' => 'maintenance', 'ends_on' => HubTimezone::today()->subDay()->toDateString()]);
        $this->assertSame([], $this->alerts()->forStation($this->station4->id, null));

        $today = $this->alert($this->e1, ['category' => 'general', 'ends_on' => HubTimezone::today()->toDateString()]);
        $this->assertSame($today->id, $this->onlyAlert($this->station1->id)['id']);
    }

    public function test_a_swap_with_a_past_date_stays_and_says_the_date_passed(): void
    {
        $this->alert($this->r44, [
            'scheduled_date' => HubTimezone::today()->subDay()->toDateString(),
            'ends_on' => HubTimezone::today()->subDays(3)->toDateString(),
        ]);
        $this->alert($this->e1, ['scheduled_date' => HubTimezone::today()->toDateString()]);

        $this->assertTrue($this->onlyAlert($this->station4->id)['date_passed']);
        $this->assertFalse($this->onlyAlert($this->station1->id)['date_passed'], 'today has not passed');
    }

    public function test_swaps_come_first_then_by_date(): void
    {
        $today = HubTimezone::today();
        $second = $this->truck($this->station4, 'E 4', 'e4', '30001', 'Engine', 'In Service');
        $third = $this->truck($this->station4, 'L 4', 'l4', '30002', 'Ladder', 'In Service');
        $notice = $this->notice(['scheduled_date' => $today->toDateString()]);
        $undated = $this->alert($this->r44);
        $later = $this->alert($second, ['scheduled_date' => $today->addDays(2)->toDateString()]);
        $sooner = $this->alert($third, ['scheduled_date' => $today->addDay()->toDateString()]);

        $this->assertSame(
            [$sooner->id, $later->id, $undated->id, $notice->id],
            array_column($this->alerts()->forStation($this->station4->id, null), 'id'),
        );
    }

    public function test_the_feed_does_not_grow_with_the_number_of_alerts_and_viewing_changes_nothing(): void
    {
        $viewer = $this->memberWithProfile();
        foreach (range(1, 6) as $n) {
            $alert = $this->notice(['title' => "Notice {$n}"]);
            $this->changeout($alert, ['handoff_status' => ApparatusChangeout::HANDOFF_PENDING_VERIFICATION]);
        }
        $before = ApparatusOperationalAlert::query()->orderBy('id')->get()->map->getAttributes()->all();
        $trucks = DB::table('apparatuses')->orderBy('id')->get()->all();

        DB::enableQueryLog();
        $rows = $this->alerts()->forStation($this->station4->id, $viewer);
        $tables = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();

        $this->assertCount(6, $rows);
        $this->assertCount(1, array_filter($tables, fn (string $sql): bool => str_contains($sql, 'from "apparatus_operational_alerts"')));
        $this->assertCount(1, array_filter($tables, fn (string $sql): bool => str_contains($sql, 'from "apparatus_changeouts"')));
        $this->assertSame($before, ApparatusOperationalAlert::query()->orderBy('id')->get()->map->getAttributes()->all());
        $this->assertEquals($trucks, DB::table('apparatuses')->orderBy('id')->get()->all());
    }
}
