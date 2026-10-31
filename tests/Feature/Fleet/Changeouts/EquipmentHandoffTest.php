<?php

declare(strict_types=1);

namespace Tests\Feature\Fleet\Changeouts;

use App\Models\Apparatus;
use App\Models\ApparatusChangeout;
use App\Models\ApparatusDefect;
use App\Models\ApparatusDefectEvent;
use App\Models\ApparatusFleetEvent;
use App\Models\ApparatusOperationalAlert;
use App\Services\Fleet\ApparatusChangeoutPlanner;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Sections 2 and 3: what came with the crew, what stayed, and the separate accountability step for controlled items.
 */
final class EquipmentHandoffTest extends ChangeoutTestCase
{
    private ApparatusOperationalAlert $alert;

    private ApparatusOperationalAlert $engineAlert;

    /** @var array{unit: Apparatus, reserve: Apparatus} */
    private array $rescue;

    private const MED_BOX = 'patient-item-2';

    private const AIRWAY = 'patient-item-1';

    private const LIFEPAK = 'patient-item-3';

    private const SEAL = 'field:narcotic_seal';

    private const BOOK = 'field:narcotic_book';

    protected function setUp(): void
    {
        parent::setUp();
        $this->useSmallChecklists();
        $this->engineAlert = $this->swapAlert($this->unit);
        $this->alert = $this->engineAlert;
    }

    /** @param list<array<string, mixed>> $exceptions */
    private function handoff(array $exceptions): ApparatusChangeout
    {
        return $this->changeout($this->moveToReserve(['alert_id' => $this->engineAlert->id, 'exceptions' => $exceptions])->assertCreated());
    }

    /** @param list<array<string, mixed>> $exceptions */
    private function rescueHandoff(array $exceptions = []): ApparatusChangeout
    {
        $this->rescue = $this->rescuePair();
        $this->alert = $this->swapAlert($this->rescue['unit']);

        return $this->changeout($this->postChangeout($this->rescue['unit'], [
            'alert_id' => $this->alert->id, 'to_apparatus_id' => $this->rescue['reserve']->id, 'exceptions' => $exceptions,
        ])->assertCreated());
    }

    public function test_transfer_all_is_the_default(): void
    {
        $changeout = $this->handoff([]);

        $this->assertSame(self::ENGINE_LINES, $changeout->items()->count());
        $this->assertSame(self::ENGINE_LINES, $changeout->items()->where('handling', 'transfer')->where('custody_status', 'moved')->where('condition', 'ok')->count());
        $this->assertSame(0, $changeout->items()->whereNotNull('quantity_moved')->count());
        $radio = $this->line($changeout, self::RADIO);
        $this->assertSame(['cab', 'Front Cab', 'Portable radio', 2], [$radio->compartment_id, $radio->compartment_name, $radio->item_name, $radio->expected_quantity]);
        $this->assertNull($this->line($changeout, self::MAP_BOOK)->expected_quantity, 'An item with no count is not a count of one.');
        $this->assertSame('complete', $changeout->handoff_status);
        $this->assertSame('completed', $this->alert->fresh()->status);
    }

    public function test_leave_with_a_note_stays_on_the_old_truck(): void
    {
        $changeout = $this->handoff([['item_key' => self::MAP_BOOK, 'handling' => 'leave', 'note' => 'Spare stays with the truck']]);

        $line = $this->line($changeout, self::MAP_BOOK);
        $this->assertSame(['leave', 'left_behind', 'Spare stays with the truck'], [$line->handling, $line->custody_status, $line->note]);
        $this->assertSame('complete', $changeout->handoff_status);
    }

    public function test_a_partial_count_is_moved_and_keeps_the_note(): void
    {
        $changeout = $this->handoff([['item_key' => self::RADIO, 'handling' => 'transfer', 'quantity' => 1, 'note' => 'One is at the shop']]);

        $line = $this->line($changeout, self::RADIO);
        $this->assertSame(['transfer', 'moved', 1, 'One is at the shop'], [$line->handling, $line->custody_status, $line->quantity_moved, $line->note]);
    }

