<?php

declare(strict_types=1);

namespace Tests\Feature\Fleet;

use App\Filament\Resources\ApparatusResource\Pages\ViewApparatus;
use App\Filament\Resources\ApparatusResource\RelationManagers\VehicleSwapReportsRelationManager;
use App\Filament\Resources\VehicleSwapRequestResource;
use App\Filament\Resources\VehicleSwapRequestResource\Pages\ListVehicleSwapRequests;
use App\Models\Apparatus;
use App\Models\ApparatusDefect;
use App\Models\ApparatusInspection;
use App\Models\ApparatusInspectionException;
use App\Models\ApparatusInspectionReviewEvent;
use App\Models\ApparatusOperationalStatusEvent;
use App\Models\User;
use App\Services\Fleet\VehicleSwapDecisionException;
use App\Services\Fleet\VehicleSwapService;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Fleet\Concerns\BuildsVehicleSwapFixtures;
use Tests\TestCase;

/**
 * An apparatus row is the physical truck. A decision moves the unit designation
 * (with its station and frontline role) between truck rows; vehicle number,
 * VIN, meters, defects and tickets never move.
 */
final class VehicleSwapDecisionTest extends TestCase
{
    use BuildsVehicleSwapFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSwapFixtures();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function service(): VehicleSwapService
    {
        return app(VehicleSwapService::class);
    }

