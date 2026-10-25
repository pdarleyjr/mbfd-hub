<?php

declare(strict_types=1);

namespace Tests\Feature\Fleet;

use App\Filament\Resources\ApparatusResource;
use App\Filament\Resources\UnitMasterVehicleResource\Pages\ListUnitMasterVehicles;
use App\Filament\Resources\UnitMasterVehicleResource\Pages\ViewUnitMasterVehicle;
use App\Models\Apparatus;
use App\Models\Station;
use App\Models\UnitMasterVehicle;
use App\Models\UnitMasterVehicleChange;
use App\Models\User;
use App\Services\Fleet\MasterVehicleApparatusCreator;
use App\Services\Fleet\MasterVehicleApparatusReview;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Fleet\Concerns\BuildsMasterLinkFixtures;
use Tests\Feature\Fleet\Concerns\BuildsVehicleSwapFixtures;
use Tests\TestCase;

/** The Master Vehicle Inventory "Apparatus review" worklist and its two decisions. */
final class ApparatusReviewWorklistTest extends TestCase
{
    use BuildsMasterLinkFixtures;
    use BuildsVehicleSwapFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSwapFixtures();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Notification::fake();
        Queue::fake();
        Storage::fake('public');
    }

    /** @return array<string, mixed> */
    private function details(string $designation = 'E 7'): array
    {
        return [
            'designation' => $designation,
            'station_id' => Station::query()->where('station_number', 3)->value('id'),
            'type' => 'Engine',
            'class_description' => 'ENGINE',
            'status' => 'Available',
            'daily_checkout_requirement' => 'unknown',
        ];
    }

    /** @param array<string, mixed> $attributes */
    private function reviewVehicle(string $number = 'V-501', array $attributes = []): UnitMasterVehicle
    {
        $markedAt = $attributes['not_apparatus_at'] ?? null;
        unset($attributes['not_apparatus_at']);
        $vehicle = $this->master($number, $attributes + [
            'year' => '2020', 'make' => 'Pierce', 'model' => 'Enforcer', 'serial_number' => '1FTTEST0000000501',
            'tag_number' => 'XT0501', 'fleet_class_description' => 'ENGINE',
        ]);
        if ($markedAt !== null) {
            $vehicle->forceFill(['not_apparatus_at' => $markedAt])->save();
        }

        return $vehicle->fresh();
    }

    private function asFleetAdmin(): User
    {
        $admin = $this->superAdmin();
        $this->actingAsCanonicalUser($admin);

        return $admin;
    }

    private function worklistIds(): array
    {
        return UnitMasterVehicle::query()->needingApparatusReview()->orderBy('id')->pluck('id')->all();
    }

    public function test_the_worklist_holds_unlinked_road_vehicles_of_the_apparatus_section_that_are_not_marked(): void
    {
        $listed = $this->reviewVehicle('V-501');
        $spaced = $this->reviewVehicle('V-502', ['section' => '  frontline and reserve fire apparatus ', 'asset_category' => null]);
        $this->reviewVehicle('V-503', ['asset_category' => 'boat']);
        $this->reviewVehicle('V-504', ['asset_category' => 'trailer']);
        $this->reviewVehicle('V-505', ['section' => 'Staff and support vehicles']);
        $this->reviewVehicle('V-506', ['not_apparatus_at' => now()]);
        // E2, E3 and RE1 from the fixtures are linked to apparatus: they never appear.

        $this->assertSame([$listed->id, $spaced->id], $this->worklistIds());
    }

    public function test_the_tab_and_its_badge_show_the_worklist(): void
    {
        $this->asFleetAdmin();
        $listed = $this->reviewVehicle('V-501');
        $other = $this->reviewVehicle('V-505', ['section' => 'Staff and support vehicles']);

        $component = Livewire::test(ListUnitMasterVehicles::class);
        $tabs = $component->instance()->getTabs();
        $this->assertSame(['all', 'apparatus_review'], array_keys($tabs));
        $this->assertSame('Apparatus review', $tabs['apparatus_review']->getLabel());
        $this->assertSame(1, $tabs['apparatus_review']->getBadge());

        $component->call('loadTable')
            ->assertCanSeeTableRecords([$listed, $other])
            ->set('activeTab', 'apparatus_review')
            ->assertCanSeeTableRecords([$listed])
            ->assertCanNotSeeTableRecords([$other]);
    }

    public function test_the_create_modal_is_prefilled_from_the_vehicle_record(): void
    {
        $this->asFleetAdmin();
        $vehicle = $this->reviewVehicle();

        Livewire::test(ListUnitMasterVehicles::class)
            ->mountTableAction('createApparatusRecord', $vehicle)
            ->assertTableActionDataSet([
                'veh_number' => 'V-501',
                'year_make_model' => '2020 Pierce Enforcer',
                'serial_number' => '1FTTEST0000000501',
                'type' => 'Engine',
                'class_description' => 'ENGINE',
                'status' => 'Available',
                'daily_checkout_requirement' => 'unknown',
            ]);
    }

    public function test_create_apparatus_record_creates_and_links_in_one_step_and_opens_the_profile(): void
    {
        $admin = $this->asFleetAdmin();
        $vehicle = $this->reviewVehicle();

        $component = Livewire::test(ListUnitMasterVehicles::class)
            ->callTableAction('createApparatusRecord', $vehicle, data: $this->details())
            ->assertHasNoTableActionErrors();

        $apparatus = Apparatus::query()->where('designation', 'E 7')->sole();
        $component->assertRedirect(ApparatusResource::getUrl('view', ['record' => $apparatus]));
        $this->assertSame($vehicle->id, $apparatus->unit_master_vehicle_id);
        $this->assertSame(Apparatus::MASTER_LINK_LINKED, $apparatus->master_link_status);
        $this->assertSame(
            ['V-501', 2020, 'Pierce', 'Enforcer', '1FTTEST0000000501', 'Engine', 'Available', 'unknown', 'ENGINE'],
            [$apparatus->vehicle_number, (int) $apparatus->year, $apparatus->make, $apparatus->model, $apparatus->vin, $apparatus->type, $apparatus->status, $apparatus->daily_checkout_requirement->value, $apparatus->class_description],
        );
        $this->assertSame(Station::query()->where('station_number', 3)->value('id'), $apparatus->station_id);

        $history = UnitMasterVehicleChange::query()->where('unit_master_vehicle_id', $vehicle->id)->where('field', 'record')->sole();
        $this->assertSame(['apparatus record created', UnitMasterVehicleChange::SOURCE_MASTER_INVENTORY, $admin->id, 'applied'], [$history->new_value, $history->source, $history->user_id, $history->status]);
        $this->assertStringContainsString('E 7', (string) $history->note);
        $this->assertSame(2020, (int) $vehicle->fresh()->year, 'the vehicle record is unchanged');
        $this->assertSame(0, UnitMasterVehicleChange::query()->whereIn('field', ['make', 'model', 'year', 'serial_number', 'veh_number'])->where('unit_master_vehicle_id', $vehicle->id)->count(), 'nothing was rewritten');
        $this->assertNotContains($vehicle->id, $this->worklistIds());
    }

    public function test_a_designation_is_required_and_a_failed_create_leaves_nothing_behind(): void
    {
        $this->asFleetAdmin();
        $first = $this->reviewVehicle('V-501');
        $second = $this->reviewVehicle('V-502', ['serial_number' => '1FTTEST0000000502', 'tag_number' => 'XT0502']);
        $creator = app(MasterVehicleApparatusCreator::class);
        $creator->create($first, $this->superAdmin(), $this->details('E 7'));
        $apparatusCount = Apparatus::query()->count();

        foreach ([['designation' => '  '], ['designation' => 'e 7'], ['designation' => 'E 8', 'status' => 'Bogus'], ['designation' => 'E 8', 'station_id' => 99999]] as $bad) {
            try {
                $creator->create($second, $this->superAdmin(), $bad + $this->details('E 8'));
                $this->fail('Expected a validation error for '.json_encode($bad));
            } catch (ValidationException) {
                $this->assertSame($apparatusCount, Apparatus::query()->count());
                $this->assertNull($second->fresh()->apparatus);
            }
        }

        Livewire::test(ListUnitMasterVehicles::class)
            ->callTableAction('createApparatusRecord', $second, data: ['designation' => null] + $this->details())
            ->assertHasTableActionErrors(['designation' => 'required']);
    }

    public function test_a_vehicle_that_already_has_an_apparatus_or_is_marked_cannot_get_a_second_record(): void
    {
        $admin = $this->superAdmin();
        $creator = app(MasterVehicleApparatusCreator::class);
        $linked = $this->unit->fresh()->masterVehicle;

        try {
            $creator->create($linked, $admin, $this->details('E 9'));
            $this->fail('A linked vehicle already has an apparatus');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('record', $exception->errors());
        }

        $marked = $this->reviewVehicle('V-506', ['not_apparatus_at' => now()]);
        $this->expectException(ValidationException::class);
        $creator->create($marked, $admin, $this->details('E 9'));
    }

    public function test_creating_apparatus_needs_both_create_on_apparatus_and_update_on_the_vehicle(): void
    {
        $vehicle = $this->reviewVehicle();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $updateOnly = User::factory()->create(['account_status' => \App\Enums\AccountStatus::Active]);
        $updateOnly->givePermissionTo(Permission::findOrCreate('update_unit::master::vehicle', 'web'));
        $createOnly = User::factory()->create(['account_status' => \App\Enums\AccountStatus::Active]);
        $createOnly->givePermissionTo(Permission::findOrCreate('create_apparatus', 'web'));

        foreach ([$updateOnly, $createOnly, $this->fleetViewer()] as $user) {
            try {
                app(MasterVehicleApparatusCreator::class)->create($vehicle, $user->fresh(), $this->details());
                $this->fail('Expected an authorization failure');
            } catch (AuthorizationException) {
                $this->assertSame(0, Apparatus::query()->where('designation', 'E 7')->count());
            }
        }

        $this->actingAsCanonicalUser($this->fleetViewer());
        Livewire::test(ListUnitMasterVehicles::class)
            ->assertTableActionHidden('createApparatusRecord', $vehicle)
            ->assertTableActionHidden('markNotApparatus', $vehicle);
    }

    public function test_not_an_apparatus_is_audited_leaves_the_worklist_and_the_command_output_and_can_be_undone(): void
    {
        $admin = $this->asFleetAdmin();
        $vehicle = $this->reviewVehicle();
        $this->assertContains($vehicle->id, $this->worklistIds());
        $this->assertContains($vehicle->id, $this->commandMasterOnlyIds());

        Livewire::test(ListUnitMasterVehicles::class)
            ->callTableAction('markNotApparatus', $vehicle, data: ['note' => 'Chief staff car'])
            ->assertHasNoTableActionErrors();

        $marked = $vehicle->fresh();
        $this->assertTrue($marked->isMarkedNotApparatus());
        $this->assertSame([$admin->id, 'Chief staff car'], [$marked->not_apparatus_by_user_id, $marked->not_apparatus_note]);
        $history = UnitMasterVehicleChange::query()->where('unit_master_vehicle_id', $vehicle->id)->where('field', 'record')->sole();
        $this->assertSame(['not an apparatus', 'master_inventory', $admin->id, 'Chief staff car'], [$history->new_value, $history->source, $history->user_id, $history->note]);
        $this->assertNotContains($vehicle->id, $this->worklistIds());
        $this->assertNotContains($vehicle->id, $this->commandMasterOnlyIds());
        $this->assertSame(0, Apparatus::query()->where('unit_master_vehicle_id', $vehicle->id)->count(), 'no apparatus is ever created by this');

        Livewire::test(ListUnitMasterVehicles::class)
            ->assertTableActionHidden('markNotApparatus', $marked)
            ->assertTableActionHidden('createApparatusRecord', $marked)
            ->assertTableActionVisible('undoNotApparatus', $marked)
            ->callTableAction('undoNotApparatus', $marked);

        $this->assertFalse($vehicle->fresh()->isMarkedNotApparatus());
        $this->assertContains($vehicle->id, $this->worklistIds());
        $this->assertContains($vehicle->id, $this->commandMasterOnlyIds());
        $this->assertSame(['not an apparatus', 'back in apparatus review'], UnitMasterVehicleChange::query()->where('unit_master_vehicle_id', $vehicle->id)->where('field', 'record')->orderBy('id')->pluck('new_value')->all());
    }

    public function test_marking_twice_or_undoing_when_not_marked_changes_nothing(): void
    {
        $admin = $this->superAdmin();
        $vehicle = $this->reviewVehicle();
        $review = app(MasterVehicleApparatusReview::class);

        $review->undo($vehicle, $admin);
        $review->markNotApparatus($vehicle, $admin, 'Staff');
        $review->markNotApparatus($vehicle->fresh(), $admin, 'Again');

        $this->assertSame(1, UnitMasterVehicleChange::query()->where('unit_master_vehicle_id', $vehicle->id)->where('field', 'record')->count());
        $this->assertSame('Staff', $vehicle->fresh()->not_apparatus_note);
    }

    public function test_a_vehicle_that_is_an_apparatus_cannot_be_marked_and_a_viewer_cannot_mark(): void
    {
        $review = app(MasterVehicleApparatusReview::class);
        try {
            $review->markNotApparatus($this->unit->fresh()->masterVehicle, $this->superAdmin());
            $this->fail('A linked vehicle is an apparatus');
        } catch (ValidationException) {
            $this->assertFalse($this->unit->fresh()->masterVehicle->isMarkedNotApparatus());
        }

        $this->expectException(AuthorizationException::class);
        $review->markNotApparatus($this->reviewVehicle(), $this->fleetViewer());
    }

    public function test_the_vehicle_profile_offers_the_same_decisions(): void
    {
        $this->asFleetAdmin();
        $vehicle = $this->reviewVehicle();

        Livewire::test(ViewUnitMasterVehicle::class, ['record' => $vehicle->getKey()])
            ->assertActionVisible('createApparatusRecord')
            ->assertActionVisible('markNotApparatus')
            ->assertActionHidden('undoNotApparatus')
            ->callAction('markNotApparatus', ['note' => null]);

        $this->assertTrue($vehicle->fresh()->isMarkedNotApparatus());
        Livewire::test(ViewUnitMasterVehicle::class, ['record' => $vehicle->getKey()])
            ->assertActionHidden('markNotApparatus')
            ->assertActionVisible('undoNotApparatus');
    }

    public function test_a_linked_vehicle_is_offered_neither_decision(): void
    {
        $this->asFleetAdmin();
        $linked = $this->unit->fresh()->masterVehicle;

        Livewire::test(ListUnitMasterVehicles::class)
            ->assertTableActionHidden('createApparatusRecord', $linked)
            ->assertTableActionHidden('markNotApparatus', $linked);
    }

    public function test_the_suggested_type_follows_the_class_description(): void
    {
        $this->assertSame('Engine', MasterVehicleApparatusCreator::suggestedType('ENGINE, PUMPER'));
        $this->assertSame('Rescue', MasterVehicleApparatusCreator::suggestedType('Rescue'));
        $this->assertSame('Ladder', MasterVehicleApparatusCreator::suggestedType('AERIAL LADDER'));
        $this->assertSame('Pickup', MasterVehicleApparatusCreator::suggestedType('PICKUP TRUCK'));
        $this->assertSame('Other', MasterVehicleApparatusCreator::suggestedType(null));
    }

    /** @return list<int> master ids in the "master only" section of the reconciliation report */
    private function commandMasterOnlyIds(): array
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'master-only-'.uniqid('', true).'.json';
        $this->artisan('fleet:link-master-vehicles', ['--dry-run' => true, '--json' => $path])->assertSuccessful();
        $report = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        @unlink($path);

        return array_map('intval', array_column($report['master_only'], 'master_id'));
    }
}