    public function test_a_count_of_zero_is_nothing_moved(): void
    {
        $changeout = $this->handoff([['item_key' => self::RADIO, 'handling' => 'transfer', 'quantity' => 0]]);

        $line = $this->line($changeout, self::RADIO);
        $this->assertSame(['leave', 'left_behind', 0], [$line->handling, $line->custody_status, $line->quantity_moved]);
    }

    public function test_a_count_needs_a_known_quantity_and_has_to_fit_it(): void
    {
        foreach ([
            ['item_key' => self::MAP_BOOK, 'handling' => 'transfer', 'quantity' => 1],
            ['item_key' => self::RADIO, 'handling' => 'transfer', 'quantity' => 3],
            ['item_key' => self::RADIO, 'handling' => 'transfer', 'quantity' => -1],
            ['item_key' => self::RADIO, 'handling' => 'leave', 'quantity' => 1],
            ['item_key' => self::RADIO, 'handling' => 'transfer', 'quantity' => 1, 'condition' => 'damaged'],
        ] as $exception) {
            $this->moveToReserve(['alert_id' => $this->alert->id, 'exceptions' => [$exception]])->assertUnprocessable();
        }
        $this->assertSame(0, ApparatusChangeout::query()->count());
    }

    public function test_an_unknown_item_is_refused_and_nothing_moves(): void
    {
        $this->moveToReserve(['alert_id' => $this->alert->id, 'exceptions' => [['item_key' => 'not-on-this-truck', 'handling' => 'leave']]])
            ->assertUnprocessable()->assertJsonValidationErrors('exceptions');

        $this->assertSame(0, ApparatusChangeout::query()->count());
        $this->assertSame('E2', $this->unit->fresh()->designation);
        $this->assertSame('active', $this->alert->fresh()->status);
    }

    public function test_the_same_item_twice_is_refused(): void
    {
        $this->moveToReserve(['alert_id' => $this->alert->id, 'exceptions' => [
            ['item_key' => self::AXE, 'handling' => 'leave'], ['item_key' => self::AXE, 'handling' => 'transfer'],
        ]])->assertUnprocessable();
    }

    public function test_missing_and_damaged_file_defects_on_the_old_truck_and_never_move(): void
    {
        $changeout = $this->handoff([
            ['item_key' => self::AXE, 'handling' => 'transfer', 'condition' => 'missing', 'note' => 'Not in the compartment'],
            ['item_key' => self::HALLIGAN, 'condition' => 'damaged', 'note' => 'Bent fork'],
        ]);

        $missing = $this->line($changeout, self::AXE);
        $damaged = $this->line($changeout, self::HALLIGAN);
        $this->assertNotSame('moved', $missing->custody_status);
        $this->assertNotSame('moved', $damaged->custody_status);
        $this->assertSame(['leave', 'left_behind', 'missing'], [$missing->handling, $missing->custody_status, $missing->condition]);
        $this->assertSame(['leave', 'left_behind', 'damaged'], [$damaged->handling, $damaged->custody_status, $damaged->condition]);

        $defects = ApparatusDefect::query()->orderBy('item')->get();
        $this->assertCount(2, $defects);
        foreach ($defects as $defect) {
            $this->assertSame($this->unit->id, $defect->apparatus_id, 'The finding stays with the physical truck.');
            $this->assertFalse((bool) $defect->resolved);
            $this->assertSame($this->member->id, (int) $defect->first_reported_by_user_id);
        }
        $this->assertSame(['Axe', 'missing', 'Compartment 1', 'comp_1-item-2', 'Not in the compartment'], [$defects[0]->item, $defects[0]->issue_type, $defects[0]->compartment, $defects[0]->item_key, $defects[0]->notes]);
        $this->assertSame(['Halligan', 'damaged'], [$defects[1]->item, $defects[1]->issue_type]);
        $this->assertSame($defects[0]->id, $missing->apparatus_defect_id);
        $this->assertSame($defects[1]->id, $damaged->apparatus_defect_id);
        $this->assertSame($this->member->id, (int) ApparatusDefectEvent::query()->where('apparatus_defect_id', $defects[0]->id)->sole()->actor_user_id);
        $this->assertSame('complete', $changeout->handoff_status, 'An open defect is not an open handoff.');
    }

