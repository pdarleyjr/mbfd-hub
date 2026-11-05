<?php

declare(strict_types=1);

namespace Tests\Feature\Fleet\UnitIdentity;

use App\Models\Apparatus;
use App\Services\Fleet\ApparatusChangeoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Fleet\UnitIdentity\Concerns\BuildsUnitIdentityFixtures;
use Tests\TestCase;

/** The model-level guard: every write path, not only the admin form, keeps a unit and a truck apart. */
final class UnitIdentityGuardTest extends TestCase
{
    use BuildsUnitIdentityFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Queue::fake();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->buildUnitIdentityFixtures();
    }

    public function test_the_nov_5_mistake_is_refused_a_unit_id_that_is_another_trucks_vehicle_number(): void
    {
        $errors = $this->refused(fn () => $this->e1->update(['unit_id' => '002-18']));

        self::assertArrayHasKey('unit_id', $errors);
        self::assertStringContainsString('vehicle number of another truck', $errors['unit_id'][0]);
        self::assertSame('E1', $this->e1->fresh()->unit_id);

        $errors = $this->refused(fn () => $this->spare->update(['unit_id' => '20503']));
        self::assertArrayHasKey('unit_id', $errors);
        self::assertSame('E11', $this->spare->fresh()->unit_id);
    }

    public function test_vehicle_number_comparison_ignores_case_spaces_and_dashes(): void
    {
        $this->spare->forceFill(['vehicle_number' => 'R-1033'])->save();

        $errors = $this->refused(fn () => $this->e1->update(['unit_id' => ' r 1033 ']));

        self::assertArrayHasKey('unit_id', $errors);
    }

    public function test_a_unit_can_only_be_on_one_truck(): void
    {
        $errors = $this->refused(fn () => $this->spare->update(['unit_id' => 'E1', 'designation' => 'E 1', 'name' => 'ENGINE E1', 'slug' => 'e1-copy']));

        self::assertStringContainsString('E1 is already on another truck', $errors['unit_id'][0]);
        self::assertSame('E11', $this->spare->fresh()->unit_id);

        $errors = $this->refused(fn () => $this->spare->update(['designation' => 'E 1']));
        self::assertStringContainsString('names E1, which is on another truck', $errors['designation'][0], 'The deploy gate collision: E 1 on a truck whose unit ID is not E1.');

        $errors = $this->refused(fn () => $this->spare->update(['name' => 'E1']));
        self::assertArrayHasKey('name', $errors);
    }

    public function test_identity_fields_that_disagree_are_refused(): void
    {
        $errors = $this->refused(fn () => $this->e1->update(['designation' => 'E 11']));
        self::assertStringContainsString('Designation E 11 does not match unit ID E1. Use E 1', $errors['designation'][0]);

        $errors = $this->refused(fn () => $this->e1->update(['designation' => 'Engine 7']));
        self::assertArrayHasKey('designation', $errors);

        $fresh = $this->e1->fresh();
        self::assertSame(['E1', 'E 1', 'ENGINE E1', 'e1'], [$fresh->unit_id, $fresh->designation, $fresh->name, $fresh->slug]);
    }

    public function test_a_consistent_correction_and_the_shared_reserve_label_are_accepted(): void
    {
        $this->spare->update(['unit_id' => 'E21', 'designation' => 'E 21', 'name' => 'ENGINE E21', 'slug' => 'e21']);
        self::assertSame('E21', $this->spare->fresh()->unit_id);

        $reserve = Apparatus::query()->create([
            'unit_id' => 'R-1033', 'designation' => 'Reserve', 'name' => 'RESCUE 1033', 'slug' => 'r1033',
            'type' => 'Rescue', 'vehicle_number' => '1033', 'status' => 'Available',
        ]);
        $second = Apparatus::query()->create([
            'unit_id' => 'R-1034', 'designation' => 'Reserve', 'name' => 'RESCUE 1034', 'slug' => 'r1034',
            'type' => 'Rescue', 'vehicle_number' => '1034', 'status' => 'Available',
        ]);
        self::assertTrue($reserve->exists && $second->exists, 'Many spares share the designation Reserve.');
    }

    public function test_a_vehicle_number_that_names_another_unit_is_refused(): void
    {
        $errors = $this->refused(fn () => $this->spare->update(['vehicle_number' => 'E 1']));

        self::assertArrayHasKey('vehicle_number', $errors);
        self::assertSame('002-18', $this->spare->fresh()->vehicle_number);
    }

    public function test_an_older_inconsistent_row_still_takes_unrelated_edits(): void
    {
        $legacy = Apparatus::withoutEvents(fn (): Apparatus => Apparatus::query()->create([
            'unit_id' => 'E1-OLD', 'designation' => 'E 1', 'name' => 'Rescue 4', 'slug' => 'e1-old', 'type' => 'Engine',
            'vehicle_number' => '17503', 'status' => 'In Service',
        ]));

        $legacy->update(['current_miles' => 1234, 'notes' => 'Meter correction', 'status' => 'Maintenance']);

        self::assertSame(1234, $legacy->fresh()->current_miles);
    }

    public function test_a_truck_swap_still_moves_the_unit_between_trucks(): void
    {
        $this->actingAs($admin = $this->superAdmin());

        $result = app(ApparatusChangeoutService::class)->submit($this->e1, $admin, [
            'client_submission_id' => (string) Str::uuid(),
            'expected_vehicle_number' => '20503',
            'expected_unit_slug' => 'e1',
            'to_apparatus_id' => $this->spare->id,
        ]);

        self::assertTrue($result['created']);
        $newTruck = $this->spare->fresh();
        $oldTruck = $this->e1->fresh();
        self::assertSame(['E1', 'E 1', 'e1', 'In Service', '002-18'], [$newTruck->unit_id, $newTruck->designation, $newTruck->slug, $newTruck->status, $newTruck->vehicle_number]);
        self::assertSame(['E11', 'E 11', 'e11', 'Out of Service', '20503'], [$oldTruck->unit_id, $oldTruck->designation, $oldTruck->slug, $oldTruck->status, $oldTruck->vehicle_number]);
    }

    /** @return array<string, list<string>> */
    private function refused(callable $write): array
    {
        try {
            $write();
        } catch (ValidationException $exception) {
            // A refused save leaves the attempted values on the in-memory model.
            $this->e1->refresh();
            $this->spare->refresh();

            return $exception->errors();
        }
        self::fail('The identity guard let the write through.');
    }
}
