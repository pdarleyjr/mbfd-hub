<?php

declare(strict_types=1);

namespace Tests\Feature\Captain5;

use App\Models\Apparatus;
use App\Models\ApparatusChangeout;
use App\Models\ApparatusChangeoutItem;
use App\Models\ApparatusInspection;
use App\Models\ApparatusVehicleSwapRequest;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Fleet\Changeouts\ChangeoutTestCase;

/** The Truck Changes tab: Rescue units only, real units only, from real changeouts and Fleet decisions. */
final class Captain5RescueTrucksTest extends ChangeoutTestCase
{
    private function workspace(): TestResponse
    {
        $this->actingAsCanonicalUser($this->superAdmin());

        return $this->getJson('/api/captain5/workspace')->assertOk();
    }

    /** @return array<string, mixed> */
    private function truck(TestResponse $response, string $unit): array
    {
        $row = collect($response->json('rescue_trucks'))->firstWhere('unit', $unit);
        self::assertIsArray($row, "No row for {$unit}.");

        return $row;
    }

    /** @param array<string, mixed> $overrides */
    private function completed(Apparatus $from, string $to, array $overrides = []): ApparatusChangeout
    {
        return ApparatusChangeout::query()->create([
            'client_submission_id' => (string) Str::uuid(), 'payload_hash' => str_repeat('a', 64),
            'unit_slug' => (string) $from->slug, 'unit_designation' => (string) $from->designation,
            'from_apparatus_id' => $from->id, 'from_vehicle_number' => $from->vehicle_number, 'to_vehicle_number' => $to,
            'status' => ApparatusChangeout::STATUS_COMPLETED, 'handoff_status' => ApparatusChangeout::HANDOFF_COMPLETE,
            'submitted_by_name' => 'Lt. Alvarez', 'effective_at' => now(),
            ...$overrides,
        ]);
    }

    private function rescueUnit(string $designation): Apparatus
    {
        $number = str_replace(' ', '', $designation);

        return Apparatus::query()->create([
            'station_id' => $this->unit->station_id, 'vin' => 'VIN-'.$number, 'unit_id' => $designation, 'designation' => $designation, 'name' => $designation,
            'type' => 'Rescue', 'vehicle_number' => 'T-'.$number, 'status' => 'In Service', 'daily_checkout_requirement' => 'required', 'daily_checkout_template' => 'rescue',
            'current_engine_hours' => 10, 'current_miles' => 10,
        ]);
    }

    private function inspectionOf(Apparatus $truck): ApparatusInspection
    {
        return ApparatusInspection::query()->create([
            'apparatus_id' => $truck->id, 'operator_name' => 'Lt. Alvarez', 'rank' => 'Lieutenant', 'shift' => 'A', 'unit_number' => $truck->designation,
            'designation_at_time' => $truck->designation, 'vehicle_number' => $truck->vehicle_number, 'results' => [], 'completed_at' => now(),
        ]);
    }

    public function test_only_rescue_units_are_listed_and_reserves_and_engines_are_not(): void
    {
        ['unit' => $unit] = $this->rescuePair();

        $response = $this->workspace();

        self::assertSame(['R 44'], array_column($response->json('rescue_trucks'), 'unit'), 'The reserve rescue and every Engine are left out.');
        $row = $this->truck($response, 'R 44');
        self::assertSame($unit->id, $row['apparatus_id']);
        self::assertSame('17503', $row['vehicle_number']);
        self::assertSame('Station 4', $row['station']);
        self::assertSame('In Service', $row['status']);
        self::assertFalse($row['changed_recently']);
        self::assertNull($row['changed_at']);
        self::assertSame([], $response->json('rescue_truck_changes'));
    }

    public function test_a_real_rescue_move_shows_the_new_truck_and_the_change_and_an_engine_move_does_not(): void
    {
        ['unit' => $unit, 'reserve' => $reserve] = $this->rescuePair();
        $this->postChangeout($unit, ['to_apparatus_id' => $reserve->id])->assertCreated();
        // E2 moves too. It is not Captain 5's to track here.
        $this->moveToReserve()->assertCreated();

        $response = $this->workspace();

        // The unit is now on truck 1033; truck 17503 is no longer anybody's unit.
        self::assertSame(['R 44'], array_column($response->json('rescue_trucks'), 'unit'));
        $row = $this->truck($response, 'R 44');
        self::assertSame('1033', $row['vehicle_number']);
        self::assertTrue($row['changed_recently']);
        self::assertNotNull($row['changed_at']);

        $changes = $response->json('rescue_truck_changes');
        self::assertCount(1, $changes, 'The Engine change is not listed.');
        self::assertSame('R 44', $changes[0]['unit']);
        self::assertSame('17503', $changes[0]['from_vehicle_number']);
        self::assertSame('1033', $changes[0]['to_vehicle_number']);
        self::assertTrue($changes[0]['recent']);
        self::assertSame('changeout', $changes[0]['source']);
    }

