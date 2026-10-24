<?php

declare(strict_types=1);

namespace Tests\Feature\Fleet\VehicleIdentification;

use App\Enums\DailyCheckoutChecklistTemplate;
use App\Models\Apparatus;
use App\Models\ApparatusIdentificationQuestion;
use App\Models\UnitMasterVehicleChange;
use App\Services\Fleet\UnitMasterVehicleChangeRecorder;

final class VehicleIdentificationPlannerTest extends IdentificationTestCase
{
    public function test_a_linked_unit_is_asked_only_what_its_vehicle_record_lacks(): void
    {
        // E2's record, as the sync created it, has a number and VIN only.
        $this->assertSame(['plate', 'year', 'make', 'model'], $this->openFields($this->unit));

        $this->completeMasterExcept($this->unit, ['tag_number']);
        $this->assertSame(['plate'], $this->openFields($this->unit));

        $this->completeMasterExcept($this->unit);
        $this->assertSame([], $this->openFields($this->unit));
        $block = $this->block($this->unit);
        $this->assertFalse($block['required']);
        $this->assertSame([], $block['questions']);
    }

    public function test_placeholder_values_on_the_record_count_as_blank(): void
    {
        $this->completeMasterExcept($this->unit);
        foreach (['N/A', 'na', 'None', '-'] as $placeholder) {
            $this->unitMaster()->forceFill(['tag_number' => $placeholder])->save();
            $this->assertSame(['plate'], $this->openFields($this->unit), "'{$placeholder}' says nothing");
        }
    }

    public function test_an_unlinked_vehicle_is_asked_every_field_with_vehicle_wording(): void
    {
        $unit = $this->unlink($this->unit);

        $block = $this->block($unit);

        $this->assertTrue($block['required']);
        $this->assertSame('vehicle', $block['kind']);
        $this->assertSame(['vehicle_number', 'vin', 'plate', 'year', 'make', 'model'], array_column($block['questions'], 'field'));
        $byField = array_column($block['questions'], null, 'field');
        $this->assertSame('License plate', $byField['plate']['label']);
        $this->assertStringStartsWith('VIN (17 characters', $byField['vin']['label']);
        $this->assertSame('text', $byField['plate']['input']);
        $this->assertFalse($byField['plate']['confirm']);
        $this->assertNull($byField['plate']['current_value']);
        $this->assertSame(['not_accessible_now', 'location_unknown', 'not_present', 'not_applicable', 'other'], array_column($block['reasons'], 'code'));
        $this->assertTrue(collect($block['reasons'])->firstWhere('code', 'other')['needs_note']);
        $this->assertFalse(collect($block['reasons'])->firstWhere('code', 'not_present')['needs_note']);
    }

    public function test_a_boat_is_asked_with_boat_wording_and_no_meters(): void
    {
        $boat = Apparatus::query()->create([
            'unit_id' => 'FB6', 'name' => 'Fire Boat 6', 'designation' => 'Fire Boat 6', 'type' => 'Fire Boat', 'slug' => 'fb6',
            'daily_checkout_requirement' => 'required', 'daily_checkout_template' => DailyCheckoutChecklistTemplate::FireBoat6->value,
        ]);

        $block = $this->block($boat);

        $this->assertSame('boat', $block['kind']);
        $this->assertSame(['vehicle_number', 'vin', 'plate', 'year', 'make', 'model'], array_column($block['questions'], 'field'));
        $byField = array_column($block['questions'], null, 'field');
        $this->assertSame('Fleet vessel number', $byField['vehicle_number']['label']);
        $this->assertStringStartsWith('Hull ID (HIN', $byField['vin']['label']);
        $this->assertStringStartsWith('Registration number (FL number', $byField['plate']['label']);
        $this->assertSame('Builder / make', $byField['make']['label']);
        $this->assertNotContains('odometer', array_column($block['questions'], 'field'));
        $this->assertNotContains('engine_hours', array_column($block['questions'], 'field'));
    }

    public function test_a_boat_is_recognised_by_its_type_even_without_the_fire_boat_template(): void
    {
        $boat = $this->legacyApparatus('RB1', null, ['type' => 'Rescue Boat', 'daily_checkout_template' => 'engine']);

        $this->assertSame('boat', $this->block($boat)['kind']);
    }