    /** @return array<string, mixed> */
    private function truck(Apparatus $row): array
    {
        $fresh = $row->fresh();

        return [
            'vehicle_number' => $fresh->vehicle_number, 'vin' => $fresh->vin,
            'hours' => $fresh->current_engine_hours, 'miles' => $fresh->current_miles,
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private function trucksBefore(): array
    {
        return ['unit' => $this->truck($this->unit), 'frontline' => $this->truck($this->frontline), 'reserve' => $this->truck($this->reserve)];
    }

    /** Vehicle number, VIN and meters of every truck are untouched by a decision. */
    private function assertTrucksKeepTheirIdentity(array $before): void
    {
        foreach (['unit' => $this->unit, 'frontline' => $this->frontline, 'reserve' => $this->reserve] as $key => $row) {
            $now = $this->truck($row);
            $this->assertSame($before[$key]['vehicle_number'], $now['vehicle_number'], "{$key} vehicle number");
            $this->assertSame($before[$key]['vin'], $now['vin'], "{$key} VIN");
            $this->assertSame($before[$key]['hours'], $now['hours'], "{$key} engine hours");
            $this->assertSame($before[$key]['miles'], $now['miles'], "{$key} miles");
        }
    }

    /** @return list<mixed> */
    private function identityOf(Apparatus $row): array
    {
        $fresh = $row->fresh();

        return [$fresh->designation, $fresh->unit_id, $fresh->slug, $fresh->station_id, $fresh->getRawOriginal('daily_checkout_requirement')];
    }

    public function test_reject_changes_nothing_and_applies_the_findings_to_the_assigned_truck(): void
    {
        $swap = $this->heldRequest();
        $manager = $this->fleetOperationsManager();

        $decided = $this->service()->decide($swap->id, $manager, VehicleSwapService::DECISION_REJECT, 'Not a swap.');

        $this->assertSame('rejected', $decided->status);
        $this->assertSame('E2', $this->unit->fresh()->designation);
        $this->assertSame('E3', $this->frontline->fresh()->designation);
        $inspection = ApparatusInspection::query()->sole();
        $this->assertSame('approved', $inspection->review_status);
        $this->assertSame('accepted', $inspection->processing_status);
        $this->assertNull($inspection->pending_effects);
        $this->assertSame($this->unit->id, ApparatusDefect::query()->sole()->apparatus_id);
        $this->assertSame($this->unit->id, $decided->confirmed_apparatus_id);
        $this->assertSame('401.2', $this->unit->fresh()->current_engine_hours);
        $this->assertSame(1020, $this->unit->fresh()->current_miles);
        $this->assertSame('900.0', $this->frontline->fresh()->current_engine_hours);
        $this->assertSame('VIN-202', $this->unit->fresh()->vin);
        $this->assertSame($manager->id, $decided->decided_by_user_id);
        $this->assertSame('Not a swap.', $decided->decision_note);
        $this->assertSame($manager->id, ApparatusInspectionReviewEvent::query()->where('status', 'swap_rejected')->sole()->changed_by_user_id);
        $this->assertSame('E2', $inspection->designation_at_time);
        $this->assertSame('V-202', $inspection->vehicle_number);
    }

    public function test_approve_swap_moves_the_unit_designation_station_and_role_between_the_truck_rows(): void
    {
        $swap = $this->heldRequest();
        $manager = $this->fleetOperationsManager();
        $trucks = $this->trucksBefore();
        $station2 = $this->unit->station_id;
        $station3 = $this->frontline->station_id;

        $decided = $this->service()->decide($swap->id, $manager, VehicleSwapService::DECISION_APPROVE_SWAP);

        $this->assertSame('approved_swap', $decided->status);
        // The reported truck (row V-303) now is E2 at Station 2; the old truck (row V-202) now is E3 at Station 3.
        $this->assertSame(['E2', 'E2', 'e2', $station2, 'required'], $this->identityOf($this->frontline));
        $this->assertSame(['E3', 'E3', 'e3', $station3, 'required'], $this->identityOf($this->unit));
        $this->assertSame('Engine 2', $this->frontline->fresh()->name);
        $this->assertSame('Engine 3', $this->unit->fresh()->name);
        $this->assertSame('In Service', $this->frontline->fresh()->status);
        $this->assertSame('In Service', $this->unit->fresh()->status);
        // Trucks keep their identity; the confirmed truck only gains what the checkout reported.
        $this->assertSame($trucks['unit'], $this->truck($this->unit));
        $this->assertSame($trucks['reserve'], $this->truck($this->reserve));
        $this->assertSame('V-303', $this->frontline->fresh()->vehicle_number);
        $this->assertSame('VIN-303', $this->frontline->fresh()->vin);
        $this->assertSame('900.0', $this->frontline->fresh()->current_engine_hours, 'A reading below the truck own meter is flagged, not applied.');
        // The findings land on the confirmed physical truck, not the row the checkout was started under.
        $this->assertSame($this->frontline->id, $decided->confirmed_apparatus_id);
        $this->assertSame($this->frontline->id, ApparatusDefect::query()->sole()->apparatus_id);
        $this->assertSame($this->frontline->id, ApparatusInspectionException::query()->first()->apparatus_id);
        $inspection = ApparatusInspection::query()->sole();
        $this->assertSame($this->unit->id, $inspection->apparatus_id, 'The inspection keeps its original snapshot.');
        $this->assertSame('E2', $inspection->designation_at_time);
        $this->assertSame('V-202', $inspection->vehicle_number);
        // Audit: designation, station and status for both rows, before and after.
        $audit = $decided->decision_audit;
        $this->assertSame(['E2', $station2, 'In Service'], [$audit['before']['assigned_truck']['designation'], $audit['before']['assigned_truck']['station_id'], $audit['before']['assigned_truck']['status']]);
        $this->assertSame(['E3', $station3, 'In Service'], [$audit['before']['reported_truck']['designation'], $audit['before']['reported_truck']['station_id'], $audit['before']['reported_truck']['status']]);
        $this->assertSame(['E3', $station3], [$audit['after']['assigned_truck']['designation'], $audit['after']['assigned_truck']['station_id']]);
        $this->assertSame(['E2', $station2], [$audit['after']['reported_truck']['designation'], $audit['after']['reported_truck']['station_id']]);
        $this->assertSame('V-202', $audit['after']['assigned_truck']['vehicle_number']);
        $this->assertSame('V-303', $audit['after']['reported_truck']['vehicle_number']);
        $this->assertSame($this->frontline->id, $audit['findings_applied_to_apparatus_id']);
    }

    public function test_swapping_with_a_reserve_truck_trades_the_frontline_and_reserve_roles(): void
    {
        $swap = $this->heldRequest('V-909');
        $trucks = $this->trucksBefore();

        $decided = $this->service()->decide($swap->id, $this->fleetOperationsManager(), VehicleSwapService::DECISION_APPROVE_SWAP);

        $this->assertSame('approved_swap', $decided->status);
        $this->assertSame('E2', $this->reserve->fresh()->designation);
        $this->assertSame('In Service', $this->reserve->fresh()->status, 'The reserve truck took the frontline role.');
        $this->assertSame('RE1', $this->unit->fresh()->designation);
        $this->assertSame('Reserve', $this->unit->fresh()->status, 'The old truck took the reserve role.');
        $this->assertSame('V-909', $this->reserve->fresh()->vehicle_number);
        $this->assertSame('V-202', $this->unit->fresh()->vehicle_number);
        $this->assertSame($trucks['unit']['vin'], $this->truck($this->unit)['vin']);
        $this->assertSame($trucks['reserve']['vin'], $this->truck($this->reserve)['vin']);
        $ledger = ApparatusOperationalStatusEvent::query()->get(['apparatus_id', 'status']);
        $this->assertTrue($ledger->contains(fn ($event): bool => $event->apparatus_id === $this->reserve->id && $event->status === 'In Service'));
        $this->assertTrue($ledger->contains(fn ($event): bool => $event->apparatus_id === $this->unit->id && $event->status === 'Reserve'));
        $this->assertSame($this->reserve->id, ApparatusDefect::query()->sole()->apparatus_id);
    }

    #[DataProvider('demotions')]
    public function test_approve_replace_gives_the_unit_to_the_reported_truck_and_demotes_the_old_truck(string $demoteTo): void
    {
        $swap = $this->heldRequest('V-909');
        $trucks = $this->trucksBefore();
        $station2 = $this->unit->station_id;

        $decided = $this->service()->decide($swap->id, $this->fleetOperationsManager(), VehicleSwapService::DECISION_APPROVE_REPLACE, null, $demoteTo);

        $this->assertSame('approved_replace', $decided->status);
        $this->assertSame(['E2', 'E2', 'e2', $station2, 'required'], $this->identityOf($this->reserve));
        $this->assertSame('In Service', $this->reserve->fresh()->status);
        $old = $this->unit->fresh();
        $this->assertSame('Reserve V-202', $old->designation, 'The old truck loses the frontline designation.');
        $this->assertSame('reserve-'.$old->id, $old->slug);
        $this->assertSame('reserve', $old->getRawOriginal('daily_checkout_requirement'));
        $this->assertSame($demoteTo, $old->getAttribute('status'));
        $this->assertSame('Reserve (was E2)', $old->name);
        $this->assertSame($trucks['unit'], $this->truck($this->unit));
        $this->assertSame($trucks['frontline'], $this->truck($this->frontline));
        $this->assertSame($trucks['reserve']['vin'], $this->truck($this->reserve)['vin']);
        $this->assertSame('V-909', $this->reserve->fresh()->vehicle_number);
        $this->assertTrue(ApparatusOperationalStatusEvent::query()->where('apparatus_id', $this->unit->id)->where('status', $demoteTo)->exists(), 'The status ledger recorded the demotion.');
        $this->assertSame($this->reserve->id, ApparatusDefect::query()->sole()->apparatus_id);
        $this->assertSame($demoteTo, $decided->decision_audit['old_truck_demoted_to']);
        $this->assertSame('Reserve', $decided->decision_audit['before']['reported_truck']['status']);
        $this->assertSame($demoteTo, $decided->decision_audit['after']['assigned_truck']['status']);
    }

    /** @return array<string, array{string}> */
    public static function demotions(): array
    {
        return ['reserve' => ['Reserve'], 'out of service' => ['Out of Service']];
    }

    public function test_a_vehicle_with_no_truck_record_needs_the_admin_to_map_it_to_an_existing_record(): void
    {
        $swap = $this->heldRequest('X-77');
        $this->assertNull($swap->reported_apparatus_id);
        $manager = $this->fleetOperationsManager();
        $count = Apparatus::query()->count();

        try {
            $this->service()->decide($swap->id, $manager, VehicleSwapService::DECISION_APPROVE_SWAP);
            $this->fail('An unmapped vehicle cannot be approved.');
        } catch (VehicleSwapDecisionException $exception) {
            $this->assertStringContainsString('Choose the existing truck record', $exception->getMessage());
        }
        $this->assertSame('pending', $swap->fresh()->status);
        $this->assertSame('E2', $this->unit->fresh()->designation);
        $this->assertSame('pending_vehicle_swap', ApparatusInspection::query()->sole()->processing_status);
        $this->assertDatabaseCount('apparatus_defects', 0);

        $decided = $this->service()->decide($swap->id, $manager, VehicleSwapService::DECISION_APPROVE_REPLACE, null, 'Reserve', $this->reserve->id);

        $this->assertSame('approved_replace', $decided->status);
        $this->assertSame('E2', $this->reserve->fresh()->designation);
        $this->assertTrue($decided->decision_audit['mapped_by_admin']);
        $this->assertSame($count, Apparatus::query()->count(), 'No truck record is ever created.');
        $this->assertSame($this->reserve->id, ApparatusDefect::query()->sole()->apparatus_id);
    }

    public function test_an_unmapped_vehicle_can_still_be_rejected(): void
    {
        $swap = $this->heldRequest('X-77');

        $decided = $this->service()->decide($swap->id, $this->fleetOperationsManager(), VehicleSwapService::DECISION_REJECT);

        $this->assertSame('rejected', $decided->status);
        $this->assertSame($this->unit->id, ApparatusDefect::query()->sole()->apparatus_id);
    }

    public function test_the_unit_moving_since_the_report_blocks_approval_but_not_rejection(): void
    {
        $swap = $this->heldRequest();
        $this->unit->update(['designation' => 'E9', 'name' => 'Engine 9']);
        $manager = $this->fleetOperationsManager();

        try {
            $this->service()->decide($swap->id, $manager, VehicleSwapService::DECISION_APPROVE_SWAP);
            $this->fail('The report no longer matches the unit.');
        } catch (VehicleSwapDecisionException $exception) {
            $this->assertStringContainsString('moved to a different truck record', $exception->getMessage());
        }
        $this->assertSame('pending', $swap->fresh()->status);
        $this->assertSame('E3', $this->frontline->fresh()->designation);

        $this->assertSame('rejected', $this->service()->decide($swap->id, $manager, VehicleSwapService::DECISION_REJECT)->status);
    }

    public function test_deciding_twice_is_idempotent_and_never_swaps_back_or_applies_findings_twice(): void
    {
        $swap = $this->heldRequest();
        $manager = $this->fleetOperationsManager();

        $first = $this->service()->decide($swap->id, $manager, VehicleSwapService::DECISION_APPROVE_SWAP);
        $again = $this->service()->decide($swap->id, $manager, VehicleSwapService::DECISION_APPROVE_SWAP);

        $this->assertSame($first->decided_at?->toIso8601String(), $again->decided_at?->toIso8601String());
        $this->assertSame('E2', $this->frontline->fresh()->designation, 'A second swap would trade them back.');
        $this->assertSame('E3', $this->unit->fresh()->designation);
        $this->assertDatabaseCount('apparatus_defect_observations', 1);
        $this->assertSame(1, ApparatusInspectionReviewEvent::query()->where('status', 'swap_approved')->count());

        $this->expectException(VehicleSwapDecisionException::class);
        $this->service()->decide($swap->id, $manager, VehicleSwapService::DECISION_REJECT);
    }

    public function test_a_view_only_fleet_member_and_a_plain_member_are_refused_and_nothing_changes(): void
    {
        $swap = $this->heldRequest();

        foreach ([$this->fleetViewer(), $this->member] as $refused) {
            try {
                $this->service()->decide($swap->id, $refused, VehicleSwapService::DECISION_APPROVE_SWAP);
                $this->fail('Only Fleet manage access may decide a swap.');
            } catch (AuthorizationException) {
            }
        }

        $this->assertSame('pending', $swap->fresh()->status);
        $this->assertSame('E2', $this->unit->fresh()->designation);
        $this->assertSame('E3', $this->frontline->fresh()->designation);
        $this->assertSame('pending_vehicle_swap', ApparatusInspection::query()->sole()->processing_status);
        $this->assertDatabaseCount('apparatus_defects', 0);
    }

    public function test_a_super_administrator_may_decide(): void
    {
        $swap = $this->heldRequest();

        $this->assertSame('rejected', $this->service()->decide($swap->id, $this->superAdmin(), VehicleSwapService::DECISION_REJECT)->status);
    }

    public function test_the_queue_page_is_visible_to_fleet_staff_and_closed_to_members(): void
    {
        $swap = $this->heldRequest();

        $this->actingAs($this->member);
        $this->assertFalse(VehicleSwapRequestResource::canViewAny());

        $this->actingAs($this->fleetViewer());
        $this->assertTrue(VehicleSwapRequestResource::canViewAny());
        Livewire::test(ListVehicleSwapRequests::class)
            ->assertSuccessful()
            ->call('loadTable')
            ->assertCanSeeTableRecords([$swap])
            ->assertTableActionHidden('decideVehicleSwap', $swap);

        $this->actingAs($this->fleetOperationsManager());
        Livewire::test(ListVehicleSwapRequests::class)
            ->call('loadTable')
            ->assertCanSeeTableRecords([$swap])
            ->assertTableActionVisible('decideVehicleSwap', $swap);
    }

    public function test_the_decision_modal_records_the_choice_the_demotion_and_the_mapped_truck(): void
    {
        $swap = $this->heldRequest('X-77');
        $manager = $this->fleetOperationsManager();
        $this->actingAs($manager);

        Livewire::test(ListVehicleSwapRequests::class)
            ->call('loadTable')
            ->callTableAction('decideVehicleSwap', $swap, data: [
                'decision' => VehicleSwapService::DECISION_APPROVE_REPLACE,
                'demote_to' => 'Out of Service',
                'mapped_apparatus_id' => $this->reserve->id,
                'note' => 'Confirmed with the shift officer.',
            ])
            ->assertHasNoTableActionErrors();

        $this->assertSame('approved_replace', $swap->fresh()->status);
        $this->assertSame('E2', $this->reserve->fresh()->designation);
        $this->assertSame('Out of Service', $this->unit->fresh()->getAttribute('status'));
        $this->assertSame($this->reserve->id, $swap->fresh()->confirmed_apparatus_id);
        $this->assertSame($manager->id, $swap->fresh()->decided_by_user_id);
    }

    public function test_the_modal_refuses_an_unmapped_approval(): void
    {
        $swap = $this->heldRequest('X-77');
        $this->actingAs($this->fleetOperationsManager());

        Livewire::test(ListVehicleSwapRequests::class)
            ->call('loadTable')
            ->callTableAction('decideVehicleSwap', $swap, data: ['decision' => VehicleSwapService::DECISION_APPROVE_SWAP])
            ->assertHasTableActionErrors(['mapped_apparatus_id']);

        $this->assertSame('pending', $swap->fresh()->status);
    }

    public function test_the_apparatus_profile_lists_the_unit_swap_reports_for_fleet_staff(): void
    {
        $swap = $this->heldRequest();
        $this->actingAs($this->fleetOperationsManager());

        Livewire::test(VehicleSwapReportsRelationManager::class, ['ownerRecord' => $this->unit, 'pageClass' => ViewApparatus::class])
            ->call('loadTable')
            ->assertCanSeeTableRecords([$swap])
            ->assertTableActionVisible('decideVehicleSwap', $swap);
        $this->assertSame('1', VehicleSwapReportsRelationManager::getBadge($this->unit, ViewApparatus::class));
        $this->assertTrue(VehicleSwapReportsRelationManager::canViewForRecord($this->unit, ViewApparatus::class));

        $this->actingAs($this->member);
        $this->assertFalse(VehicleSwapReportsRelationManager::canViewForRecord($this->unit, ViewApparatus::class));
    }

    public function test_a_decided_report_stays_on_the_inspection_history_without_rewriting_its_snapshot(): void
    {
        $swap = $this->heldRequest();
        $this->service()->decide($swap->id, $this->fleetOperationsManager(), VehicleSwapService::DECISION_APPROVE_SWAP);

        $inspection = ApparatusInspection::query()->sole();
        $this->assertSame('V-202', $inspection->vehicle_number, 'The stored snapshot is never rewritten.');
        $this->assertSame('E2', $inspection->designation_at_time);
        $this->assertSame('V-303', $inspection->vehicleSwapRequest->reported_vehicle_number);
        $this->assertSame($this->frontline->id, $inspection->vehicleSwapRequest->confirmed_apparatus_id);
        $this->assertInstanceOf(User::class, $inspection->vehicleSwapRequest->decidedBy);
    }
}