    public function test_left_behind_lines_carry_their_notes(): void
    {
        ['unit' => $unit] = $this->rescuePair();
        $changeout = $this->completed($unit, '1033', ['note' => 'Crew moved at 14:00.']);
        $line = ['apparatus_changeout_id' => $changeout->id, 'item_key' => 'a', 'handling' => 'leave'];
        ApparatusChangeoutItem::query()->create([...$line, 'item_name' => 'Airway Bag', 'note' => 'Stays with the old truck.', 'custody_status' => 'left_behind']);
        ApparatusChangeoutItem::query()->create([...$line, 'item_key' => 'b', 'item_name' => 'LifePak 15', 'handling' => 'transfer', 'custody_status' => 'moved']);

        $change = $this->workspace()->json('rescue_truck_changes.0');

        self::assertSame('Crew moved at 14:00.', $change['note']);
        self::assertSame('Lt. Alvarez', $change['by']);
        self::assertSame([['name' => 'Airway Bag', 'note' => 'Stays with the old truck.']], $change['left_behind']);
    }

    public function test_recent_means_24_hours_and_the_window_is_30_days_newest_first(): void
    {
        ['unit' => $unit] = $this->rescuePair();
        $this->completed($unit, 'OLD', ['effective_at' => now()->subDays(31)]);
        $this->completed($this->rescueUnit('R 2'), 'WEEK', ['effective_at' => now()->subDays(7)]);
        $this->completed($this->rescueUnit('R 11'), 'TODAY', ['effective_at' => now()->subHours(2)]);

        $response = $this->workspace();

        $changes = $response->json('rescue_truck_changes');
        self::assertSame(['TODAY', 'WEEK'], array_column($changes, 'to_vehicle_number'));
        self::assertSame([true, false], array_column($changes, 'recent'));
        self::assertTrue($this->truck($response, 'R 11')['changed_recently']);
        self::assertFalse($this->truck($response, 'R 2')['changed_recently']);
        self::assertNull($this->truck($response, 'R 44')['changed_at'], 'Older than the window.');
        self::assertSame(['R 2', 'R 11', 'R 44'], array_column($response->json('rescue_trucks'), 'unit'), 'Natural order, not string order.');
    }

    public function test_a_changeout_that_is_not_completed_is_not_a_change(): void
    {
        ['unit' => $unit] = $this->rescuePair();
        $this->completed($unit, 'OLD-ROW', ['status' => 'cancelled']);

        self::assertSame([], $this->workspace()->json('rescue_truck_changes'));
    }

    public function test_a_swap_fleet_decided_is_listed_and_an_undecided_or_engine_one_is_not(): void
    {
        ['unit' => $unit] = $this->rescuePair();
        $fleet = $this->fleetOperationsManager();
        $base = fn (Apparatus $truck): array => [
            'apparatus_id' => $truck->id, 'apparatus_inspection_id' => $this->inspectionOf($truck)->id, 'reporter_name' => 'Lt. Alvarez', 'answer' => 'swap_completed',
            'unit_designation' => $truck->designation, 'assigned_vehicle_number' => $truck->vehicle_number, 'reported_vehicle_number' => '2201', 'decided_by_user_id' => $fleet->id,
        ];
        ApparatusVehicleSwapRequest::query()->create([...$base($unit), 'status' => ApparatusVehicleSwapRequest::STATUS_APPROVED_SWAP, 'decided_at' => now()->subHour()]);
        ApparatusVehicleSwapRequest::query()->create([...$base($unit), 'status' => ApparatusVehicleSwapRequest::STATUS_PENDING]);
        ApparatusVehicleSwapRequest::query()->create([...$base($unit), 'status' => ApparatusVehicleSwapRequest::STATUS_REJECTED, 'decided_at' => now()->subHour()]);
        ApparatusVehicleSwapRequest::query()->create([...$base($this->unit), 'status' => ApparatusVehicleSwapRequest::STATUS_APPROVED_SWAP, 'decided_at' => now()->subHour()]);

        $changes = $this->workspace()->json('rescue_truck_changes');

        self::assertCount(1, $changes);
        self::assertSame('fleet_decision', $changes[0]['source']);
        self::assertSame('R 44', $changes[0]['unit']);
        self::assertSame('17503', $changes[0]['from_vehicle_number']);
        self::assertSame('2201', $changes[0]['to_vehicle_number']);
        self::assertSame((string) ($fleet->display_name ?: $fleet->name), $changes[0]['by']);
    }
}