    public function test_units_that_are_never_asked(): void
    {
        $test = $this->legacyApparatus('ZZ-VERIFY', null, ['unit_id' => 'ZZ-VERIFY', 'daily_checkout_requirement' => 'inactive']);
        $zzRequired = $this->legacyApparatus('ZZ 9', null, ['unit_id' => 'ZZ 9']);
        $inactive = $this->legacyApparatus('E 77', null, ['daily_checkout_requirement' => 'inactive']);
        $reserve = $this->legacyApparatus('RE 5', null, ['daily_checkout_requirement' => 'reserve']);
        $administrative = $this->legacyApparatus('A 2', null, ['daily_checkout_requirement' => 'administrative']);
        $unknown = $this->legacyApparatus('U 1', null, ['daily_checkout_requirement' => 'unknown']);

        foreach ([$test, $zzRequired, $inactive, $reserve, $administrative, $unknown] as $unit) {
            $this->assertSame([], $this->openFields($unit), $unit->designation.' is not asked');
            $this->assertFalse($this->block($unit)['required']);
        }
    }

    public function test_answered_and_closed_questions_are_not_asked_again_but_temporary_ones_are(): void
    {
        $unit = $this->unlink($this->unit);
        $this->question($unit, 'vin', 'answered', value: self::VIN_A);
        $this->question($unit, 'plate', 'not_applicable', reason: 'not_present');
        $this->question($unit, 'year', 'open', reason: 'not_accessible_now');

        $fields = $this->openFields($unit);

        $this->assertNotContains('vin', $fields);
        $this->assertNotContains('plate', $fields);
        $this->assertContains('year', $fields, 'a temporary reason is asked again next checkout');
        $this->assertContains('vehicle_number', $fields);
    }

    public function test_captain_5_is_asked_nothing_unless_its_record_lacks_something_or_a_plate_change_is_pending(): void
    {
        $captain = $this->captain5();
        $this->assertSame([], $this->openFields($captain), 'a complete record has no questions');

        $this->proposePlate($captain, 'XK4560');
        $questions = array_column($this->block($captain)['questions'], null, 'field');

        $this->assertSame(['plate'], array_keys($questions));
        $this->assertTrue($questions['plate']['confirm']);
        $this->assertSame('XD1904', $questions['plate']['current_value'], 'the unit\'s own recorded plate, shown only to be confirmed');
        $this->assertStringNotContainsString('XK4560', json_encode($this->block($captain)), 'the proposed value never biases the crew');

        $this->question($captain, 'plate', 'answered', value: 'XD1904');
        $this->assertSame([], $this->openFields($captain), 'confirmed once, never again');
    }

    public function test_a_pending_plate_change_does_not_make_an_ordinary_unit_ask_for_confirmation(): void
    {
        $this->completeMasterExcept($this->unit);
        $this->proposePlate($this->unit, 'XK4560');

        $this->assertSame([], $this->openFields($this->unit));
    }

    public function test_the_questions_version_changes_only_when_the_questions_do(): void
    {
        $first = $this->block($this->unit)['questions_version'];
        $this->assertSame($first, $this->block($this->unit)['questions_version']);
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $first);

        $this->completeMasterExcept($this->unit, ['tag_number']);

        $this->assertNotSame($first, $this->block($this->unit)['questions_version']);
    }

    public function test_the_planner_never_returns_another_vehicles_plate_or_vin(): void
    {
        $other = $this->frontline->fresh()->masterVehicle;
        $other->forceFill(['tag_number' => 'OTHER99', 'serial_number' => self::VIN_B])->save();
        $this->unlink($this->unit);

        $json = json_encode($this->block($this->unit->fresh()));

        $this->assertStringNotContainsString('OTHER99', $json);
        $this->assertStringNotContainsString(self::VIN_B, $json);
    }

    private function captain5(): Apparatus
    {
        $unit = Apparatus::query()->create([
            'unit_id' => 'Captain 5', 'name' => 'Captain 5', 'designation' => 'Captain 5', 'type' => 'SUV',
            'vehicle_number' => '22530', 'status' => 'In Service',
            'daily_checkout_requirement' => 'administrative', 'daily_checkout_template' => 'pending',
        ]);
        $master = $unit->fresh()->masterVehicle;
        $master->forceFill([
            'year' => '2022', 'make' => 'Ford', 'model' => 'F-250', 'tag_number' => 'XD1904', 'serial_number' => 'TESTVIN000000530',
        ])->save();

        return $unit->fresh();
    }

    private function proposePlate(Apparatus $unit, string $plate): void
    {
        app(UnitMasterVehicleChangeRecorder::class)->propose($unit->fresh()->masterVehicle, 'tag_number', $plate, UnitMasterVehicleChange::SOURCE_FY27);
    }

    private function question(Apparatus $unit, string $field, string $state, ?string $value = null, ?string $reason = null): void
    {
        ApparatusIdentificationQuestion::query()->create([
            'apparatus_id' => $unit->getKey(), 'field' => $field, 'state' => $state,
            'answered_value' => $value, 'last_reason' => $reason,
        ]);
    }
}
