<?php

declare(strict_types=1);

namespace Tests\Feature\Fleet\Changeouts;

use App\Models\Apparatus;
use App\Models\ApparatusChangeout;
use App\Models\UnitMasterVehicle;
use App\Models\UnitMasterVehicleChange;

/** A crew types the number on the truck in front of them. It is a real truck, so the work goes through and Fleet checks it. */
final class TypedTruckTest extends ChangeoutTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->useSmallChecklists();
    }

    public function test_a_number_nobody_knows_makes_new_records_and_commits(): void
    {
        $response = $this->postChangeout($this->unit, ['to_vehicle_number' => '002-16'])->assertCreated();

        $response->assertJsonPath('changeout.status', 'completed')
            ->assertJsonPath('changeout.new_truck_record', true)
            ->assertJsonPath('changeout.to_vehicle_number', '002-16');
        $truck = Apparatus::query()->findOrFail($response->json('changeout.to_apparatus_id'));
        $this->assertSame(['002-16', 'E2', 'e2', 'In Service', 'Engine'], [$truck->vehicle_number, $truck->designation, $truck->slug, $truck->getAttribute('status'), $truck->type]);
        $this->assertSame('Out of Service', $this->unit->fresh()->getAttribute('status'));
        $this->assertSame(['Reserve', 'R-V-202', 'rv-202', 'ENGINE V-202', 'reserve', 'pending'], [$this->unit->fresh()->designation, $this->unit->fresh()->unit_id, $this->unit->fresh()->slug, $this->unit->fresh()->name, $this->unit->fresh()->getRawOriginal('daily_checkout_requirement'), $this->unit->fresh()->getRawOriginal('daily_checkout_template')]);

        $master = UnitMasterVehicle::query()->where('veh_number', '002-16')->sole();
        $this->assertTrue((bool) $master->needs_fleet_review);
        $this->assertSame(UnitMasterVehicleChange::SOURCE_DAILY_CHECKOUT, $master->record_source);
        $this->assertSame($master->id, $truck->unit_master_vehicle_id);
        $this->assertStringContainsString($this->member->name, (string) UnitMasterVehicleChange::query()->where('unit_master_vehicle_id', $master->id)->where('field', 'record')->value('note'));

        $changeout = $this->changeout($response);
        $this->assertFalse($changeout->to_vehicle_listed);
        $this->assertStringContainsString('002-16', $changeout->reviewNotes()[0]);
    }

    public function test_a_number_is_a_string_so_002_16_is_not_2_16(): void
    {
        $existing = $this->reserveEngine('2-16');

        $response = $this->postChangeout($this->unit, ['to_vehicle_number' => '002-16'])->assertCreated();

        $this->assertNotSame($existing->id, $response->json('changeout.to_apparatus_id'));
        $this->assertSame('RE-2-16', $existing->fresh()->designation);
    }

    public function test_a_master_record_with_no_apparatus_is_used_and_no_second_master_is_made(): void
    {
        $master = UnitMasterVehicle::query()->create(['veh_number' => '17600', 'make' => 'Ford', 'model' => 'F550', 'year' => '2021', 'serial_number' => 'VIN-17600']);

        $response = $this->postChangeout($this->unit, ['to_vehicle_number' => '17600'])->assertCreated()->assertJsonPath('changeout.new_truck_record', true);

        $truck = Apparatus::query()->findOrFail($response->json('changeout.to_apparatus_id'));
        $this->assertSame($master->id, $truck->unit_master_vehicle_id);
        $this->assertSame(['Ford', 'F550', 2021, 'VIN-17600'], [$truck->make, $truck->model, (int) $truck->year, $truck->getAttribute('vin')]);
        $this->assertSame(1, UnitMasterVehicle::query()->where('veh_number', '17600')->count());
        $this->assertStringContainsString('Master Vehicle Inventory', $this->changeout($response)->reviewNotes()[0]);
    }

    public function test_two_master_records_with_that_number_leave_the_truck_unlinked_for_fleet(): void
    {
        UnitMasterVehicle::query()->create(['veh_number' => '17601']);
        UnitMasterVehicle::query()->create(['veh_number' => '17601']);

        $response = $this->postChangeout($this->unit, ['to_vehicle_number' => '17601'])->assertCreated()->assertJsonPath('changeout.status', 'completed');

        $truck = Apparatus::query()->findOrFail($response->json('changeout.to_apparatus_id'));
        $this->assertNull($truck->unit_master_vehicle_id);
        $this->assertSame(Apparatus::MASTER_LINK_NEEDS_REVIEW, $truck->master_link_status);
        $this->assertSame(2, UnitMasterVehicle::query()->where('veh_number', '17601')->count());
        $this->assertStringContainsString('17601', $this->changeout($response)->reviewNotes()[0]);
    }

    public function test_a_typed_number_on_the_list_uses_that_truck(): void
    {
        $this->postChangeout($this->unit, ['to_vehicle_number' => ' v-909 '])->assertCreated()
            ->assertJsonPath('changeout.to_apparatus_id', $this->reserve->id)
            ->assertJsonPath('changeout.new_truck_record', false);
    }

    public function test_two_records_sharing_a_number_use_the_first_and_flag_it(): void
    {
        $first = $this->reserveEngine('17700');
        $second = $this->reserveEngine('17700', ['vin' => 'VIN-17700-B', 'unit_id' => 'RE-17700-B', 'designation' => 'RE-17700-B']);

        $response = $this->postChangeout($this->unit, ['to_vehicle_number' => '17700'])->assertCreated();

        $response->assertJsonPath('changeout.to_apparatus_id', $first->id);
        $this->assertSame('RE-17700-B', $second->fresh()->designation);
        $this->assertStringContainsString('Two records share truck number 17700', implode(' ', $this->changeout($response)->reviewNotes()));
    }

    public function test_the_same_new_number_a_second_time_uses_the_row_the_first_made(): void
    {
        $first = $this->postChangeout($this->unit, ['to_vehicle_number' => '17800'])->assertCreated();
        $this->assertSame(1, Apparatus::query()->where('vehicle_number', '17800')->count());

        $this->postChangeout($this->frontline, ['to_vehicle_number' => '17800'])->assertCreated()
            ->assertJsonPath('changeout.to_apparatus_id', $first->json('changeout.to_apparatus_id'))
            ->assertJsonPath('changeout.displaced_unit', 'E2');

        $this->assertSame(1, Apparatus::query()->where('vehicle_number', '17800')->count());
        $this->assertSame(2, ApparatusChangeout::query()->count());
    }
}