    public function test_a_finding_joins_the_open_defect_for_that_item(): void
    {
        $existing = ApparatusDefect::query()->create(['apparatus_id' => $this->unit->id, 'compartment' => 'Compartment 1', 'item' => 'Axe', 'status' => 'open', 'issue_type' => 'damaged', 'notes' => 'First report', 'reported_date' => now()->toDateString()]);

        $changeout = $this->handoff([['item_key' => self::AXE, 'condition' => 'missing']]);

        $this->assertSame(1, ApparatusDefect::query()->count());
        $this->assertSame($existing->id, $this->line($changeout, self::AXE)->apparatus_defect_id);
        $this->assertSame('First report', $existing->fresh()->notes);
    }

    public function test_review_keeps_the_handoff_and_alert_open_until_fleet_settles_it_with_a_note(): void
    {
        $changeout = $this->handoff([['item_key' => self::AXE, 'handling' => 'review', 'note' => 'Not sure which truck it belongs to']]);

        $line = $this->line($changeout, self::AXE);
        $this->assertSame(['review', 'needs_review'], [$line->handling, $line->custody_status]);
        $this->assertSame('pending_verification', $changeout->handoff_status);
        $this->assertSame('active', $this->alert->fresh()->status);

        $manager = $this->fleetOperationsManager();
        try {
            $this->service()->verifyLine($changeout->id, $line->id, $manager);
            $this->fail('A settled review needs a note.');
        } catch (\Illuminate\Validation\ValidationException) {
            $this->assertSame('needs_review', $line->fresh()->custody_status);
        }

        $settled = $this->service()->verifyLine($changeout->id, $line->id, $manager, 'Belongs to the reserve.');

        $this->assertSame(['verified', 'Belongs to the reserve.', $manager->id], [$line->fresh()->custody_status, $line->fresh()->verification_note, $line->fresh()->verified_by_user_id]);
        $this->assertSame('complete', $settled->handoff_status);
        $this->assertSame('completed', $this->alert->fresh()->status);
        $this->assertSame($changeout->id, $this->alert->fresh()->completed_by_changeout_id);
    }

    public function test_controlled_items_are_never_moved_whatever_was_chosen(): void
    {
        $changeout = $this->rescueHandoff([['item_key' => self::MED_BOX, 'handling' => 'transfer', 'note' => 'Handed to the officer']]);

        $line = $this->line($changeout, self::MED_BOX);
        $this->assertSame(['transfer', 'pending_verification', 'controlled', 'Handed to the officer'], [$line->handling, $line->custody_status, $line->item_class, $line->note]);
        $this->assertSame('review', $this->line($changeout, self::SEAL)->handling, 'Never marked moved by default.');
        $this->assertSame('pending_verification', $changeout->handoff_status);
        $this->assertSame('active', $this->alert->fresh()->status);
        $this->assertSame('moved', $this->line($changeout, self::AIRWAY)->custody_status);
        $this->assertSame('moved', $this->line($changeout, self::LIFEPAK)->custody_status, 'High-value items are ordinary lines.');
    }

    public function test_a_controlled_item_takes_no_count(): void
    {
        $this->rescue = $this->rescuePair();
        $alert = $this->swapAlert($this->rescue['unit']);

        $this->postChangeout($this->rescue['unit'], [
            'alert_id' => $alert->id, 'to_apparatus_id' => $this->rescue['reserve']->id,
            'exceptions' => [['item_key' => self::MED_BOX, 'quantity' => 1]],
        ])->assertUnprocessable();
    }

    public function test_rescue_officer_fields_become_controlled_lines(): void
    {
        $changeout = $this->rescueHandoff();

        foreach ([self::SEAL => 'Narcotic Seal #', self::BOOK => 'Narcotic Book'] as $key => $name) {
            $line = $this->line($changeout, $key);
            $this->assertSame([$name, 'controlled', 'pending_verification', null], [$line->item_name, $line->item_class, $line->custody_status, $line->expected_quantity]);
        }
        $this->assertFalse($changeout->items()->where('item_key', 'field:mileage')->exists());
    }

