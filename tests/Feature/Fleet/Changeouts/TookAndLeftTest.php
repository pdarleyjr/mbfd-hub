<?php

declare(strict_types=1);

namespace Tests\Feature\Fleet\Changeouts;

use App\Models\ApparatusChangeout;
use App\Models\ApparatusDefect;

/** The crew says what they took and what they left. Nothing is preset, nothing is controlled, and nothing they send is refused. */
final class TookAndLeftTest extends ChangeoutTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->useSmallChecklists();
    }

    public function test_every_line_is_took_unless_the_crew_says_otherwise(): void
    {
        $changeout = $this->changeout($this->moveToReserve()->assertCreated());

        $this->assertSame(self::ENGINE_LINES, $changeout->items()->count());
        $this->assertSame(self::ENGINE_LINES, $changeout->items()->where('handling', 'transfer')->where('custody_status', 'moved')->count());
    }

    public function test_took_and_left_are_recorded_with_notes_and_nothing_else(): void
    {
        $changeout = $this->changeout($this->moveToReserve(['exceptions' => [
            ['item_key' => self::MAP_BOOK, 'handling' => 'leave', 'note' => 'Spare stays with the truck'],
            ['item_key' => self::AXE, 'handling' => 'transfer', 'note' => 'Handle is cracked'],
        ]])->assertCreated());

        $this->assertSame(['leave', 'left_behind', 'Spare stays with the truck'], [$this->line($changeout, self::MAP_BOOK)->handling, $this->line($changeout, self::MAP_BOOK)->custody_status, $this->line($changeout, self::MAP_BOOK)->note]);
        $this->assertSame(['transfer', 'moved', 'Handle is cracked'], [$this->line($changeout, self::AXE)->handling, $this->line($changeout, self::AXE)->custody_status, $this->line($changeout, self::AXE)->note]);
        $this->assertSame(0, ApparatusDefect::query()->count(), 'A changeout files no defects.');
    }

    public function test_the_summary_and_show_read_plainly(): void
    {
        $response = $this->moveToReserve(['exceptions' => [['item_key' => self::MAP_BOOK, 'handling' => 'leave', 'note' => 'Stays']]])->assertCreated();
        $response->assertJsonPath('changeout.moved', self::ENGINE_LINES - 1)->assertJsonPath('changeout.left_behind', 1);

        $line = collect($this->getJson('/api/public/changeouts/'.$response->json('changeout.id'))->assertOk()->json('changeout.lines'))->firstWhere('item_key', self::MAP_BOOK);

        $this->assertSame(['id', 'item_key', 'name', 'compartment', 'handling', 'custody_status', 'note'], array_keys($line));
        $this->assertSame(['Map book', 'Front Cab', 'leave', 'left_behind', 'Stays'], [$line['name'], $line['compartment'], $line['handling'], $line['custody_status'], $line['note']]);
    }

    public function test_an_item_the_list_no_longer_has_is_ignored_and_the_work_goes_through(): void
    {
        $changeout = $this->changeout($this->moveToReserve(['exceptions' => [
            ['item_key' => 'gone-item', 'handling' => 'leave'],
            ['item_key' => self::AXE, 'handling' => 'leave'],
        ]])->assertCreated());

        $this->assertSame(self::ENGINE_LINES, $changeout->items()->count());
        $this->assertSame('left_behind', $this->line($changeout, self::AXE)->custody_status);
    }

    public function test_a_changed_list_never_stops_the_crew(): void
    {
        $this->moveToReserve(['checklist_version' => str_repeat('0', 64), 'exceptions' => [['item_key' => self::AXE, 'handling' => 'leave']]])
            ->assertCreated()->assertJsonPath('changeout.left_behind', 1);
    }

    public function test_the_same_item_twice_keeps_the_last_choice(): void
    {
        $changeout = $this->changeout($this->moveToReserve(['exceptions' => [
            ['item_key' => self::AXE, 'handling' => 'leave'],
            ['item_key' => self::AXE, 'handling' => 'transfer'],
        ]])->assertCreated());

        $this->assertSame('moved', $this->line($changeout, self::AXE)->custody_status);
    }

    public function test_old_fields_are_accepted_and_ignored(): void
    {
        $changeout = $this->changeout($this->moveToReserve(['exceptions' => [
            ['item_key' => self::RADIO, 'handling' => 'transfer', 'quantity' => 1, 'condition' => 'missing'],
        ]])->assertCreated());

        $this->assertSame('moved', $this->line($changeout, self::RADIO)->custody_status);
        $this->assertSame(0, ApparatusDefect::query()->count());
    }

    public function test_nothing_on_a_rescue_is_controlled(): void
    {
        ['unit' => $unit, 'reserve' => $reserve] = $this->rescuePair();

        $context = $this->getJson("/api/public/apparatuses/{$unit->id}/changeout-context")->assertOk();
        $this->assertSame(['Patient Compartment'], array_column($context->json('equipment.compartments'), 'name'));

        $changeout = $this->changeout($this->postChangeout($unit, ['to_apparatus_id' => $reserve->id, 'exceptions' => [
            ['item_key' => 'patient-item-2', 'handling' => 'leave', 'note' => 'In the locker'],
        ]])->assertCreated());

        $this->assertSame(['leave', 'left_behind', 'In the locker'], [$this->line($changeout, 'patient-item-2')->handling, $this->line($changeout, 'patient-item-2')->custody_status, $this->line($changeout, 'patient-item-2')->note]);
        $this->assertSame(ApparatusChangeout::HANDOFF_COMPLETE, $changeout->handoff_status);
    }

    public function test_a_truck_with_no_usable_list_records_no_lines_and_keeps_the_crews_note(): void
    {
        $this->unit->forceFill(['daily_checkout_template' => 'pending', 'type' => 'Utility', 'designation' => 'U1', 'unit_id' => 'U1', 'name' => 'Utility 1'])->save();

        $context = $this->getJson("/api/public/apparatuses/{$this->unit->id}/changeout-context")->assertOk();
        $context->assertJsonPath('equipment.usable', false)->assertJsonPath('equipment.compartments', []);

        $changeout = $this->changeout($this->moveToReserve(['note' => 'Took everything on the rig'])->assertCreated());

        $this->assertSame(0, $changeout->items()->count());
        $this->assertSame('Took everything on the rig', $changeout->note);
        $this->assertSame(ApparatusChangeout::STATUS_COMPLETED, $changeout->status);
    }
}
