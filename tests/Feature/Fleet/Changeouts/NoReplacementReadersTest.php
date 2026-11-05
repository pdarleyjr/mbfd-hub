<?php

declare(strict_types=1);

namespace Tests\Feature\Fleet\Changeouts;

use App\Filament\Resources\ApparatusChangeoutResource\Pages\ListApparatusChangeouts;
use App\Filament\Resources\ApparatusChangeoutResource\Pages\ViewApparatusChangeout;
use App\Models\Apparatus;
use App\Models\ApparatusChangeout;
use App\Services\Captain5\Captain5RescueTrucks;
use App\Services\Fleet\ApparatusChangeoutPlanner;
use App\Services\Fleet\ApparatusChangeoutService;
use App\Services\Fleet\CheckoutVehicleReport;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * A no-replacement record (an admin took a unit's only truck out of service) names the same truck on both sides and
 * moves nothing. Readers that ask "where did this unit go" or "what did it leave" must skip it, and readers that show
 * it to people must not say "truck X to truck X".
 */
final class NoReplacementReadersTest extends ChangeoutTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->useSmallChecklists();
    }

    private function withoutReplacement(Apparatus $truck, string $reason = 'Both spare engines are at the shop.'): ApparatusChangeout
    {
        return app(ApparatusChangeoutService::class)->recordWithoutReplacement($truck->fresh(), $this->fleetOperationsManager(), $reason);
    }

    private function planner(): ApparatusChangeoutPlanner
    {
        return app(ApparatusChangeoutPlanner::class);
    }

    public function test_the_scope_keeps_moves_and_drops_no_replacement_records(): void
    {
        $move = $this->changeout($this->moveToReserve()->assertCreated());
        $kept = $this->withoutReplacement($this->frontline);

        self::assertTrue($kept->isWithoutReplacement());
        self::assertSame([$move->id], ApparatusChangeout::query()->realMoves()->pluck('id')->all());
        self::assertSame([$move->id, $kept->id], ApparatusChangeout::query()->orderBy('id')->pluck('id')->all(), 'Nothing else is filtered.');
    }

    public function test_a_no_replacement_record_is_not_a_move_off_the_truck(): void
    {
        $spare = $this->reserveEngine('1011', ['unit_id' => 'E 11', 'designation' => 'E 11', 'name' => 'Engine 11', 'slug' => 'e-11', 'status' => 'In Service']);
        $this->withoutReplacement($spare);

        self::assertNull($this->planner()->changedOutFrom($spare->fresh()));
        // A checkout on this non-required truck naming another number is an ordinary swap report, not "after E 11 had moved to truck 1011".
        $assessment = app(CheckoutVehicleReport::class)->assess($spare->fresh(), ['vehicle_number' => 'V-555']);
        self::assertSame(CheckoutVehicleReport::SWAP, $assessment['outcome']);
    }

    public function test_the_last_real_move_stays_the_previous_changeout_and_a_return_still_points_at_it(): void
    {
        $out = $this->changeout($this->moveToReserve(['exceptions' => [
            ['item_key' => self::MAP_BOOK, 'handling' => 'leave', 'note' => 'Spare stays with the truck'],
        ]])->assertCreated());
        $kept = $this->withoutReplacement($this->reserve);

        $previous = $this->planner()->previousChangeout('e2');
        self::assertSame($out->id, $previous?->id);
        $context = $this->planner()->context($this->reserve->fresh());
        self::assertSame($out->id, $context['previous_changeout']['id']);
        self::assertSame([['name' => 'Map book', 'note' => 'Spare stays with the truck']], $context['previous_changeout']['left_behind']);
        $back = collect($context['candidates'])->firstWhere('apparatus_id', $this->unit->id);
        self::assertTrue($back['is_return'] ?? false, 'Going back to V-202 is still a return.');

        $return = $this->changeout($this->postChangeout($this->reserve, ['to_apparatus_id' => $this->unit->id])->assertCreated());
        self::assertSame($out->id, $return->return_of_changeout_id);
        self::assertNotSame($kept->id, $return->return_of_changeout_id);
    }

    public function test_the_checklist_handoff_notice_still_comes_from_the_real_move(): void
    {
        $this->moveToReserve(['exceptions' => [
            ['item_key' => self::MAP_BOOK, 'handling' => 'leave', 'note' => 'Spare stays'],
        ]])->assertCreated();
        $this->withoutReplacement($this->reserve);

        $this->getJson("/api/public/apparatuses/{$this->reserve->id}/checklist")->assertOk()
            ->assertJsonPath('changeout_handoff.from_vehicle_number', 'V-202')
            ->assertJsonPath('changeout_handoff.left_behind.0.note', 'Spare stays');
    }

    public function test_captain5_truck_changes_say_the_unit_was_kept(): void
    {
        ['unit' => $unit, 'reserve' => $reserve] = $this->rescuePair();
        $this->postChangeout($unit, ['to_apparatus_id' => $reserve->id])->assertCreated();
        $this->withoutReplacement($reserve, 'No rescue truck is free tonight.');

        $changes = app(Captain5RescueTrucks::class)->payload()['rescue_truck_changes'];

        self::assertCount(2, $changes);
        self::assertTrue($changes[0]['without_replacement']);
        self::assertSame(['1033', '1033'], [$changes[0]['from_vehicle_number'], $changes[0]['to_vehicle_number']]);
        self::assertFalse($changes[1]['without_replacement']);
        self::assertSame(['17503', '1033'], [$changes[1]['from_vehicle_number'], $changes[1]['to_vehicle_number']]);
    }

    public function test_the_member_receipt_says_there_was_no_replacement(): void
    {
        $kept = $this->withoutReplacement($this->unit);
        $this->actAsFleet();

        $this->getJson("/api/public/changeouts/{$kept->id}")->assertOk()
            ->assertJsonPath('changeout.without_replacement', true)
            ->assertJsonPath('changeout.message', 'E2 has no truck in service. Truck V-202 is marked Out of Service.');
    }

    public function test_the_admin_list_and_record_read_kept_on_truck(): void
    {
        $move = $this->changeout($this->moveToReserve()->assertCreated());
        $kept = $this->withoutReplacement($this->frontline);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAsCanonicalUser($this->superAdmin());

        self::assertSame('E3 kept on truck V-303 (no replacement available)', $kept->trucksPhrase());
        self::assertSame('Truck V-202 to truck V-909', $move->trucksPhrase());

        Livewire::test(ListApparatusChangeouts::class)
            ->call('loadTable')
            ->assertSee('E3 kept on truck V-303 (no replacement available).')
            ->assertSee('Truck V-202 to truck V-909.')
            ->assertDontSee('Truck V-303 to truck V-303');

        Livewire::test(ViewApparatusChangeout::class, ['record' => $kept->getKey()])
            ->assertSee('None available (unit kept on this truck)');
    }
}
