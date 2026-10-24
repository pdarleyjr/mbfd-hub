<?php

declare(strict_types=1);

namespace Tests\Feature\Fleet;

use App\Enums\AccountStatus;
use App\Filament\Resources\ApparatusResource;
use App\Filament\Resources\ApparatusResource\Pages\EditApparatus;
use App\Filament\Resources\ApparatusResource\Pages\ListApparatuses;
use App\Filament\Resources\ApparatusResource\Pages\ViewApparatus;
use App\Filament\Resources\UnitMasterVehicleResource;
use App\Models\Apparatus;
use App\Models\ApparatusDefect;
use App\Models\UnitMasterVehicleChange;
use App\Models\User;
use App\Services\Fleet\ApparatusMasterVehicleLink;
use App\Services\Security\FleetOperationsManagerAssignment;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Fleet\Concerns\BuildsMasterLinkFixtures;
use Tests\TestCase;

/**
 * Fleet -> Apparatus as the Fleet manager and mechanics use it: worklist tabs,
 * profile actions, the vehicle record card and the "Link vehicle record" decision.
 */
final class ApparatusFleetWorkspaceTest extends TestCase
{
    use BuildsMasterLinkFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->withoutVite();
    }

    public function test_worklist_tabs_put_units_that_need_fleet_first(): void
    {
        $this->master('20503', ['make' => 'PIERCE', 'model' => 'VELOCITY', 'year' => '2021']);
        $clear = $this->legacyApparatus('E 1', '20503');
        app(ApparatusMasterVehicleLink::class)->reconcile($clear);
        $broken = $this->legacyApparatus('R 3', '17501', ['status' => 'Out of Service']);
        $pmDue = $this->legacyApparatus('E 2', '24509', ['current_engine_hours' => 1438, 'last_pm_engine_hours' => 672]);
        $spare = $this->legacyApparatus('E 21', '002-16', ['status' => 'Available']);
        $withIssue = $this->legacyApparatus('R 2', '16507');
        ApparatusDefect::query()->create([
            'apparatus_id' => $withIssue->id,
            'compartment' => 'Cab',
            'item' => 'Flashlight',
            'status' => 'Missing',
            'issue_type' => 'missing',
            'resolved' => false,
        ]);

        $this->actingAs($this->fleetManager());

        Livewire::test(ListApparatuses::class)
            ->assertSuccessful()
            ->set('activeTab', 'attention')
            ->call('loadTable')
            ->assertCanSeeTableRecords([$broken, $pmDue, $withIssue])
            ->assertCanNotSeeTableRecords([$clear])
            ->set('activeTab', 'out_of_service')
            ->assertCanSeeTableRecords([$broken])
            ->assertCanNotSeeTableRecords([$pmDue, $clear])
            ->set('activeTab', 'pm')
            ->assertCanSeeTableRecords([$pmDue])
            ->assertCanNotSeeTableRecords([$clear, $broken])
            ->set('activeTab', 'spares')
            ->assertCanSeeTableRecords([$spare])
            ->assertCanNotSeeTableRecords([$clear])
            ->set('activeTab', 'vehicle_record')
            ->assertCanSeeTableRecords([$broken, $spare])
            ->assertCanNotSeeTableRecords([$clear]);
    }

    public function test_pm_window_uses_each_units_own_interval(): void
    {
        $short = $this->legacyApparatus('A 1', '002-20', ['current_engine_hours' => 120, 'last_pm_engine_hours' => 0, 'pm_interval_hours' => 150]);
        $standard = $this->legacyApparatus('A 2', '18500', ['current_engine_hours' => 120, 'last_pm_engine_hours' => 0, 'pm_interval_hours' => 300]);

        $ids = ApparatusResource::wherePm(Apparatus::query(), 'attention')->pluck('id')->all();

        self::assertContains($short->id, $ids, '120 of 150 hours is inside the 50-hour warning window.');
        self::assertNotContains($standard->id, $ids);
    }

    public function test_profile_offers_the_everyday_work_and_the_vehicle_record(): void
    {
        $master = $this->master('16507', ['make' => 'FREIGHTLINER', 'model' => 'M2106', 'year' => '2016', 'tag_number' => 'XE4031']);
        $unit = $this->legacyApparatus('R 2', '16507', ['year' => 2015]);
        app(ApparatusMasterVehicleLink::class)->reconcile($unit);
        $this->actingAs($this->fleetManager());

        Livewire::test(ViewApparatus::class, ['record' => $unit->getRouteKey()])
            ->assertSuccessful()
            ->assertActionVisible('scheduleService')
            ->assertActionVisible('logPmService')
            ->assertActionVisible('updateStatus')
            ->assertSee('Master Vehicle Inventory #16507')
            ->assertSee('Plate XE4031')
            ->assertSee(UnitMasterVehicleResource::getUrl('view', ['record' => $master]), false);

        self::assertSame(2016, (int) $unit->fresh()->year, 'The master record wins on link.');
    }

    public function test_unlinked_unit_explains_why_and_fleet_links_it_with_history(): void
    {
        // Production shape: the Captain 5 apparatus row still carried a former vehicle's
        // identity, so its VIN contradicts master 22530 (the Ford F-250 assigned to
        // CAPTAIN 5) and the automatic match refused it.
        $f250 = $this->master('22530', ['make' => 'Ford', 'model' => 'F-250', 'year' => '2022', 'serial_number' => 'TESTVIN000000530', 'employee_or_vehicle_name' => 'CAPTAIN 5']);
        $captain = $this->legacyApparatus('Captain 5', '22530', ['vin' => 'TESTVIN000000631', 'make' => 'Former make', 'model' => 'Former model', 'year' => 2024]);
        app(ApparatusMasterVehicleLink::class)->reconcile($captain);
        $captain->refresh();
        self::assertSame(Apparatus::MASTER_LINK_NEEDS_REVIEW, $captain->master_link_status);

        $manager = $this->fleetManager();
        $this->actingAs($manager);

        Livewire::test(ViewApparatus::class, ['record' => $captain->getRouteKey()])
            ->assertSee('Vehicle record needs review.')
            ->assertSee('does not match master vehicle 22530')
            ->assertActionVisible('linkVehicleRecord')
            ->callAction('linkVehicleRecord', data: ['unit_master_vehicle_id' => $f250->id])
            ->assertHasNoActionErrors();

        $captain->refresh();
        self::assertSame($f250->id, $captain->unit_master_vehicle_id);
        self::assertSame(Apparatus::MASTER_LINK_LINKED, $captain->master_link_status);
        self::assertSame(['22530', 'Ford', 'F-250', 2022, 'TESTVIN000000530'], [$captain->vehicle_number, $captain->make, $captain->model, (int) $captain->year, $captain->vin]);
        $history = UnitMasterVehicleChange::query()->where('unit_master_vehicle_id', $f250->id)->get();
        self::assertTrue($history->every(fn (UnitMasterVehicleChange $change): bool => $change->user_id === $manager->id));
        self::assertSame('TESTVIN000000631', $history->firstWhere('field', 'serial_number')?->old_value, 'The former identity stays in the vehicle history.');
        self::assertSame('Former model', $history->firstWhere('field', 'model')?->old_value);
    }

    public function test_link_picker_searches_the_whole_inventory_case_insensitively(): void
    {
        $f250 = $this->master('22530', ['make' => 'Ford', 'model' => 'F-250', 'year' => '2022']);
        $this->master('16507', ['make' => 'FREIGHTLINER', 'model' => 'M2106']);
        $unit = $this->legacyApparatus('C 9', '9999');
        app(ApparatusMasterVehicleLink::class)->reconcile($unit);
        $this->actingAs($this->fleetManager());

        $results = Livewire::test(ViewApparatus::class, ['record' => $unit->getRouteKey()])
            ->mountAction('linkVehicleRecord')
            ->instance()
            ->getFormSelectSearchResults('mountedActionsData.0.unit_master_vehicle_id', 'f-250');

        self::assertSame([(string) $f250->id], array_map('strval', array_column($results, 'value')), json_encode($results));
    }

    public function test_desktop_list_shows_the_vehicle_and_flags_review(): void
    {
        $this->master('20503', ['make' => 'PIERCE', 'model' => 'VELOCITY', 'year' => '2021']);
        $engine = $this->legacyApparatus('E 1', '20503');
        app(ApparatusMasterVehicleLink::class)->reconcile($engine);
        $boat = $this->legacyApparatus('FB6', null);
        app(ApparatusMasterVehicleLink::class)->reconcile($boat);
        $this->actingAs($this->fleetManager());

        Livewire::test(ListApparatuses::class)
            ->call('loadTable')
            ->assertSee('2021 PIERCE VELOCITY')
            ->assertSee('Vehicle record needs review');
    }

    public function test_a_vehicle_linked_to_another_unit_cannot_be_taken(): void
    {
        $this->master('20503');
        $engine = $this->legacyApparatus('E 1', '20503');
        app(ApparatusMasterVehicleLink::class)->reconcile($engine);
        $other = $this->legacyApparatus('E 31', null);
        app(ApparatusMasterVehicleLink::class)->reconcile($other);
        $this->actingAs($this->fleetManager());

        Livewire::test(ViewApparatus::class, ['record' => $other->getRouteKey()])
            ->callAction('linkVehicleRecord', data: ['unit_master_vehicle_id' => $engine->fresh()->unit_master_vehicle_id]);

        self::assertNull($other->fresh()->unit_master_vehicle_id);
        self::assertSame($engine->id, Apparatus::query()->where('unit_master_vehicle_id', $engine->fresh()->unit_master_vehicle_id)->sole()->id);
    }

    public function test_view_only_fleet_member_sees_the_profile_but_cannot_link_or_run_service(): void
    {
        $this->master('16507');
        $unit = $this->legacyApparatus('R 2', '9999');
        app(ApparatusMasterVehicleLink::class)->reconcile($unit);
        $this->actingAs($this->fleetViewer());

        Livewire::test(ViewApparatus::class, ['record' => $unit->getRouteKey()])
            ->assertSuccessful()
            ->assertActionHidden('linkVehicleRecord')
            ->assertActionHidden('scheduleService')
            ->assertActionHidden('logPmService')
            ->assertActionHidden('updateStatus');

        self::assertNull($unit->fresh()->unit_master_vehicle_id);
    }

    public function test_linked_vehicle_number_is_set_by_the_record_not_typed(): void
    {
        $this->master('20504', ['make' => 'PIERCE']);
        $unit = $this->legacyApparatus('E 4', '20504');
        app(ApparatusMasterVehicleLink::class)->reconcile($unit);
        $this->actingAs($this->fleetManager());

        Livewire::test(EditApparatus::class, ['record' => $unit->getRouteKey()])
            ->assertFormFieldIsDisabled('vehicle_number')
            ->fillForm(['vehicle_number' => '99999', 'model' => 'VELOCITY'])
            ->call('save')
            ->assertHasNoFormErrors();

        $unit->refresh();
        self::assertSame('20504', $unit->vehicle_number);
        self::assertSame('VELOCITY', $unit->masterVehicle?->model, 'Shared fields still write through to the master.');
    }

    public function test_clearing_a_shared_field_on_the_apparatus_never_erases_the_master(): void
    {
        $master = $this->master('20504', ['make' => 'PIERCE', 'serial_number' => 'TESTVIN000000504']);
        $unit = $this->legacyApparatus('E 4', '20504');
        app(ApparatusMasterVehicleLink::class)->reconcile($unit);
        $this->actingAs($this->fleetManager());

        Livewire::test(EditApparatus::class, ['record' => $unit->getRouteKey()])
            ->fillForm(['make' => '', 'vin' => ''])
            ->call('save')
            ->assertHasNoFormErrors();

        self::assertSame('PIERCE', $master->fresh()->make);
        self::assertSame('TESTVIN000000504', $master->fresh()->serial_number);
        self::assertSame('PIERCE', $unit->fresh()->make, 'The apparatus copy goes back to the master value.');
        self::assertSame('TESTVIN000000504', $unit->fresh()->vin);
    }

    public function test_a_vin_that_belongs_to_another_vehicle_is_refused(): void
    {
        $this->master('20504', ['serial_number' => 'TESTVIN000000504']);
        $this->master('20503', ['serial_number' => 'TESTVIN000000503']);
        $unit = $this->legacyApparatus('E 4', '20504');
        app(ApparatusMasterVehicleLink::class)->reconcile($unit);
        $this->actingAs($this->fleetManager());

        Livewire::test(EditApparatus::class, ['record' => $unit->getRouteKey()])
            ->fillForm(['vin' => 'testvin-000000503'])
            ->call('save')
            ->assertHasFormErrors(['vin']);

        self::assertSame('TESTVIN000000504', $unit->fresh()->vin);
    }

    public function test_master_profile_shows_the_linked_apparatus(): void
    {
        $master = $this->master('17505', ['make' => 'PIERCE', 'model' => 'ARROW XT']);
        $unit = $this->legacyApparatus('L 3', '17505', ['status' => 'In Service']);
        app(ApparatusMasterVehicleLink::class)->reconcile($unit);
        $this->actingAs($this->fleetManager());

        $this->get(UnitMasterVehicleResource::getUrl('view', ['record' => $master]))
            ->assertOk()
            ->assertSee('Daily operations for this vehicle')
            ->assertSee('L 3')
            ->assertSee(ApparatusResource::getUrl('view', ['record' => $unit]), false);
    }

    private function fleetViewer(): User
    {
        $viewer = User::factory()->create(['account_status' => AccountStatus::Active]);
        foreach (['admin.access', 'admin.fleet.view'] as $permission) {
            $viewer->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }

        return $viewer->fresh();
    }

    private function fleetManager(): User
    {
        $super = User::factory()->create(['account_status' => AccountStatus::Active]);
        $super->assignRole(Role::findOrCreate('super_admin', 'web'));
        $manager = User::factory()->create(['account_status' => AccountStatus::Active]);
        app(FleetOperationsManagerAssignment::class)->apply($super, $manager, true, 'fleet duties');

        return $manager->fresh();
    }
}
