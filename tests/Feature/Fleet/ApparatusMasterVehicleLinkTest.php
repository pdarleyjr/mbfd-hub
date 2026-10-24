<?php

declare(strict_types=1);

namespace Tests\Feature\Fleet;

use App\Filament\Resources\ApparatusResource\Pages\CreateApparatus;
use App\Filament\Resources\ApparatusResource\Pages\EditApparatus;
use App\Filament\Resources\UnitMasterVehicleResource\Pages\EditUnitMasterVehicle;
use App\Jobs\SyncApparatusToSheetJob;
use App\Models\Apparatus;
use App\Models\UnitMasterVehicle;
use App\Models\UnitMasterVehicleChange;
use App\Models\User;
use App\Services\Fleet\ApparatusMasterVehicleLink;
use App\Services\Fleet\MasterLinkResolution;
use App\Services\Fleet\UnitMasterVehicleChangeRecorder;
use App\Support\Fleet\MasterVehicleSyncGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use RuntimeException;
use Tests\Feature\Filament\StationAdminFixtures;
use Tests\Feature\Fleet\Concerns\BuildsMasterLinkFixtures;
use Tests\TestCase;

final class ApparatusMasterVehicleLinkTest extends TestCase
{
    use BuildsMasterLinkFixtures;
    use RefreshDatabase;
    use StationAdminFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpStationAdmin();
        config()->set('google_sheets.apparatus_sync_enabled', false);
    }

    private function service(): ApparatusMasterVehicleLink
    {
        return app(ApparatusMasterVehicleLink::class);
    }

    // ---- resolve ----------------------------------------------------------------

    public function test_exact_match_resolves_ignoring_case_and_spacing(): void
    {
        $master = $this->master('R 3 REPLACEMENT');
        $apparatus = $this->legacyApparatus('R3R', '  r 3   replacement ');

        $resolution = $this->service()->resolve($apparatus);

        $this->assertTrue($resolution->isLinked());
        $this->assertSame($master->id, $resolution->master->id);
    }

    public function test_zero_padded_and_dashed_numbers_never_match_each_other(): void
    {
        $this->master('2-16');
        $apparatus = $this->legacyApparatus('E21', '002-16');

        $resolution = $this->service()->resolve($apparatus);

        $this->assertSame(MasterLinkResolution::CODE_NOT_FOUND, $resolution->code);
        $this->assertSame('No master vehicle has number 002-16.', $resolution->reason);
    }

    public function test_blank_number_needs_review(): void
    {
        $resolution = $this->service()->resolve($this->legacyApparatus('FB6', null));

        $this->assertSame('No vehicle number on this apparatus.', $resolution->reason);
    }

    public function test_duplicate_master_numbers_need_review(): void
    {
        $this->master('1031');
        $this->master(' 1031 ');
        $apparatus = $this->legacyApparatus('X1', '1031');

        $resolution = $this->service()->resolve($apparatus);

        $this->assertSame(MasterLinkResolution::CODE_AMBIGUOUS, $resolution->code);
        $this->assertSame('2 master vehicles share number 1031.', $resolution->reason);
    }

    public function test_vin_conflict_needs_review_and_nothing_is_linked(): void
    {
        // Captain 5 shape: same vehicle number, a different truck (different VIN).
        $master = $this->master('22530', ['year' => '2022', 'make' => 'Ford', 'model' => 'F-250', 'serial_number' => 'TESTVIN000000530']);
        $apparatus = $this->legacyApparatus('Captain 5', '22530', ['year' => 2024, 'make' => 'Former make', 'model' => 'Former model', 'vin' => 'TESTVIN000000631']);

        $result = $this->service()->reconcile($apparatus);

        $this->assertSame(ApparatusMasterVehicleLink::OUTCOME_NEEDS_REVIEW, $result['outcome']);
        $this->assertSame('VIN on this apparatus (TESTVIN000000631) does not match master vehicle 22530 (TESTVIN000000530).', $result['reason']);
        $fresh = $apparatus->fresh();
        $this->assertNull($fresh->unit_master_vehicle_id);
        $this->assertSame('needs_review', $fresh->master_link_status);
        $this->assertSame($result['reason'], $fresh->master_link_note);
        $this->assertSame(2024, $fresh->year);
        $this->assertSame('Ford', $master->fresh()->make);
        $this->assertSame(0, $this->changesFor($master)->count());
    }

    public function test_vin_comparison_ignores_spaces_dashes_and_case(): void
    {
        $this->master('16507', ['serial_number' => 'testvin-0000 00002']);
        $apparatus = $this->legacyApparatus('R2', '16507', ['vin' => 'TESTVIN000000002']);

        $this->assertTrue($this->service()->resolve($apparatus)->isLinked());
    }

    public function test_master_already_linked_to_another_apparatus_needs_review(): void
    {
        $master = $this->master('1033');
        $holder = $this->legacyApparatus('R-1034', '1033');
        $second = $this->legacyApparatus('R-1033', '1033');
        $this->service()->link($holder, $master, null);

        $resolution = $this->service()->resolve($second);

        $this->assertSame(MasterLinkResolution::CODE_ALREADY_LINKED, $resolution->code);
        $this->assertSame('Master vehicle 1033 is already linked to R-1034.', $resolution->reason);
        $this->assertTrue($this->service()->resolve($holder->fresh())->isLinked(), 'the linked row still resolves to its own master');
    }

    // ---- link -------------------------------------------------------------------

    public function test_link_aligns_blanks_and_year_drift_and_logs_each_change(): void
    {
        $actor = User::factory()->create();
        $master = $this->master('16507', ['year' => '2016', 'make' => 'FREIGHTLINER', 'model' => 'M2', 'serial_number' => 'TESTVIN000000002']);
        $apparatus = $this->legacyApparatus('R2', '16507', ['year' => 2015, 'make' => null, 'model' => null, 'vin' => null]);

        $changes = $this->service()->link($apparatus, $master, $actor, 'apparatus_link');

        $fresh = $apparatus->fresh();
        $this->assertSame($master->id, $fresh->unit_master_vehicle_id);
        $this->assertSame('linked', $fresh->master_link_status);
        $this->assertNull($fresh->master_link_note);
        $this->assertSame([2016, 'FREIGHTLINER', 'M2', 'TESTVIN000000002', '16507'], [$fresh->year, $fresh->make, $fresh->model, $fresh->vin, $fresh->vehicle_number]);
        $this->assertCount(4, $changes);

        $rows = $this->changesFor($master, 'apparatus_link')->keyBy('field');
        $this->assertSame(['make', 'model', 'serial_number', 'year'], $rows->keys()->sort()->values()->all());
        $year = $rows['year'];
        $this->assertSame(['2015', '2016', 'applied', $actor->id], [$year->old_value, $year->new_value, $year->status, $year->user_id]);
        $this->assertSame('Apparatus R2 copy aligned to the master record', $year->note);
        $this->assertNull($rows['make']->old_value);
        $this->assertSame('FREIGHTLINER', $rows['make']->new_value);
        $this->assertSame('2016', $master->fresh()->year, 'the master is never changed where it has a value');
    }

    public function test_link_fills_a_blank_master_field_from_the_apparatus_through_the_recorder(): void
    {
        $master = $this->master('20503', ['make' => 'PIERCE', 'year' => '2021']);
        $apparatus = $this->legacyApparatus('E1', '20503', ['model' => 'Velocity', 'vin' => 'TESTVIN000000001']);

        $this->service()->link($apparatus, $master, null);

        $fresh = $master->fresh();
        $this->assertSame('Velocity', $fresh->model);
        $this->assertSame('TESTVIN000000001', $fresh->serial_number);
        $rows = $this->changesFor($master, 'apparatus_link')->keyBy('field');
        // model and VIN were blank on the master (filled from the apparatus); make and year were blank on the apparatus (aligned to the master).
        $this->assertSame(['make', 'model', 'serial_number', 'year'], $rows->keys()->sort()->values()->all());
        $this->assertNull($rows['model']->old_value);
        $this->assertSame('Velocity', $rows['model']->new_value);
        $this->assertSame('applied', $rows['model']->status);
        $this->assertNull($rows['serial_number']->old_value);
        $this->assertSame('TESTVIN000000001', $rows['serial_number']->new_value);
        $this->assertSame(['PIERCE', 2021], [$apparatus->fresh()->make, $apparatus->fresh()->year]);
    }

    public function test_link_is_idempotent(): void
    {
        $master = $this->master('20503', ['make' => 'PIERCE']);
        $apparatus = $this->legacyApparatus('E1', '20503');
        $this->service()->link($apparatus, $master, null);
        $rows = $this->changesFor($master)->count();

        $again = $this->service()->link($apparatus->fresh(), $master, null);

        $this->assertSame([], $again);
        $this->assertSame($rows, $this->changesFor($master)->count());
    }

    // ---- master edit -> apparatus ----------------------------------------------

    public function test_master_edit_page_propagates_to_the_apparatus_with_one_history_row_per_field(): void
    {
        $master = $this->master('20503', ['make' => 'PIERCE', 'model' => 'VELOCITY', 'year' => '2021', 'serial_number' => 'TESTVIN000000001']);
        $apparatus = $this->liveApparatus('E1', '20503');
        $this->assertSame($master->id, $apparatus->fresh()->unit_master_vehicle_id);
        $before = $this->changesFor($master)->count();
        $actor = $this->actingAsSuperAdmin();

        Livewire::test(EditUnitMasterVehicle::class, ['record' => $master->getKey()])
            ->fillForm([
                'veh_number' => '20503A', 'make' => 'PIERCE MFG', 'model' => 'VELOCITY 2',
                'year' => '2022', 'serial_number' => 'TESTVIN000000009',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $fresh = $apparatus->fresh();
        $this->assertSame(['20503A', 'PIERCE MFG', 'VELOCITY 2', 2022, 'TESTVIN000000009'], [$fresh->vehicle_number, $fresh->make, $fresh->model, $fresh->year, $fresh->vin]);
        $this->assertSame($master->id, $fresh->unit_master_vehicle_id);
        $this->assertSame('linked', $fresh->master_link_status);

        $new = $this->changesFor($master)->slice($before);
        $this->assertCount(5, $new, 'one row per changed field, nothing bounced back');
        $this->assertSame(['master_inventory'], $new->pluck('source')->unique()->values()->all());
        $this->assertSame([$actor->id], $new->pluck('user_id')->unique()->values()->all());
        $this->assertSame(['make', 'model', 'serial_number', 'veh_number', 'year'], $new->pluck('field')->sort()->values()->all());
        $make = $new->firstWhere('field', 'make');
        $this->assertSame(['PIERCE', 'PIERCE MFG', 'applied'], [$make->old_value, $make->new_value, $make->status]);
    }

    public function test_recorder_change_is_logged_once_and_still_reaches_the_apparatus(): void
    {
        $master = $this->master('20503', ['make' => 'PIERCE']);
        $apparatus = $this->liveApparatus('E1', '20503');
        $before = $this->changesFor($master)->count();

        app(UnitMasterVehicleChangeRecorder::class)->apply($master->fresh(), 'make', 'KME', 'fy27_reconciliation');

        $new = $this->changesFor($master)->slice($before);
        $this->assertCount(1, $new);
        $this->assertSame('fy27_reconciliation', $new->first()->source);
        $this->assertSame('KME', $apparatus->fresh()->make);
    }

    public function test_master_edit_runs_the_apparatus_observer_so_the_sheet_sync_is_queued(): void
    {
        $master = $this->master('20503');
        $apparatus = $this->liveApparatus('E1', '20503');
        config()->set('google_sheets.apparatus_sync_enabled', true);
        Queue::fake();

        $master->update(['veh_number' => '20504']);

        $this->assertSame('20504', $apparatus->fresh()->vehicle_number);
        Queue::assertPushed(SyncApparatusToSheetJob::class);
    }

    public function test_clearing_a_master_vehicle_number_flags_the_apparatus_for_review(): void
    {
        $master = $this->master('20503');
        $apparatus = $this->liveApparatus('E1', '20503');

        $master->update(['veh_number' => null]);

        $fresh = $apparatus->fresh();
        $this->assertSame('20503', $fresh->vehicle_number);
        $this->assertNull($fresh->unit_master_vehicle_id);
        $this->assertSame('Master vehicle record no longer has a vehicle number.', $fresh->master_link_note);
    }

    public function test_a_linked_master_record_cannot_be_deleted(): void
    {
        $master = $this->master('20503');
        $apparatus = $this->liveApparatus('E1', '20503');

        try {
            $master->delete();
            $this->fail('A master vehicle that is a fire apparatus must not be deleted.');
        } catch (\LogicException) {
        }

        $this->assertNotNull($master->fresh());
        $this->assertSame($master->id, $apparatus->fresh()->unit_master_vehicle_id);
        $this->assertSame('linked', $apparatus->fresh()->master_link_status);
    }

    // ---- apparatus edit -> master ----------------------------------------------

    public function test_apparatus_edit_page_writes_make_and_year_through_to_the_master(): void
    {
        $master = $this->master('20503', ['make' => 'PIERCE', 'model' => 'VELOCITY', 'year' => '2021']);
        $apparatus = $this->liveApparatus('E1', '20503');
        $before = $this->changesFor($master)->count();
        $actor = $this->actingAsSuperAdmin();

        Livewire::test(EditApparatus::class, ['record' => $apparatus->getKey()])
            ->fillForm(['make' => 'KME', 'year' => 2023])
            ->call('save')
            ->assertHasNoFormErrors();

        $fresh = $master->fresh();
        $this->assertSame(['KME', '2023', 'VELOCITY'], [$fresh->make, $fresh->year, $fresh->model]);
        $new = $this->changesFor($master)->slice($before);
        $this->assertCount(2, $new);
        $this->assertSame(['apparatus_page'], $new->pluck('source')->unique()->values()->all());
        $this->assertSame([$actor->id], $new->pluck('user_id')->unique()->values()->all());
        $year = $new->firstWhere('field', 'year');
        $this->assertSame(['2021', '2023', 'applied'], [$year->old_value, $year->new_value, $year->status]);
        $this->assertSame([2023, 'KME'], [$apparatus->fresh()->year, $apparatus->fresh()->make]);
    }

    public function test_changing_the_apparatus_vehicle_number_relinks_and_never_renames_a_master(): void
    {
        $first = $this->master('1031', ['make' => 'AAA', 'year' => '2005']);
        $second = $this->master('1032', ['make' => 'BBB', 'year' => '2010']);
        $apparatus = $this->liveApparatus('X1', '1031');
        $this->assertSame($first->id, $apparatus->fresh()->unit_master_vehicle_id);
        $this->actingAsSuperAdmin();

        // On the Admin form a linked unit's number is set by "Link vehicle record"
        // (ApparatusFleetWorkspaceTest); any other writer that changes it relinks.
        $apparatus->fresh()->update(['vehicle_number' => '1032']);

        $fresh = $apparatus->fresh();
        $this->assertSame($second->id, $fresh->unit_master_vehicle_id);
        $this->assertSame(['1032', 'BBB', 2010], [$fresh->vehicle_number, $fresh->make, $fresh->year], 'identity is pulled from the new master');
        $this->assertSame(['1031', 'AAA', '2005'], [$first->fresh()->veh_number, $first->fresh()->make, $first->fresh()->year]);
        $this->assertNull($first->apparatus()->first());

        $apparatus->fresh()->update(['vehicle_number' => '9999']);

        $fresh = $apparatus->fresh();
        $this->assertNull($fresh->unit_master_vehicle_id);
        $this->assertSame('needs_review', $fresh->master_link_status);
        $this->assertSame('No master vehicle has number 9999.', $fresh->master_link_note);
        $this->assertSame('1032', $second->fresh()->veh_number);
        $this->assertSame(2, UnitMasterVehicle::query()->count());
    }

    public function test_editing_non_shared_apparatus_fields_writes_nothing_to_the_master(): void
    {
        $master = $this->master('20503', ['make' => 'PIERCE']);
        $apparatus = $this->liveApparatus('E1', '20503');
        $before = $this->changesFor($master)->count();

        $apparatus->update(['status' => 'Maintenance', 'notes' => 'Pump service']);

        $this->assertSame($before, $this->changesFor($master)->count());
    }

    // ---- new apparatus ----------------------------------------------------------

    public function test_new_apparatus_with_an_unknown_number_creates_a_flagged_master_and_links_it(): void
    {
        $actor = User::factory()->create();
        $this->actingAs($actor);

        $apparatus = $this->liveApparatus('E 9', 'ZZ-V1', ['make' => 'Pierce', 'model' => 'Enforcer', 'year' => 2024, 'vin' => 'TESTVIN000000009']);

        $master = UnitMasterVehicle::query()->where('veh_number', 'ZZ-V1')->sole();
        $this->assertSame(['Pierce', 'Enforcer', '2024', 'TESTVIN000000009', 'vehicle', 'apparatus_page', true], [
            $master->make, $master->model, $master->year, $master->serial_number, $master->asset_category, $master->record_source, $master->needs_fleet_review,
        ]);
        $fresh = $apparatus->fresh();
        $this->assertSame([$master->id, 'linked', null], [$fresh->unit_master_vehicle_id, $fresh->master_link_status, $fresh->master_link_note]);
        $created = $this->changesFor($master)->sole();
        $this->assertSame(['record', 'created', 'apparatus_page', 'applied', $actor->id], [$created->field, $created->new_value, $created->source, $created->status, $created->user_id]);
    }

    public function test_creating_an_apparatus_in_the_admin_form_creates_and_links_the_master(): void
    {
        $this->actingAsSuperAdmin();

        Livewire::test(CreateApparatus::class)
            ->fillForm([
                'designation' => 'E 9', 'unit_id' => 'E9', 'vehicle_number' => '30999', 'make' => 'Pierce', 'year' => 2024, 'mileage' => 0,
                'daily_checkout_requirement' => 'required', 'daily_checkout_template' => 'engine',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $apparatus = Apparatus::query()->where('vehicle_number', '30999')->sole();
        $master = UnitMasterVehicle::query()->where('veh_number', '30999')->sole();
        $this->assertSame($master->id, $apparatus->unit_master_vehicle_id);
        $this->assertTrue($master->needs_fleet_review);
    }

    public function test_new_apparatus_with_one_matching_master_links_and_pulls_its_identity(): void
    {
        $master = $this->master('20503', ['make' => 'PIERCE', 'year' => '2021', 'serial_number' => 'TESTVIN000000001']);

        $apparatus = $this->liveApparatus('E1', '20503');

        $fresh = $apparatus->fresh();
        $this->assertSame([$master->id, 'PIERCE', 2021, 'TESTVIN000000001'], [$fresh->unit_master_vehicle_id, $fresh->make, $fresh->year, $fresh->vin]);
        $this->assertSame(1, UnitMasterVehicle::query()->count());
    }

    public function test_new_apparatus_with_ambiguous_conflicting_or_blank_numbers_goes_to_review(): void
    {
        $this->master('1031');
        $this->master('1031');
        $this->master('22530', ['serial_number' => 'TESTVIN000000530']);

        $ambiguous = $this->liveApparatus('X1', '1031');
        $conflict = $this->liveApparatus('Captain 5', '22530', ['vin' => 'TESTVIN000000631']);
        $blank = $this->liveApparatus('FB6', null);

        foreach ([$ambiguous, $conflict, $blank] as $row) {
            $fresh = $row->fresh();
            $this->assertNull($fresh->unit_master_vehicle_id);
            $this->assertSame('needs_review', $fresh->master_link_status);
        }
        $this->assertSame('2 master vehicles share number 1031.', $ambiguous->fresh()->master_link_note);
        $this->assertSame('No vehicle number on this apparatus.', $blank->fresh()->master_link_note);
        $this->assertSame(3, UnitMasterVehicle::query()->count(), 'no master row is created for an ambiguous or blank number');
    }

    public function test_the_pm_backfill_does_not_overwrite_identity_owned_by_a_linked_master(): void
    {
        $master = $this->master('20503', ['make' => 'PIERCE', 'model' => 'VELOCITY', 'year' => '2021']);
        $apparatus = $this->liveApparatus('E1', '20503');
        $before = $this->changesFor($master)->count();

        $this->artisan('apparatus:backfill-pm-data')->assertExitCode(0);

        $fresh = $apparatus->fresh();
        $this->assertSame(['20503', 'PIERCE', 'VELOCITY', 2021, $master->id], [$fresh->vehicle_number, $fresh->make, $fresh->model, $fresh->year, $fresh->unit_master_vehicle_id]);
        $this->assertSame(27836, $fresh->current_miles, 'the meter data it exists for is still written');
        $this->assertSame($before, $this->changesFor($master)->count());
    }

    // ---- atomicity --------------------------------------------------------------

    public function test_if_the_master_write_fails_the_apparatus_edit_is_rolled_back(): void
    {
        $master = $this->master('20503', ['make' => 'PIERCE', 'year' => '2021']);
        $apparatus = $this->liveApparatus('E1', '20503');
        $this->actingAsSuperAdmin();
        UnitMasterVehicleChange::creating(static function (UnitMasterVehicleChange $change): void {
            if ($change->source === 'apparatus_page') {
                throw new RuntimeException('simulated history failure');
            }
        });

        try {
            Livewire::test(EditApparatus::class, ['record' => $apparatus->getKey()])
                ->fillForm(['make' => 'KME'])
                ->call('save');
            $this->fail('The simulated failure should have surfaced.');
        } catch (RuntimeException $exception) {
            $this->assertSame('simulated history failure', $exception->getMessage());
        }

        $this->assertSame('PIERCE', $master->fresh()->make);
        $this->assertSame('PIERCE', $apparatus->fresh()->make, 'the apparatus row did not change either');
        $this->assertFalse(MasterVehicleSyncGuard::isPropagating());
        $this->assertFalse(MasterVehicleSyncGuard::isRecording());
    }

    public function test_if_the_apparatus_write_fails_the_master_edit_and_its_history_are_rolled_back(): void
    {
        $master = $this->master('20503', ['make' => 'PIERCE']);
        $apparatus = $this->liveApparatus('E1', '20503');
        $before = $this->changesFor($master)->count();
        $this->actingAsSuperAdmin();
        Apparatus::updating(static function (): void {
            throw new RuntimeException('simulated apparatus failure');
        });

        try {
            Livewire::test(EditUnitMasterVehicle::class, ['record' => $master->getKey()])
                ->fillForm(['make' => 'KME'])
                ->call('save');
            $this->fail('The simulated failure should have surfaced.');
        } catch (RuntimeException $exception) {
            $this->assertSame('simulated apparatus failure', $exception->getMessage());
        }

        $this->assertSame('PIERCE', $master->fresh()->make);
        $this->assertSame('PIERCE', $apparatus->fresh()->make);
        $this->assertSame($before, $this->changesFor($master)->count(), 'no history row survives the rollback');
    }
}
