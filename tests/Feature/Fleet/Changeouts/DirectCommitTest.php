<?php

declare(strict_types=1);

namespace Tests\Feature\Fleet\Changeouts;

use App\Models\Apparatus;
use App\Models\ApparatusDefect;
use App\Models\ApparatusFleetEvent;
use App\Models\ApparatusServiceTicket;
use App\Models\UnitMasterVehicle;
use App\Services\Display\DisplaySnapshotService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Section 4.3: a commit trades unit identities between two truck rows and changes nothing physical.
 */
final class DirectCommitTest extends ChangeoutTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->useSmallChecklists();
    }

    public function test_a_scheduled_change_moves_the_unit_and_completes_its_alert(): void
    {
        $alert = $this->swapAlert($this->unit);

        $response = $this->moveToReserve(['alert_id' => $alert->id]);

        $response->assertCreated()
            ->assertJsonPath('replayed', false)
            ->assertJsonPath('changeout.status', 'completed')
            ->assertJsonPath('changeout.handoff_status', 'complete')
            ->assertJsonPath('changeout.unit_designation', 'E2')
            ->assertJsonPath('changeout.from_vehicle_number', 'V-202')
            ->assertJsonPath('changeout.to_vehicle_number', 'V-909')
            ->assertJsonPath('changeout.moved', self::ENGINE_LINES)
            ->assertJsonPath('changeout.left_behind', 0)
            ->assertJsonPath('changeout.open', 0)
            ->assertJsonPath('changeout.alert_completed', true)
            ->assertJsonPath('changeout.message', 'E2 is now on truck V-909.');

        $alert->refresh();
        $this->assertSame('completed', $alert->status);
        $this->assertSame($response->json('changeout.id'), $alert->completed_by_changeout_id);
        $this->assertSame($this->reserve->id, Apparatus::query()->where('slug', 'e2')->sole()->id);
    }

    public function test_slug_and_designation_move_and_nothing_physical_does(): void
    {
        $master = UnitMasterVehicle::query()->create(['veh_number' => 'V-202', 'section' => 'Frontline and Reserve Fire Apparatus', 'asset_category' => 'vehicle']);
        $this->unit->forceFill(['unit_master_vehicle_id' => $master->id, 'master_link_status' => Apparatus::MASTER_LINK_LINKED])->save();
        $defect = ApparatusDefect::query()->create(['apparatus_id' => $this->unit->id, 'compartment' => 'Cab', 'item' => 'Seat', 'status' => 'open', 'issue_type' => 'damaged', 'reported_date' => now()->toDateString()]);
        $ticket = $this->openTicketOn($this->unit);
        $alert = $this->swapAlert($this->unit, ['apparatus_service_ticket_id' => $ticket->id]);
        $unitStation = $this->unit->station_id;
        $reserveSlug = $this->reserve->slug;

        $this->moveToReserve(['alert_id' => $alert->id])->assertCreated();

        $old = $this->unit->fresh();
        $new = $this->reserve->fresh();
        // The identity moved.
        $this->assertSame(['E2', 'E2', 'Engine 2', 'e2', 'Station 2', 'required', 'engine2'], [$new->designation, $new->unit_id, $new->name, $new->slug, $new->assignment, $new->getRawOriginal('daily_checkout_requirement'), $new->getRawOriginal('daily_checkout_template')]);
        $this->assertSame($unitStation, $new->station_id);
        $this->assertSame(['RE1', 'RE1', 'Reserve Engine 1', $reserveSlug, 'reserve', 'default'], [$old->designation, $old->unit_id, $old->name, $old->slug, $old->getRawOriginal('daily_checkout_requirement'), $old->getRawOriginal('daily_checkout_template')]);
        // New truck takes the unit's status. The old truck has an open ticket, so it goes to the shop, and stays where the swap happened.
        $this->assertSame('In Service', $new->getAttribute('status'));
        $this->assertSame('Maintenance', $old->getAttribute('status'));
        $this->assertSame($unitStation, $old->station_id);
        // The trucks are the same trucks.
        $this->assertSame(['V-202', 'VIN-202', '400.0', 1000, $master->id], [$old->vehicle_number, $old->vin, $old->current_engine_hours, $old->current_miles, $old->unit_master_vehicle_id]);
        $this->assertSame(['V-909', 'VIN-909', '1200.0', 8000], [$new->vehicle_number, $new->vin, $new->current_engine_hours, $new->current_miles]);
        // A swap does not fix a truck.
        $this->assertSame($this->unit->id, $defect->fresh()->apparatus_id);
        $this->assertFalse((bool) $defect->fresh()->resolved);
        $this->assertSame($this->unit->id, $ticket->fresh()->apparatus_id);
        $this->assertSame('submitted', $ticket->fresh()->status);
        $this->assertSame(1, ApparatusServiceTicket::query()->count());
    }

    public function test_the_old_truck_takes_the_status_fleet_planned_for_it(): void
    {
        $alert = $this->swapAlert($this->unit, ['old_truck_status_after' => 'Maintenance']);

        $this->moveToReserve(['alert_id' => $alert->id])->assertCreated();

        $this->assertSame('Maintenance', $this->unit->fresh()->getAttribute('status'));
        $this->assertSame('Maintenance', $this->changeout(1)->move_audit['old_truck_status_after']);
    }

    public function test_a_member_who_names_no_alert_still_gets_the_units_scheduled_change(): void
    {
        $alert = $this->swapAlert($this->unit);

        $response = $this->moveToReserve();

        $response->assertCreated()->assertJsonPath('changeout.status', 'completed')->assertJsonPath('changeout.alert_id', $alert->id);
        $this->assertSame('completed', $alert->fresh()->status);
    }

    public function test_a_fleet_manager_moves_a_unit_with_no_alert(): void
    {
        $this->actAsFleet();

        $this->moveToReserve()->assertCreated()->assertJsonPath('changeout.status', 'completed');

        $this->assertSame('E2', $this->reserve->fresh()->designation);
    }

    public function test_not_even_a_fleet_manager_can_displace_a_frontline_unit(): void
    {
        $this->actAsFleet();

        $this->postChangeout($this->unit, ['to_apparatus_id' => $this->frontline->id])
            ->assertStatus(409)
            ->assertJsonPath('code', 'CHANGEOUT_TRUCK_UNAVAILABLE')
            ->assertJsonPath('message', 'Truck V-303 is assigned to E3. Change that unit first.');

        $this->assertSame('E3', $this->frontline->fresh()->designation);
        $this->assertSame('E2', $this->unit->fresh()->designation);
    }

    public function test_even_a_fleet_manager_needs_a_decision_to_move_a_unit_onto_another_type_of_truck(): void
    {
        $rescue = $this->rescuePair();
        $this->actAsFleet();

        $this->postChangeout($this->unit, ['to_apparatus_id' => $rescue['reserve']->id])->assertCreated()->assertJsonPath('changeout.status', 'pending_fleet');

        $this->assertSame('E2', $this->unit->fresh()->designation);
    }

    public function test_leading_zeros_are_part_of_the_number(): void
    {
        $zero = $this->reserveEngine('002-16');
        $plain = $this->reserveEngine('2-16');
        $alert = $this->swapAlert($this->unit);

        $response = $this->postChangeout($this->unit, ['alert_id' => $alert->id, 'to_vehicle_number' => '2-16']);

        $response->assertCreated()->assertJsonPath('changeout.to_vehicle_number', '2-16');
        $this->assertSame('E2', $plain->fresh()->designation);
        $this->assertSame('002-16', $zero->fresh()->vehicle_number);
        $this->assertSame('RE-002-16', $zero->fresh()->designation);
        $this->assertSame('2-16', $plain->fresh()->vehicle_number);
    }

    public function test_a_number_derived_identity_is_rewritten_for_the_truck_that_carries_it(): void
    {
        ['unit' => $unit, 'reserve' => $reserve] = $this->rescuePair();
        $alert = $this->swapAlert($unit);

        $this->postChangeout($unit, ['alert_id' => $alert->id, 'to_apparatus_id' => $reserve->id])->assertCreated();

        $old = $unit->fresh();
        $this->assertSame(['R-17503', 'RESCUE 17503', 'r17503'], [$old->unit_id, $old->name, $old->slug]);
        $this->assertSame('R 44', $reserve->fresh()->designation);
        $this->assertSame('r-44', $reserve->fresh()->slug);
        $this->assertSame('1033', $reserve->fresh()->vehicle_number);
        $this->assertSame('17503', $old->vehicle_number);
    }

    public function test_the_move_audit_says_what_changed_and_who_did_it(): void
    {
        $alert = $this->swapAlert($this->unit);

        $changeout = $this->changeout($this->moveToReserve(['alert_id' => $alert->id])->assertCreated());

        $audit = $changeout->move_audit;
        $this->assertSame('E2', $audit['before']['from']['designation']);
        $this->assertSame('E2', $audit['after']['to']['designation']);
        $this->assertSame('RE1', $audit['after']['from']['designation']);
        $this->assertSame(0, $audit['corrections_left_on_truck']);
        $this->assertSame($this->member->id, $audit['actor']['id']);
        $this->assertSame($alert->id, $audit['alert_id']);
        $this->assertSame('e2', $changeout->unit_slug);
        $this->assertSame(['submitted', 'committed', 'alert_completed'], ApparatusFleetEvent::query()->where('changeout_id', $changeout->id)->orderBy('id')->pluck('event')->all());
    }

    public function test_the_list_comes_from_the_effective_checklist_and_corrections_stay_on_the_truck(): void
    {
        $changeRow = ['apparatus_id' => $this->unit->id, 'created_at' => now(), 'updated_at' => now()];
        $correctionId = DB::table('apparatus_inventory_corrections')->insertGetId([
            ...$changeRow, 'client_request_id' => (string) Str::uuid(), 'payload_hash' => str_repeat('a', 64), 'reason' => 'not_on_truck',
            'target_snapshot' => '{}', 'checklist_type' => 'engine2', 'checklist_version' => str_repeat('b', 64), 'status' => 'applied',
        ]);
        $setId = DB::table('apparatus_inventory_change_sets')->insertGetId([
            'apparatus_inventory_correction_id' => $correctionId, 'scope' => 'unit', 'idempotency_key' => 'test', 'preview_fingerprint' => str_repeat('c', 64),
            'operation' => '{}', 'results' => '{}', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('apparatus_inventory_changes')->insert([
            ...$changeRow, 'apparatus_inventory_correction_id' => $correctionId, 'apparatus_inventory_change_set_id' => $setId,
            'operation' => 'remove', 'item_key' => self::AXE, 'equipment_key' => 'name:axe', 'payload' => '{}', 'before' => '{}', 'scope' => 'unit', 'status' => 'active',
        ]);
        $alert = $this->swapAlert($this->unit);

        $response = $this->moveToReserve(['alert_id' => $alert->id]);

        $response->assertCreated()->assertJsonPath('changeout.moved', self::ENGINE_LINES - 1);
        $changeout = $this->changeout($response);
        $this->assertSame(1, $changeout->move_audit['corrections_left_on_truck']);
        $this->assertFalse($changeout->items()->where('item_key', self::AXE)->exists());
        $this->assertSame(1, DB::table('apparatus_inventory_changes')->where('apparatus_id', $this->unit->id)->count());
    }

    public function test_it_clears_the_display_caches_after_commit(): void
    {
        Cache::put(DisplaySnapshotService::SNAPSHOT_CACHE_KEY, 'old', 60);
        Cache::put(DisplaySnapshotService::STATIONS_CACHE_KEY, 'old', 60);
        $alert = $this->swapAlert($this->unit);

        $this->moveToReserve(['alert_id' => $alert->id])->assertCreated();

        $this->assertNull(Cache::get(DisplaySnapshotService::SNAPSHOT_CACHE_KEY));
        $this->assertNull(Cache::get(DisplaySnapshotService::STATIONS_CACHE_KEY));
    }

    public function test_a_required_unit_is_sent_to_a_fresh_daily_checkout_on_the_new_truck(): void
    {
        $alert = $this->swapAlert($this->unit);

        $this->moveToReserve(['alert_id' => $alert->id])->assertCreated()->assertJsonPath('changeout.next_daily_checkout_path', '/daily/apparatus/e2');
    }

    public function test_a_unit_with_no_daily_checkout_gets_no_path(): void
    {
        $this->unit->forceFill(['daily_checkout_requirement' => 'exempt'])->save();
        $alert = $this->swapAlert($this->unit);

        $this->moveToReserve(['alert_id' => $alert->id])->assertCreated()->assertJsonPath('changeout.next_daily_checkout_path', null);
    }

    public function test_a_waiting_change_has_no_daily_checkout_path(): void
    {
        $this->moveToReserve()->assertCreated()->assertJsonPath('changeout.status', 'pending_fleet')->assertJsonPath('changeout.next_daily_checkout_path', null);
    }
}
