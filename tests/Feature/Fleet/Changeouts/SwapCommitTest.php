<?php

declare(strict_types=1);

namespace Tests\Feature\Fleet\Changeouts;

use App\Models\ApparatusChangeout;
use App\Models\ApparatusDefect;
use App\Models\ApparatusFleetEvent;
use App\Models\Station;
use Illuminate\Support\Str;

/** A crew's submission happens when it is sent: no approvals, any truck, any day, and the old truck is Out of Service. */
final class SwapCommitTest extends ChangeoutTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->useSmallChecklists();
    }

    public function test_a_member_with_no_alert_commits_instantly(): void
    {
        $response = $this->moveToReserve();

        $response->assertCreated()
            ->assertJsonPath('replayed', false)
            ->assertJsonPath('changeout.status', 'completed')
            ->assertJsonPath('changeout.unit_designation', 'E2')
            ->assertJsonPath('changeout.from_vehicle_number', 'V-202')
            ->assertJsonPath('changeout.to_vehicle_number', 'V-909')
            ->assertJsonPath('changeout.to_apparatus_id', $this->reserve->id)
            ->assertJsonPath('changeout.old_truck_status', 'Out of Service')
            ->assertJsonPath('changeout.alert_id', null)
            ->assertJsonPath('changeout.alert_completed', false)
            ->assertJsonPath('changeout.submitted_by_name', $this->member->name)
            ->assertJsonPath('changeout.moved', self::ENGINE_LINES)
            ->assertJsonPath('changeout.left_behind', 0)
            ->assertJsonPath('changeout.new_truck_record', false)
            ->assertJsonPath('changeout.displaced_unit', null)
            ->assertJsonPath('changeout.message', 'E2 is now on truck V-909. Truck V-202 is marked Out of Service.');
        $this->assertSame([
            'id', 'status', 'unit_designation', 'from_vehicle_number', 'to_vehicle_number', 'to_apparatus_id', 'station_id', 'effective_at',
            'old_truck_status', 'alert_id', 'alert_completed', 'return_of_changeout_id', 'submitted_by_name', 'moved', 'left_behind',
            'next_daily_checkout_path', 'message', 'new_truck_record', 'displaced_unit',
        ], array_keys($response->json('changeout')));

        $changeout = $this->changeout($response);
        $this->assertSame([ApparatusChangeout::STATUS_COMPLETED, ApparatusChangeout::HANDOFF_COMPLETE], [$changeout->status, $changeout->handoff_status]);
        $this->assertSame(['E2', 'In Service', 'V-909', 'VIN-909'], [$this->reserve->fresh()->designation, $this->reserve->fresh()->getAttribute('status'), $this->reserve->fresh()->vehicle_number, $this->reserve->fresh()->getAttribute('vin')]);
        $this->assertSame(['RE1', 'Out of Service', 'V-202', 'VIN-202'], [$this->unit->fresh()->designation, $this->unit->fresh()->getAttribute('status'), $this->unit->fresh()->vehicle_number, $this->unit->fresh()->getAttribute('vin')]);
        $this->assertTrue(ApparatusFleetEvent::query()->where('changeout_id', $changeout->id)->where('event', 'committed')->exists());
    }

    public function test_the_move_audit_says_what_changed_and_who_did_it(): void
    {
        $changeout = $this->changeout($this->moveToReserve()->assertCreated());

        $this->assertSame('E2', $changeout->move_audit['before']['from']['designation']);
        $this->assertSame('In Service', $changeout->move_audit['before']['from']['status']);
        $this->assertSame('Reserve', $changeout->move_audit['before']['to']['status']);
        $this->assertSame('Out of Service', $changeout->move_audit['after']['from']['status']);
        $this->assertSame($this->member->id, $changeout->move_audit['actor']['id']);
    }

    public function test_the_crew_is_sent_on_to_a_daily_checkout_of_the_new_truck(): void
    {
        $this->moveToReserve()->assertJsonPath('changeout.next_daily_checkout_path', '/daily/apparatus/e2');
    }

    public function test_a_truck_with_an_open_ticket_is_allowed_and_the_ticket_stays_open(): void
    {
        $onNew = $this->openTicketOn($this->reserve);
        $onOld = $this->openTicketOn($this->unit);

        $this->moveToReserve()->assertCreated()->assertJsonPath('changeout.status', 'completed');

        $this->assertSame('E2', $this->reserve->fresh()->designation);
        $this->assertSame('In Service', $this->reserve->fresh()->getAttribute('status'));
        $this->assertSame(['submitted', 'submitted'], [$onNew->fresh()->status, $onOld->fresh()->status]);
        $this->assertSame([$this->reserve->id, $this->unit->id], [$onNew->fresh()->apparatus_id, $onOld->fresh()->apparatus_id]);
    }

    public function test_a_defect_stays_on_the_truck_it_is_on(): void
    {
        $defect = $this->unit->openDefects()->create(['compartment' => 'Cab', 'item' => 'Seat', 'status' => 'open', 'issue_type' => 'damaged', 'reported_date' => now()->toDateString()]);

        $this->moveToReserve()->assertCreated();

        $fresh = ApparatusDefect::query()->findOrFail($defect->id);
        $this->assertSame($this->unit->id, $fresh->apparatus_id);
        $this->assertFalse((bool) $fresh->resolved);
    }

    public function test_a_truck_out_of_service_can_be_chosen_and_comes_in_service(): void
    {
        $this->reserve->forceFill(['status' => 'Out of Service'])->save();

        $this->moveToReserve()->assertCreated()->assertJsonPath('changeout.status', 'completed');

        $this->assertSame('In Service', $this->reserve->fresh()->getAttribute('status'));
        $this->assertSame('Out of Service', $this->unit->fresh()->getAttribute('status'));
    }

    public function test_a_truck_of_another_type_can_be_chosen_by_number(): void
    {
        ['reserve' => $rescue] = $this->rescuePair();

        $this->postChangeout($this->unit, ['to_vehicle_number' => '1033'])->assertCreated()->assertJsonPath('changeout.to_apparatus_id', $rescue->id);

        $this->assertSame('E2', $rescue->fresh()->designation);
    }

    public function test_a_truck_holding_a_frontline_unit_trades_identities_and_the_alert_says_so(): void
    {
        ['unit' => $unit] = $this->rescuePair();
        $other = \App\Models\Apparatus::query()->create([
            'station_id' => Station::query()->create(['station_number' => 7, 'name' => 'Station 7', 'address' => '7 Test Way', 'is_active' => true])->id,
            'vin' => 'VIN-17502', 'unit_id' => 'R 4', 'designation' => 'R 4', 'name' => 'Rescue 4', 'slug' => 'r-4', 'type' => 'Rescue', 'vehicle_number' => '17502',
            'status' => 'In Service', 'daily_checkout_requirement' => 'required', 'daily_checkout_template' => 'rescue',
        ]);

        $response = $this->postChangeout($unit, ['to_apparatus_id' => $other->id])->assertCreated();

        $response->assertJsonPath('changeout.displaced_unit', 'R 4')->assertJsonPath('changeout.status', 'completed');
        $this->assertSame(['R 44', 'r-44', 'In Service', 'required'], [$other->fresh()->designation, $other->fresh()->slug, $other->fresh()->getAttribute('status'), $other->fresh()->getRawOriginal('daily_checkout_requirement')]);
        $this->assertSame(['R 4', 'r-4', 'Out of Service', 'required'], [$unit->fresh()->designation, $unit->fresh()->slug, $unit->fresh()->getAttribute('status'), $unit->fresh()->getRawOriginal('daily_checkout_requirement')]);
        $this->assertSame($other->station_id, $unit->fresh()->station_id, 'R 4 stays on its own station page.');
        $this->assertSame($unit->station_id, $other->fresh()->station_id, 'R 44 stays on its own station page.');
        $this->assertSame(
            ['R 4 was on truck 17502 and is now listed on truck 17503, which is Out of Service. Please review.'],
            $this->changeout($response)->reviewNotes(),
        );
    }

    public function test_only_the_same_truck_and_an_ill_formed_number_are_refused(): void
    {
        $this->postChangeout($this->unit, ['to_apparatus_id' => $this->unit->id])->assertUnprocessable()->assertJsonValidationErrors('to_apparatus_id');
        $this->postChangeout($this->unit, ['to_vehicle_number' => 'V-202'])->assertUnprocessable()->assertJsonValidationErrors('to_vehicle_number');
        $this->postChangeout($this->unit, ['to_vehicle_number' => '<script>'])->assertUnprocessable()->assertJsonValidationErrors('to_vehicle_number');
        $this->postChangeout($this->unit)->assertUnprocessable()->assertJsonValidationErrors('to_apparatus_id');

        $this->assertSame(0, ApparatusChangeout::query()->count());
    }

    public function test_a_fleet_manager_can_do_it_too(): void
    {
        $this->actAsFleet();

        $this->moveToReserve()->assertCreated()->assertJsonPath('changeout.status', 'completed');
    }

    public function test_an_old_truck_status_on_the_alert_is_not_read(): void
    {
        $alert = $this->swapAlert($this->unit);
        $alert->forceFill(['old_truck_status_after' => 'Maintenance'])->save();

        $this->moveToReserve()->assertCreated();

        $this->assertSame('Out of Service', $this->unit->fresh()->getAttribute('status'));
    }

    public function test_a_replay_returns_the_first_result_and_tells_fleet_once(): void
    {
        $id = (string) Str::uuid();
        $first = $this->moveToReserve(['client_submission_id' => $id])->assertCreated();

        $this->postJson("/api/public/apparatuses/{$this->unit->id}/changeouts", [
            'client_submission_id' => $id, 'expected_vehicle_number' => 'V-202', 'expected_unit_slug' => 'e2', 'to_apparatus_id' => $this->reserve->id,
        ])->assertOk()->assertJsonPath('replayed', true)->assertJsonPath('changeout.id', $first->json('changeout.id'));

        $this->assertSame(1, ApparatusChangeout::query()->count());
        $this->assertNotifiedFleetTimes(1);
    }

    public function test_the_same_id_with_other_choices_is_a_replay_conflict(): void
    {
        $id = (string) Str::uuid();
        $this->moveToReserve(['client_submission_id' => $id])->assertCreated();

        $this->postJson("/api/public/apparatuses/{$this->unit->id}/changeouts", [
            'client_submission_id' => $id, 'expected_vehicle_number' => 'V-202', 'expected_unit_slug' => 'e2', 'to_apparatus_id' => $this->reserve->id, 'note' => 'Different',
        ])->assertStatus(409)->assertJsonPath('code', 'CHANGEOUT_REPLAY_CONFLICT');
    }

    public function test_a_unit_that_already_moved_is_stale_and_nothing_is_written(): void
    {
        $this->moveToReserve()->assertCreated();
        $other = $this->reserveEngine('V-950');

        $this->postJson("/api/public/apparatuses/{$this->unit->id}/changeouts", [
            'client_submission_id' => (string) Str::uuid(), 'expected_vehicle_number' => 'V-202', 'expected_unit_slug' => 'e2', 'to_apparatus_id' => $other->id,
        ])->assertStatus(409)
            ->assertJsonPath('code', 'CHANGEOUT_STALE')
            ->assertJsonPath('current_vehicle_number', 'V-909')
            ->assertJsonPath('current_apparatus_id', $this->reserve->id);

        $this->assertSame(1, ApparatusChangeout::query()->count());
        $this->assertSame('Reserve', $other->fresh()->getAttribute('status'));
    }

    public function test_a_client_cannot_set_the_changeout_a_return_reverses_or_the_status(): void
    {
        $changeout = $this->changeout($this->moveToReserve(['return_of_changeout_id' => 99, 'status' => 'pending_fleet'])->assertCreated());

        $this->assertNull($changeout->return_of_changeout_id);
        $this->assertSame('completed', $changeout->status);
    }
}
