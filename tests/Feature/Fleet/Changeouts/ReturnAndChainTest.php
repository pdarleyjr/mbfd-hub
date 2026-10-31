<?php

declare(strict_types=1);

namespace Tests\Feature\Fleet\Changeouts;

use App\Models\Apparatus;
use App\Models\ApparatusChangeout;

/**
 * Section 5: going back to the truck this unit last left. The earlier record is never edited, and each return points
 * only at the changeout it reverses.
 */
final class ReturnAndChainTest extends ChangeoutTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->useSmallChecklists();
    }

    /** @param array<string, mixed> $overrides */
    private function scheduledMove(Apparatus $from, Apparatus $to, array $overrides = []): ApparatusChangeout
    {
        $alert = $this->swapAlert($from->fresh());

        return $this->changeout($this->postChangeout($from, ['alert_id' => $alert->id, 'to_apparatus_id' => $to->id, ...$overrides])->assertCreated());
    }

    public function test_a_return_commits_directly_and_points_at_what_it_reverses(): void
    {
        $out = $this->scheduledMove($this->unit, $this->reserve);

        $back = $this->postChangeout($this->reserve, ['to_apparatus_id' => $this->unit->id]);

        $back->assertCreated()->assertJsonPath('changeout.status', 'completed')->assertJsonPath('changeout.return_of_changeout_id', $out->id);
        $this->assertSame('E2', $this->unit->fresh()->designation);
        $this->assertSame('RE1', $this->reserve->fresh()->designation);
        $this->assertSame('required', $this->unit->fresh()->getRawOriginal('daily_checkout_requirement'));
        $this->assertSame('reserve', $this->reserve->fresh()->getRawOriginal('daily_checkout_requirement'));
    }

    public function test_a_return_is_held_for_fleet_until_fleet_releases_the_truck(): void
    {
        $this->scheduledMove($this->unit, $this->reserve);
        $this->unit->forceFill(['status' => 'Maintenance'])->save();

        $this->postChangeout($this->reserve, ['to_apparatus_id' => $this->unit->id])
            ->assertCreated()->assertJsonPath('changeout.status', 'pending_fleet');

        $this->assertSame('E2', $this->reserve->fresh()->designation);
    }

    public function test_a_number_derived_identity_is_restored_on_the_way_back(): void
    {
        ['unit' => $unit, 'reserve' => $reserve] = $this->rescuePair();
        $this->scheduledMove($unit, $reserve);
        $this->assertSame(['R-17503', 'r17503'], [$unit->fresh()->unit_id, $unit->fresh()->slug]);

        $this->postChangeout($reserve, ['to_apparatus_id' => $unit->id])->assertCreated()->assertJsonPath('changeout.status', 'completed');

        $this->assertSame(['R 44', 'R 44', 'Rescue 44', 'r-44'], [$unit->fresh()->unit_id, $unit->fresh()->designation, $unit->fresh()->name, $unit->fresh()->slug]);
        $this->assertSame(['R-1033', 'RESCUE 1033', 'r1033'], [$reserve->fresh()->unit_id, $reserve->fresh()->name, $reserve->fresh()->slug]);
    }

    public function test_a_designated_reserve_trades_and_comes_back(): void
    {
        $reserve = $this->reserveEngine('1011', ['unit_id' => 'E 11', 'designation' => 'E 11', 'name' => 'Engine 11', 'slug' => 'e-11', 'status' => 'Available']);

        $this->scheduledMove($this->unit, $reserve);

        $this->assertSame(['E2', 'e2', 'In Service'], [$reserve->fresh()->designation, $reserve->fresh()->slug, $reserve->fresh()->getAttribute('status')]);
        $this->assertSame(['E 11', 'E 11', 'Engine 11', 'e-11', 'Available'], [$this->unit->fresh()->designation, $this->unit->fresh()->unit_id, $this->unit->fresh()->name, $this->unit->fresh()->slug, $this->unit->fresh()->getAttribute('status')]);

        $this->postChangeout($reserve, ['to_apparatus_id' => $this->unit->id])->assertCreated()->assertJsonPath('changeout.status', 'completed');

        $this->assertSame(['E 11', 'e-11', 'Available'], [$reserve->fresh()->designation, $reserve->fresh()->slug, $reserve->fresh()->getAttribute('status')]);
        $this->assertSame(['E2', 'e2', 'In Service'], [$this->unit->fresh()->designation, $this->unit->fresh()->slug, $this->unit->fresh()->getAttribute('status')]);
        $this->assertSame('1011', $reserve->fresh()->vehicle_number);
        $this->assertSame('V-202', $this->unit->fresh()->vehicle_number);
    }

    public function test_return_defaults_follow_what_happened_last_time(): void
    {
        $out = $this->scheduledMove($this->unit, $this->reserve, ['exceptions' => [
            ['item_key' => self::MAP_BOOK, 'handling' => 'leave', 'note' => 'Spare stays with the truck'],
            ['item_key' => self::RADIO, 'handling' => 'transfer', 'quantity' => 1],
            ['item_key' => self::AXE, 'handling' => 'review', 'note' => 'Could not find it'],
        ]]);

        // The unit goes back with the review still open on the first record.
        $back = $this->changeout($this->postChangeout($this->reserve, ['to_apparatus_id' => $this->unit->id])->assertCreated());

        $this->assertSame($out->id, $back->return_of_changeout_id);
        $this->assertSame(['transfer', 'moved', null], [$this->line($back, self::HALLIGAN)->handling, $this->line($back, self::HALLIGAN)->custody_status, $this->line($back, self::HALLIGAN)->quantity_moved]);
        $this->assertSame(['transfer', 'moved', 1], [$this->line($back, self::RADIO)->handling, $this->line($back, self::RADIO)->custody_status, $this->line($back, self::RADIO)->quantity_moved], 'What moved goes back, no more than that.');
        $this->assertSame(['leave', 'left_behind', 'Spare stays with the truck'], [$this->line($back, self::MAP_BOOK)->handling, $this->line($back, self::MAP_BOOK)->custody_status, $this->line($back, self::MAP_BOOK)->note]);
        $this->assertSame(['review', 'needs_review', 'Could not find it'], [$this->line($back, self::AXE)->handling, $this->line($back, self::AXE)->custody_status, $this->line($back, self::AXE)->note]);
        $this->assertSame('pending_verification', $back->handoff_status);

        // The earlier record is never edited.
        $this->assertSame('needs_review', $this->line($out, self::AXE)->custody_status);
        $this->assertNull($out->fresh()->return_of_changeout_id);
    }

    public function test_a_member_can_override_a_return_default(): void
    {
        $this->scheduledMove($this->unit, $this->reserve, ['exceptions' => [['item_key' => self::MAP_BOOK, 'handling' => 'leave', 'note' => 'Stays']]]);

        $back = $this->changeout($this->postChangeout($this->reserve, ['to_apparatus_id' => $this->unit->id, 'exceptions' => [['item_key' => self::MAP_BOOK, 'handling' => 'transfer']]])->assertCreated());

        $this->assertSame('moved', $this->line($back, self::MAP_BOOK)->custody_status);
    }

    public function test_controlled_lines_start_as_review_on_a_return(): void
    {
        ['unit' => $unit, 'reserve' => $reserve] = $this->rescuePair();
        $out = $this->scheduledMove($unit, $reserve);
        foreach ($out->items()->where('custody_status', 'pending_verification')->get() as $line) {
            $this->service()->verifyLine($out->id, $line->id, $this->fleetOperationsManager());
        }

        $back = $this->changeout($this->postChangeout($reserve, ['to_apparatus_id' => $unit->id])->assertCreated());

        $medBox = $this->line($back, 'patient-item-2');
        $this->assertSame(['review', 'pending_verification'], [$medBox->handling, $medBox->custody_status]);
    }

    public function test_the_context_offers_the_return_and_what_it_would_do(): void
    {
        $out = $this->scheduledMove($this->unit, $this->reserve, ['exceptions' => [
            ['item_key' => self::MAP_BOOK, 'handling' => 'leave', 'note' => 'Stays'],
            ['item_key' => self::AXE, 'handling' => 'review'],
        ]]);

        $context = $this->getJson("/api/public/apparatuses/{$this->reserve->id}/changeout-context")->assertOk();

        $context->assertJsonPath('mode', 'start')
            ->assertJsonPath('previous_changeout.id', $out->id)
            ->assertJsonPath('previous_changeout.from_vehicle_number', 'V-202')
            ->assertJsonPath('previous_changeout.to_vehicle_number', 'V-909')
            ->assertJsonPath('previous_changeout.moved', 2)
            ->assertJsonPath('previous_changeout.left_behind.0.name', 'Map book')
            ->assertJsonPath('previous_changeout.left_behind.0.note', 'Stays')
            ->assertJsonPath('previous_changeout.open.0.name', 'Axe')
            ->assertJsonPath('previous_changeout.open.0.status', 'needs_review')
            ->assertJsonPath('previous_changeout.submitted_by_name', $this->member->name);
        $back = collect($context->json('candidates'))->firstWhere('apparatus_id', $this->unit->id);
        $this->assertSame([true, true], [$back['is_return'], $back['direct']]);

        $items = collect($context->json('equipment.compartments'))->flatMap(static fn (array $compartment): array => $compartment['items'])->keyBy('item_key');
        $this->assertSame('transfer', $items[self::MAP_BOOK]['default_handling']);
        $this->assertSame('leave', $items[self::MAP_BOOK]['previous']['return_handling']);
        $this->assertSame('review', $items[self::AXE]['previous']['return_handling']);
        $this->assertSame('transfer', $items[self::HALLIGAN]['previous']['return_handling']);
    }

    public function test_three_swaps_in_a_row_each_see_the_right_previous_changeout(): void
    {
        $third = $this->reserveEngine('V-930');

        $first = $this->scheduledMove($this->unit, $this->reserve);
        $this->getJson("/api/public/apparatuses/{$this->reserve->id}/changeout-context")->assertOk()
            ->assertJsonPath('previous_changeout.id', $first->id)->assertJsonPath('previous_changeout.to_vehicle_number', 'V-909');

        $second = $this->scheduledMove($this->reserve, $third);
        $this->assertSame('e2', $second->unit_slug);
        $this->assertNull($second->return_of_changeout_id);
        $this->getJson("/api/public/apparatuses/{$third->id}/changeout-context")->assertOk()
            ->assertJsonPath('previous_changeout.id', $second->id)->assertJsonPath('previous_changeout.from_vehicle_number', 'V-909');

        // Only the truck it last left is a return: V-202 is not, V-909 is.
        $context = $this->getJson("/api/public/apparatuses/{$third->id}/changeout-context")->assertOk();
        $returns = collect($context->json('candidates'))->where('is_return', true)->pluck('apparatus_id')->all();
        $this->assertSame([$this->reserve->id], $returns);

        $back = $this->changeout($this->postChangeout($third, ['to_apparatus_id' => $this->reserve->id])->assertCreated());
        $this->assertSame([ApparatusChangeout::STATUS_COMPLETED, $second->id], [$back->status, $back->return_of_changeout_id]);
        $this->getJson("/api/public/apparatuses/{$this->reserve->id}/changeout-context")->assertOk()
            ->assertJsonPath('previous_changeout.id', $back->id)->assertJsonPath('previous_changeout.from_vehicle_number', 'V-930');

        $this->assertSame('E2', $this->reserve->fresh()->designation);
        $this->assertSame(['V-202', 'V-909', 'V-930'], [$this->unit->fresh()->vehicle_number, $this->reserve->fresh()->vehicle_number, $third->fresh()->vehicle_number]);
    }

    public function test_another_unit_on_the_old_truck_does_not_make_it_a_return(): void
    {
        $this->scheduledMove($this->unit, $this->reserve);
        $other = $this->reserveEngine('V-950');

        // RE1 now rides on V-202. It has no changeout of its own, so V-202 is no return for it.
        $this->postChangeout($this->unit, ['to_apparatus_id' => $other->id])->assertCreated()->assertJsonPath('changeout.status', 'pending_fleet')->assertJsonPath('changeout.return_of_changeout_id', null);
    }
}
