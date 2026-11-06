<?php

declare(strict_types=1);

namespace Tests\Feature\Fleet\UnitIdentity;

use App\Filament\Resources\ApparatusResource;
use App\Filament\Resources\ApparatusResource\Pages\EditApparatus;
use App\Filament\Resources\ApparatusResource\Pages\ListApparatuses;
use App\Filament\Resources\ApparatusResource\Pages\ViewApparatus;
use App\Models\Apparatus;
use App\Models\ApparatusChangeout;
use App\Models\Station;
use App\Services\Fleet\FrontlineCoverage;
use App\Services\Fleet\VehicleSwapNotifier;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Fleet\UnitIdentity\Concerns\BuildsUnitIdentityFixtures;
use Tests\TestCase;

/**
 * The simplified Apparatus page: the unit and the truck on it at the top, the truck's own fields, a read-only unit
 * assignment with a guarded correction, and the front-line rule that a unit's truck goes out of service through a swap.
 */
final class ApparatusEditPageTest extends TestCase
{
    use BuildsUnitIdentityFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Queue::fake();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->withoutVite();
        $this->buildUnitIdentityFixtures();
        $this->actingAs($this->superAdmin());
    }

    public function test_edit_page_shows_the_unit_the_truck_and_every_section(): void
    {
        Livewire::test(EditApparatus::class, ['record' => $this->e1->getRouteKey()])
            ->assertSuccessful()
            ->assertSee('E 1 on truck 20503')
            ->assertSee('Front-line unit')
            ->assertSee('Truck on it now')
            ->assertSee('ending 020503')
            ->assertSee('This truck')
            ->assertSee('Vehicle # (the physical truck)')
            ->assertSee('Unit assignment')
            ->assertSee('Change unit assignment')
            ->assertSee('More settings')
            ->assertSee('Maintenance and PM')
            ->assertSee('E1 must always have a truck In Service.')
            ->assertActionVisible('swapTruck')
            ->assertActionVisible('takeOutOfService')
            ->assertActionHidden('returnToService');
    }

    public function test_every_field_on_the_page_still_saves(): void
    {
        $station2 = Station::query()->create(['station_number' => 2, 'name' => 'Station 2', 'address' => '2300 Pine Tree Drive', 'is_active' => true]);
        // A linked truck takes its vehicle # from the vehicle record (covered in ApparatusFleetWorkspaceTest); unlinked, it is typed.
        Apparatus::withoutEvents(fn () => $this->spare->forceFill(['unit_master_vehicle_id' => null])->save());

        Livewire::test(EditApparatus::class, ['record' => $this->spare->getRouteKey()])
            ->fillForm([
                'vehicle_number' => '002-19',
                'vin' => 'TESTVIN000000219',
                'status' => 'Maintenance',
                'year' => 2019,
                'make' => 'PIERCE',
                'model' => 'ENFORCER',
                'current_engine_hours' => 2210.5,
                'current_miles' => 30600,
                'notes' => 'Spare engine, front bumper scratched.',
                'station_id' => $station2->id,
                'assignment' => 'Station 2',
                'current_location' => 'Station 2',
                'class_description' => 'ENGINE',
                'daily_checkout_requirement' => 'reserve',
                'daily_checkout_template' => 'engine',
                'last_service_type' => 'PMA',
                'last_service_date' => '2026-10-01',
                'last_pm_date' => '2026-10-01',
                'last_pm_engine_hours' => 2100.0,
                'last_pm_mileage' => 30000,
                'pm_interval_hours' => 250,
                'pm_interval_miles' => 2500,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $saved = $this->spare->fresh();
        self::assertSame(
            ['002-19', 'TESTVIN000000219', 'Maintenance', 2019, 'PIERCE', 'ENFORCER', 30600, 'Spare engine, front bumper scratched.', $station2->id, 'Station 2', 'Station 2', 'ENGINE', 'reserve', 'engine', 'PMA', '2026-10-01', '2026-10-01', 30000, 250, 2500],
            [$saved->vehicle_number, $saved->vin, $saved->status, (int) $saved->year, $saved->make, $saved->model, $saved->current_miles, $saved->notes, $saved->station_id, $saved->assignment, $saved->current_location, $saved->class_description, $saved->daily_checkout_requirement->value, $saved->daily_checkout_template->value, $saved->last_service_type, $saved->last_service_date?->format('Y-m-d'), $saved->last_pm_date?->format('Y-m-d'), $saved->last_pm_mileage, $saved->pm_interval_hours, $saved->pm_interval_miles],
        );
        self::assertEqualsWithDelta(2210.5, (float) $saved->current_engine_hours, 0.01);
        self::assertEqualsWithDelta(2100.0, (float) $saved->last_pm_engine_hours, 0.01);
        self::assertSame('E11', $saved->unit_id, 'The unit fields are not part of the page save.');
    }

    public function test_the_page_save_cannot_set_a_front_line_units_truck_out_of_service(): void
    {
        Livewire::test(EditApparatus::class, ['record' => $this->e1->getRouteKey()])
            ->fillForm(['status' => 'Out of Service'])
            ->call('save')
            ->assertHasFormErrors(['status']);

        self::assertSame('In Service', $this->e1->fresh()->status);
    }

    public function test_change_status_on_a_front_line_truck_is_refused_and_points_to_the_swap(): void
    {
        Livewire::test(ViewApparatus::class, ['record' => $this->e1->getRouteKey()])
            ->callAction('updateStatus', data: ['status' => 'Out of Service'])
            ->assertHasActionErrors(['status']);

        self::assertSame('In Service', $this->e1->fresh()->status);
    }

    public function test_out_of_service_on_a_front_line_truck_asks_which_truck_runs_as_the_unit(): void
    {
        Livewire::test(ViewApparatus::class, ['record' => $this->e1->getRouteKey()])
            ->mountAction('takeOutOfService')
            ->assertSee('Which truck will run as E 1?')
            ->setActionData(['to_apparatus_id' => $this->spare->id])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertRedirect(ApparatusResource::getUrl('view', ['record' => $this->spare->id]));

        $newTruck = $this->spare->fresh();
        $oldTruck = $this->e1->fresh();
        self::assertSame(['E1', 'In Service'], [$newTruck->unit_id, $newTruck->status]);
        self::assertSame(['E11', 'Out of Service'], [$oldTruck->unit_id, $oldTruck->status]);
        self::assertSame(1, ApparatusChangeout::query()->unacknowledged()->count());
        self::assertSame([], app(FrontlineCoverage::class)->problems());
    }

    public function test_choosing_no_replacement_needs_a_reason(): void
    {
        Livewire::test(ViewApparatus::class, ['record' => $this->e1->getRouteKey()])
            ->callAction('takeOutOfService', data: ['no_replacement' => true, 'reason' => ''])
            ->assertHasActionErrors(['reason']);

        self::assertSame('In Service', $this->e1->fresh()->status);
        self::assertSame(0, ApparatusChangeout::query()->count());
    }

    public function test_no_replacement_escape_hatch_keeps_the_unit_and_alerts_like_a_swap(): void
    {
        $this->mock(VehicleSwapNotifier::class)->shouldReceive('changeoutCommitted')->once();

        Livewire::test(ViewApparatus::class, ['record' => $this->e1->getRouteKey()])
            ->callAction('takeOutOfService', data: ['no_replacement' => true, 'reason' => 'Both spare engines are at the shop.'])
            ->assertHasNoActionErrors();

        $truck = $this->e1->fresh();
        self::assertSame(['E1', 'Out of Service'], [$truck->unit_id, $truck->status], 'The unit stays on its truck.');
        $changeout = ApparatusChangeout::query()->sole();
        self::assertTrue($changeout->isWithoutReplacement());
        self::assertFalse($changeout->isAcknowledged());
        self::assertSame('E 1 has no truck in service: truck 20503 is Out of Service', $changeout->headline());
        self::assertSame('Both spare engines are at the shop.', $changeout->note);

        $problems = app(FrontlineCoverage::class)->problems();
        self::assertSame([FrontlineCoverage::KIND_NOT_IN_SERVICE], array_column($problems, 'kind'));

        // The same Apparatus page alerts a swap raises: the vehicle change banner and the front-line warning.
        Livewire::test(ListApparatuses::class)
            ->assertSee('E 1 has no truck in service: truck 20503 is Out of Service')
            ->assertSee('E1 is on truck 20503, which is Out of Service.');
    }

    public function test_a_spare_truck_goes_out_of_service_and_back_without_a_swap(): void
    {
        Livewire::test(ViewApparatus::class, ['record' => $this->spare->getRouteKey()])
            ->callAction('takeOutOfService')
            ->assertHasNoActionErrors();
        self::assertSame('Out of Service', $this->spare->fresh()->status);

        Livewire::test(ViewApparatus::class, ['record' => $this->spare->getRouteKey()])
            ->callAction('returnToService')
            ->assertHasNoActionErrors();
        self::assertSame('In Service', $this->spare->fresh()->status);
        self::assertSame(0, ApparatusChangeout::query()->count());
    }

    public function test_swap_truck_moves_the_unit_and_follows_it(): void
    {
        Livewire::test(ViewApparatus::class, ['record' => $this->e1->getRouteKey()])
            ->callAction('swapTruck', data: ['to_apparatus_id' => $this->spare->id, 'note' => 'Pump problem on 20503.'])
            ->assertHasNoActionErrors()
            ->assertRedirect(ApparatusResource::getUrl('view', ['record' => $this->spare->id]));

        self::assertSame('E1', $this->spare->fresh()->unit_id);
        self::assertSame('Pump problem on 20503.', ApparatusChangeout::query()->sole()->note);
    }

    public function test_change_unit_assignment_refuses_a_vehicle_number_and_needs_confirmation(): void
    {
        Livewire::test(ViewApparatus::class, ['record' => $this->spare->getRouteKey()])
            ->callAction('changeUnitAssignment', data: ['unit_id' => '20503', 'designation' => 'E 11', 'name' => 'ENGINE E11', 'slug' => 'e11', 'confirmed' => true]);
        self::assertSame('E11', $this->spare->fresh()->unit_id);

        Livewire::test(ViewApparatus::class, ['record' => $this->spare->getRouteKey()])
            ->callAction('changeUnitAssignment', data: ['unit_id' => 'E21', 'designation' => 'E 21', 'name' => 'ENGINE E21', 'slug' => 'e21', 'confirmed' => false])
            ->assertHasActionErrors(['confirmed']);
        self::assertSame('E11', $this->spare->fresh()->unit_id);
    }

    public function test_change_unit_assignment_saves_a_consistent_correction(): void
    {
        Livewire::test(ViewApparatus::class, ['record' => $this->spare->getRouteKey()])
            ->mountAction('changeUnitAssignment')
            ->setActionData(['unit_id' => 'E21'])
            ->assertActionDataSet(['designation' => 'E 21', 'name' => 'ENGINE E21', 'slug' => 'e21'])
            ->setActionData(['confirmed' => true])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $fresh = $this->spare->fresh();
        self::assertSame(['E21', 'E 21', 'ENGINE E21', 'e21', '002-18'], [$fresh->unit_id, $fresh->designation, $fresh->name, $fresh->slug, $fresh->vehicle_number]);
    }

    public function test_a_unit_named_on_the_wrong_truck_is_shown_on_the_apparatus_list(): void
    {
        // The data the Nov 5 mistake left behind, written past the guard as a legacy row would be.
        Apparatus::withoutEvents(fn () => $this->e1->forceFill(['unit_id' => '002-18'])->save());

        $problems = app(FrontlineCoverage::class)->problems();
        $e1 = collect($problems)->firstWhere('unit_id', 'E1');
        self::assertSame(FrontlineCoverage::KIND_CONFLICT, $e1['kind']);
        self::assertStringContainsString("record #{$this->e1->id} (vehicle 20503), but that record's unit ID is 002-18", $e1['message']);

        Livewire::test(ListApparatuses::class)
            ->assertSee('A front-line unit needs a truck')
            ->assertSee('use Change unit assignment so every unit field names the same unit.');
    }
}