    public function test_verifying_the_last_controlled_line_completes_the_handoff_and_the_alert(): void
    {
        $changeout = $this->rescueHandoff();
        $manager = $this->fleetOperationsManager();

        foreach ([self::MED_BOX, self::SEAL] as $key) {
            $this->assertSame('pending_verification', $this->service()->verifyLine($changeout->id, $this->line($changeout, $key)->id, $manager)->handoff_status);
            $this->assertSame('active', $this->alert->fresh()->status);
        }
        $done = $this->service()->verifyLine($changeout->id, $this->line($changeout, self::BOOK)->id, $manager, 'Book and seal checked.');

        $this->assertSame('complete', $done->handoff_status);
        $this->assertSame('completed', $this->alert->fresh()->status);
        $this->assertSame('verified', $this->line($changeout, self::BOOK)->custody_status);
        $this->assertSame(
            ['submitted', 'committed', 'line_verified', 'line_verified', 'line_verified', 'reconciled', 'alert_completed'],
            ApparatusFleetEvent::query()->where('changeout_id', $changeout->id)->orderBy('id')->pluck('event')->all(),
        );
    }

    public function test_a_missing_controlled_item_files_a_defect_and_is_still_verified_separately(): void
    {
        $changeout = $this->rescueHandoff([['item_key' => self::SEAL, 'condition' => 'missing', 'note' => 'Seal broken']]);

        $line = $this->line($changeout, self::SEAL);
        $this->assertSame('pending_verification', $line->custody_status);
        $defect = ApparatusDefect::query()->sole();
        $this->assertSame([$this->rescue['unit']->id, 'missing', 'Narcotic Seal #', 'Officer checklist', null], [$defect->apparatus_id, $defect->issue_type, $defect->item, $defect->compartment, $defect->item_key]);
        $this->assertSame($defect->id, $line->apparatus_defect_id);
    }

    public function test_verifying_twice_changes_nothing(): void
    {
        $changeout = $this->rescueHandoff();
        $manager = $this->fleetOperationsManager();
        $line = $this->line($changeout, self::MED_BOX);
        $this->service()->verifyLine($changeout->id, $line->id, $manager, 'First');

        $this->service()->verifyLine($changeout->id, $line->id, $this->fleetOperationsManager(), 'Second');

        $this->assertSame('First', $line->fresh()->verification_note);
    }

    public function test_a_member_cannot_verify(): void
    {
        $changeout = $this->rescueHandoff();
        $line = $this->line($changeout, self::MED_BOX);

        $this->postJson("/api/public/changeouts/{$changeout->id}/lines/{$line->id}/verify", ['note' => 'Trust me'])->assertForbidden();

        $this->assertSame('pending_verification', $line->fresh()->custody_status);
    }

    public function test_captain_5_can_verify_rescue_equipment_but_not_engine_equipment_or_a_review(): void
    {
        $changeout = $this->rescueHandoff([['item_key' => self::AIRWAY, 'handling' => 'review']]);
        $captain = $this->actAsOtherMember('Captain');
        $line = $this->line($changeout, self::MED_BOX);

        $this->postJson("/api/public/changeouts/{$changeout->id}/lines/{$line->id}/verify", [])
            ->assertOk()->assertJsonPath('changeout.can_verify', true);
        $this->assertSame([$captain->id, 'verified'], [$line->fresh()->verified_by_user_id, $line->fresh()->custody_status]);

        $review = $this->line($changeout, self::AIRWAY);
        $this->postJson("/api/public/changeouts/{$changeout->id}/lines/{$review->id}/verify", ['note' => 'Fine'])->assertForbidden();
        $this->assertSame('needs_review', $review->fresh()->custody_status);

        $engineChangeout = $this->handoff([['item_key' => self::AXE, 'handling' => 'review']]);
        $this->assertFalse($this->service()->canVerify($captain->fresh(), $engineChangeout));
    }

    public function test_a_line_of_another_changeout_cannot_be_verified_through_this_one(): void
    {
        $changeout = $this->rescueHandoff();
        $other = $this->handoff([['item_key' => self::AXE, 'handling' => 'review']]);
        $this->actAsFleet();

        $this->postJson("/api/public/changeouts/{$changeout->id}/lines/{$this->line($other, self::AXE)->id}/verify", ['note' => 'x'])->assertNotFound();
    }

