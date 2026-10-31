<?php

declare(strict_types=1);

namespace Tests\Feature\Fleet\Changeouts;

use App\Models\Apparatus;
use App\Models\ApparatusDefect;
use App\Models\ApparatusFleetEvent;
use App\Models\UnitMasterVehicle;
use App\Services\Fleet\ChangeoutConflictException;
use App\Support\HubTimezone;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Section 4.2: anything but a scheduled change, a return, or Fleet itself waits for Fleet. All the work is saved, and
 * no apparatus changes until the decision.
 */
final class PendingFleetTest extends ChangeoutTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->useSmallChecklists();
    }

    private function assertNoTruckChanged(): void
    {
        $this->assertSame(['E2', 'e2', 'In Service'], [$this->unit->fresh()->designation, $this->unit->fresh()->slug, $this->unit->fresh()->getAttribute('status')]);
        $this->assertSame(['RE1', 'Reserve'], [$this->reserve->fresh()->designation, $this->reserve->fresh()->getAttribute('status')]);
    }

    public function test_no_alert_waits_for_fleet_with_the_work_saved(): void
    {
        $response = $this->moveToReserve([
            'exceptions' => [['item_key' => self::MAP_BOOK, 'handling' => 'leave', 'note' => 'Stays with the truck']],
        ]);

        $response->assertCreated()
            ->assertJsonPath('changeout.status', 'pending_fleet')
            ->assertJsonPath('changeout.to_vehicle_number', 'V-909')
            ->assertJsonPath('changeout.alert_completed', false);
        $this->assertStringContainsString('saved', $response->json('changeout.message'));
        $this->assertNoTruckChanged();
        $changeout = $this->changeout($response);
        $this->assertSame('left_behind', $this->line($changeout, self::MAP_BOOK)->custody_status);
        $this->assertNull($changeout->effective_at);
        $this->assertSame(['submitted'], ApparatusFleetEvent::query()->where('changeout_id', $changeout->id)->pluck('event')->all());
    }

    public function test_an_unlisted_number_creates_only_a_waiting_changeout(): void
    {
        $apparatuses = Apparatus::query()->count();
        $masters = UnitMasterVehicle::query()->count();

        $response = $this->postChangeout($this->unit, ['to_vehicle_number' => 'NEW-77']);

        $response->assertCreated()->assertJsonPath('changeout.status', 'pending_fleet')->assertJsonPath('changeout.to_vehicle_number', 'NEW-77');
        $changeout = $this->changeout($response);
        $this->assertNull($changeout->to_apparatus_id);
        $this->assertFalse($changeout->to_vehicle_listed);
        $this->assertSame($apparatuses, Apparatus::query()->count());
        $this->assertSame($masters, UnitMasterVehicle::query()->count());
        $this->assertNoTruckChanged();
    }

    public function test_a_badly_formed_number_is_refused(): void
    {
        $this->postChangeout($this->unit, ['to_vehicle_number' => '!!!'])->assertUnprocessable()->assertJsonValidationErrors('to_vehicle_number');
    }

    public function test_a_truck_that_is_out_of_service_waits_for_fleet_even_with_an_alert(): void
    {
        $this->reserve->forceFill(['status' => 'Out of Service'])->save();
        $alert = $this->swapAlert($this->unit);

        $response = $this->moveToReserve(['alert_id' => $alert->id]);

        $response->assertCreated()->assertJsonPath('changeout.status', 'pending_fleet');
        $this->assertSame('active', $alert->fresh()->status);
        $this->assertSame('E2', $this->unit->fresh()->designation);
    }

    public function test_a_truck_holding_a_frontline_requirement_waits_for_fleet(): void
    {
        $alert = $this->swapAlert($this->unit);

        $changeout = $this->changeout($this->postChangeout($this->unit, ['alert_id' => $alert->id, 'to_apparatus_id' => $this->frontline->id])
            ->assertCreated()->assertJsonPath('changeout.status', 'pending_fleet'));
        $this->assertSame('E3', $this->frontline->fresh()->designation);

        // Fleet can answer it, but only by saying no: a changeout never displaces a frontline unit.
        try {
            $this->service()->decide($changeout->id, $this->fleetOperationsManager(), 'approve');
            $this->fail('Approval onto a frontline truck has to be refused.');
        } catch (ChangeoutConflictException $refused) {
            $this->assertSame(ChangeoutConflictException::TRUCK_UNAVAILABLE, $refused->errorCode);
            $this->assertSame('Truck V-303 is assigned to E3. Change that unit first.', $refused->getMessage());
        }
        $this->assertSame('pending_fleet', $changeout->fresh()->status);
        $this->assertSame('E3', $this->frontline->fresh()->designation);
    }

    public function test_an_available_truck_of_another_type_waits_for_fleet(): void
    {
        $rescue = $this->rescuePair();
        $alert = $this->swapAlert($this->unit);

        $this->postChangeout($this->unit, ['alert_id' => $alert->id, 'to_apparatus_id' => $rescue['reserve']->id])
            ->assertCreated()->assertJsonPath('changeout.status', 'pending_fleet');
    }

    public function test_a_different_truck_than_fleet_planned_waits_for_fleet_without_the_alert(): void
    {
        $planned = $this->reserveEngine('V-910');
        $alert = $this->swapAlert($this->unit, ['planned_replacement_apparatus_id' => $planned->id]);

        $response = $this->moveToReserve()->assertCreated()->assertJsonPath('changeout.status', 'pending_fleet')->assertJsonPath('changeout.alert_id', null);

        $this->assertNoTruckChanged();
        $this->service()->decide($response->json('changeout.id'), $this->fleetOperationsManager(), 'approve');
        $this->assertSame('active', $alert->fresh()->status);
    }

    public function test_the_truck_fleet_planned_commits_directly(): void
    {
        $alert = $this->swapAlert($this->unit, ['planned_replacement_apparatus_id' => $this->reserve->id]);

        $this->moveToReserve(['alert_id' => $alert->id])->assertCreated()->assertJsonPath('changeout.status', 'completed');
    }

    public function test_an_available_truck_is_eligible_even_though_it_is_not_marked_reserve(): void
    {
        $this->reserve->forceFill(['status' => 'Available'])->save();
        $alert = $this->swapAlert($this->unit);

        $this->moveToReserve(['alert_id' => $alert->id])->assertCreated()->assertJsonPath('changeout.status', 'completed');
    }

    public function test_fleet_is_told_once_with_a_link_to_the_decision(): void
    {
        $id = (string) Str::uuid();

        $this->moveToReserve(['client_submission_id' => $id])->assertCreated();
        $this->moveToReserve(['client_submission_id' => $id])->assertOk();

        $this->assertNotifiedFleetTimes(1);
    }

    public function test_a_direct_commit_tells_nobody(): void
    {
        $this->moveToReserve(['alert_id' => $this->swapAlert($this->unit)->id])->assertCreated();

        $this->assertNotifiedFleetTimes(0);
    }

    public function test_approval_keeps_the_crews_equipment_choices(): void
    {
        $response = $this->moveToReserve(['exceptions' => [
            ['item_key' => self::MAP_BOOK, 'handling' => 'leave', 'note' => 'Stays with the truck'],
            ['item_key' => self::RADIO, 'handling' => 'transfer', 'quantity' => 1, 'note' => 'One is at the shop'],
            ['item_key' => self::AXE, 'condition' => 'damaged', 'note' => 'Cracked handle'],
        ]]);
        $changeout = $this->changeout($response);
        $this->assertSame(0, ApparatusDefect::query()->count(), 'Waiting work files nothing.');
        $manager = $this->actAsFleet();

        $decided = $this->service()->decide($changeout->id, $manager, 'approve', 'Approved by phone.', null, 'Maintenance');

        $this->assertSame('completed', $decided->status);
        $this->assertSame($manager->id, $decided->decided_by_user_id);
        $this->assertSame('Approved by phone.', $decided->decision_note);
        $this->assertNotNull($decided->effective_at);
        $this->assertSame('E2', $this->reserve->fresh()->designation);
        $this->assertSame('Maintenance', $this->unit->fresh()->getAttribute('status'));
        $this->assertSame(['leave', 'left_behind', 'Stays with the truck'], [$this->line($decided, self::MAP_BOOK)->handling, $this->line($decided, self::MAP_BOOK)->custody_status, $this->line($decided, self::MAP_BOOK)->note]);
        $this->assertSame([1, 'moved', 'One is at the shop'], [$this->line($decided, self::RADIO)->quantity_moved, $this->line($decided, self::RADIO)->custody_status, $this->line($decided, self::RADIO)->note]);
        $defect = ApparatusDefect::query()->sole();
        $this->assertSame([$this->unit->id, 'damaged', 'Axe'], [$defect->apparatus_id, $defect->issue_type, $defect->item]);
        $this->assertSame($this->member->id, (int) $defect->first_reported_by_user_id);
        $this->assertSame($defect->id, $this->line($decided, self::AXE)->apparatus_defect_id);
        $this->assertSame(['submitted', 'decided_approved', 'committed'], ApparatusFleetEvent::query()->where('changeout_id', $decided->id)->orderBy('id')->pluck('event')->all());
        $this->assertSame('/daily/apparatus/e2', $this->service()->summary($decided, $manager)['next_daily_checkout_path']);
    }

    public function test_approval_completes_the_alert_when_the_handoff_is_done(): void
    {
        $this->reserve->forceFill(['status' => 'Maintenance'])->save();
        $alert = $this->swapAlert($this->unit);
        $changeout = $this->changeout($this->moveToReserve(['alert_id' => $alert->id])->assertCreated());
        $this->assertTrue($changeout->isPendingFleet());
        $this->assertSame('active', $alert->fresh()->status);

        $this->service()->decide($changeout->id, $this->fleetOperationsManager(), 'approve');

        $this->assertSame('completed', $alert->fresh()->status);
        $this->assertSame($changeout->id, $alert->fresh()->completed_by_changeout_id);
    }

    public function test_an_unlisted_number_is_approved_by_mapping_it_to_an_existing_truck(): void
    {
        $changeout = $this->changeout($this->postChangeout($this->unit, ['to_vehicle_number' => 'NEW-77']));
        $manager = $this->fleetOperationsManager();

        try {
            $this->service()->decide($changeout->id, $manager, 'approve');
            $this->fail('Approval with no truck has to be refused.');
        } catch (ChangeoutConflictException $refused) {
            $this->assertSame(ChangeoutConflictException::TRUCK_REQUIRED, $refused->errorCode);
        }
        $this->assertSame('pending_fleet', $changeout->fresh()->status);
        $this->assertNoTruckChanged();

        $decided = $this->service()->decide($changeout->id, $manager, 'approve', null, $this->reserve->id);

        $this->assertSame([$this->reserve->id, 'V-909', false], [$decided->to_apparatus_id, $decided->to_vehicle_number, $decided->to_vehicle_listed]);
        $this->assertSame('NEW-77', $decided->move_audit['reported_vehicle_number']);
        $this->assertSame('E2', $this->reserve->fresh()->designation);
        $this->assertSame('V-909', $this->reserve->fresh()->vehicle_number);
    }

    public function test_approval_is_refused_if_the_unit_has_since_moved(): void
    {
        $changeout = $this->changeout($this->moveToReserve());
        $changeout->update(['unit_slug' => 'somewhere-else']);

        $this->expectException(ChangeoutConflictException::class);

        try {
            $this->service()->decide($changeout->id, $this->fleetOperationsManager(), 'approve');
        } finally {
            $this->assertNoTruckChanged();
            $this->assertSame('pending_fleet', $changeout->fresh()->status);
        }
    }

    public function test_rejection_changes_nothing_and_keeps_the_record(): void
    {
        $alert = $this->swapAlert($this->unit, ['scheduled_date' => HubTimezone::today()->addDays(3)->toDateString()]);
        $changeout = $this->changeout($this->moveToReserve(['alert_id' => $alert->id]));
        $manager = $this->fleetOperationsManager();

        $decided = $this->service()->decide($changeout->id, $manager, 'reject', 'Wrong truck.');

        $this->assertSame(['rejected', 'Wrong truck.', $manager->id], [$decided->status, $decided->decision_note, $decided->decided_by_user_id]);
        $this->assertNoTruckChanged();
        $this->assertSame('active', $alert->fresh()->status);
        $this->assertSame(['submitted', 'decided_rejected'], ApparatusFleetEvent::query()->where('changeout_id', $changeout->id)->orderBy('id')->pluck('event')->all());
    }

    public function test_only_fleet_managers_decide(): void
    {
        $changeout = $this->changeout($this->moveToReserve());

        $this->expectException(AuthorizationException::class);

        try {
            $this->service()->decide($changeout->id, $this->member, 'approve');
        } finally {
            $this->assertNoTruckChanged();
            $this->assertSame('pending_fleet', $changeout->fresh()->status);
        }
    }

    public function test_an_unknown_decision_is_refused(): void
    {
        $changeout = $this->changeout($this->moveToReserve());

        $this->expectException(ValidationException::class);

        $this->service()->decide($changeout->id, $this->fleetOperationsManager(), 'maybe');
    }

    public function test_repeating_a_decision_changes_nothing_and_a_different_one_is_refused(): void
    {
        $changeout = $this->changeout($this->moveToReserve());
        $manager = $this->fleetOperationsManager();
        $this->service()->decide($changeout->id, $manager, 'approve');

        $this->assertSame('completed', $this->service()->decide($changeout->id, $manager, 'approve')->status);
        $this->assertSame('E2', $this->reserve->fresh()->designation, 'A repeat must not trade the identities back.');

        $this->expectException(ChangeoutConflictException::class);
        $this->service()->decide($changeout->id, $manager, 'reject');
    }

    public function test_the_submitter_or_fleet_can_cancel_a_waiting_change(): void
    {
        $changeout = $this->changeout($this->moveToReserve());

        $cancelled = $this->service()->cancel($changeout->id, $this->member, 'Sent by mistake.');

        $this->assertSame(['cancelled', 'Sent by mistake.'], [$cancelled->status, $cancelled->decision_note]);
        $this->assertNoTruckChanged();
        $this->assertSame('cancelled', $this->service()->cancel($changeout->id, $this->member)->status);
        $this->assertTrue(ApparatusFleetEvent::query()->where('changeout_id', $changeout->id)->where('event', 'cancelled')->exists());
        // A cancelled record frees the unit for a new one.
        $this->moveToReserve()->assertCreated();
    }

    public function test_someone_else_cannot_cancel(): void
    {
        $changeout = $this->changeout($this->moveToReserve());

        $this->expectException(AuthorizationException::class);

        $this->service()->cancel($changeout->id, $this->actAsOtherMember());
    }

    public function test_a_completed_change_cannot_be_cancelled(): void
    {
        $changeout = $this->changeout($this->moveToReserve(['alert_id' => $this->swapAlert($this->unit)->id]));

        $this->expectException(ChangeoutConflictException::class);

        $this->service()->cancel($changeout->id, $this->fleetOperationsManager());
    }
}