    public function test_a_waiting_change_cannot_be_verified(): void
    {
        $this->rescue = $this->rescuePair();
        $changeout = $this->changeout($this->postChangeout($this->rescue['unit'], ['to_apparatus_id' => $this->rescue['reserve']->id])->assertCreated());
        $this->assertTrue($changeout->isPendingFleet());
        $this->actAsFleet();

        $this->postJson("/api/public/changeouts/{$changeout->id}/lines/{$this->line($changeout, self::MED_BOX)->id}/verify", [])
            ->assertStatus(409)->assertJsonPath('code', 'CHANGEOUT_NOT_COMPLETED');
    }

    public function test_members_only_ever_see_the_name_of_a_controlled_item(): void
    {
        $this->rescue = $this->rescuePair();
        $context = $this->getJson("/api/public/apparatuses/{$this->rescue['unit']->id}/changeout-context")->assertOk();
        $controlled = collect($context->json('equipment.compartments'))->firstWhere('id', ApparatusChangeoutPlanner::CONTROLLED_GROUP_ID);
        $medBox = collect($controlled['items'])->firstWhere('item_key', self::MED_BOX);
        $this->assertSame(['name' => 'Med Box', 'expected_quantity' => null, 'controlled' => true], ['name' => $medBox['name'], 'expected_quantity' => $medBox['expected_quantity'], 'controlled' => $medBox['controlled']]);
        $this->assertSame('review', $medBox['default_handling']);
        $this->assertSame(['id', 'name', 'items'], array_keys($controlled));
        $patient = collect($context->json('equipment.compartments'))->firstWhere('id', 'patient');
        $this->assertNull(collect($patient['items'])->firstWhere('item_key', self::MED_BOX), 'A controlled item is not listed under its compartment.');

        $changeout = $this->changeout($this->postChangeout($this->rescue['unit'], [
            'alert_id' => $this->swapAlert($this->rescue['unit'])->id,
            'to_apparatus_id' => $this->rescue['reserve']->id,
            'exceptions' => [['item_key' => self::MED_BOX, 'handling' => 'transfer', 'note' => 'In the locker in the cab']],
        ])->assertCreated());

        $shown = collect($this->getJson("/api/public/changeouts/{$changeout->id}")->assertOk()->json('changeout.lines'))->firstWhere('item_key', self::MED_BOX);
        $this->assertSame(['id', 'item_key', 'name', 'controlled', 'custody_status'], array_keys($shown));

        $this->actAsFleet();
        $forFleet = collect($this->getJson("/api/public/changeouts/{$changeout->id}")->assertOk()->json('changeout.lines'))->firstWhere('item_key', self::MED_BOX);
        $this->assertSame(['Patient Compartment', 1, 'In the locker in the cab'], [$forFleet['compartment_name'], $forFleet['expected_quantity'], $forFleet['note']]);
    }

    public function test_a_truck_with_no_usable_list_leaves_one_line_for_fleet_to_settle(): void
    {
        $this->unit->forceFill(['daily_checkout_template' => 'pending', 'type' => 'Air Truck', 'unit_id' => 'AIR1', 'designation' => 'AIR1', 'name' => 'Air Truck 1'])->save();
        $this->reserve->forceFill(['type' => 'Air Truck'])->save();
        $alert = $this->engineAlert;

        $changeout = $this->changeout($this->moveToReserve(['alert_id' => $alert->id])->assertCreated());

        $line = $this->line($changeout, ApparatusChangeoutPlanner::NO_CHECKLIST_KEY);
        $this->assertSame('needs_review', $line->custody_status);
        $this->assertSame('pending_verification', $changeout->handoff_status);
        $this->assertSame('active', $alert->fresh()->status);
        $this->assertSame('AIR1', $this->reserve->fresh()->designation);
    }

    public function test_an_unauthorized_person_gets_no_verification(): void
    {
        $changeout = $this->rescueHandoff();

        $this->expectException(AuthorizationException::class);

        $this->service()->verifyLine($changeout->id, $this->line($changeout, self::MED_BOX)->id, $this->member);
    }
}
