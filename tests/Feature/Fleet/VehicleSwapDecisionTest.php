<?php

declare(strict_types=1);

namespace Tests\Feature\Fleet;

use App\Filament\Resources\ApparatusResource\Pages\ViewApparatus;
use App\Filament\Resources\ApparatusResource\RelationManagers\VehicleSwapReportsRelationManager;
use App\Filament\Resources\VehicleSwapRequestResource;
use App\Filament\Resources\VehicleSwapRequestResource\Pages\ListVehicleSwapRequests;
use App\Models\ApparatusDefect;
use App\Models\ApparatusInspection;
use App\Models\ApparatusInspectionReviewEvent;
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

    public function test_reject_changes_nothing_and_applies_the_findings_to_the_assigned_vehicle(): void
    {
        $swap = $this->heldRequest();
        $manager = $this->fleetOperationsManager();

        $decided = $this->service()->decide($swap->id, $manager, VehicleSwapService::DECISION_REJECT, 'Not a swap.');

        $this->assertSame('rejected', $decided->status);
        $this->assertSame('V-202', $this->unit->fresh()->vehicle_number);
        $this->assertSame('V-303', $this->frontline->fresh()->vehicle_number);
        $inspection = ApparatusInspection::query()->sole();
        $this->assertSame('approved', $inspection->review_status);
        $this->assertSame('accepted', $inspection->processing_status);
        $this->assertNull($inspection->pending_effects);
        $this->assertSame('401.2', $this->unit->fresh()->current_engine_hours);
        $this->assertSame(1020, $this->unit->fresh()->current_miles);
        $this->assertSame('900.0', $this->frontline->fresh()->current_engine_hours);
        $defect = ApparatusDefect::query()->sole();
        $this->assertSame($this->unit->id, $defect->apparatus_id);

        $this->assertSame($manager->id, $decided->decided_by_user_id);
        $this->assertNotNull($decided->decided_at);
        $this->assertSame('Not a swap.', $decided->decision_note);
        $this->assertSame('V-202', $decided->decision_audit['before']['unit']['vehicle_number']);
        $this->assertSame('V-202', $decided->decision_audit['after']['unit']['vehicle_number']);
        $event = ApparatusInspectionReviewEvent::query()->where('status', 'swap_rejected')->sole();
        $this->assertSame($manager->id, $event->changed_by_user_id);
    }

    public function test_approve_swap_trades_the_two_units_vehicle_ids_and_applies_findings(): void
    {
        $swap = $this->heldRequest();
        $manager = $this->fleetOperationsManager();

        $decided = $this->service()->decide($swap->id, $manager, VehicleSwapService::DECISION_APPROVE_SWAP);

        $this->assertSame('approved_swap', $decided->status);
        $this->assertSame('V-303', $this->unit->fresh()->vehicle_number);
        $this->assertSame('V-202', $this->frontline->fresh()->vehicle_number);
        $this->assertSame('In Service', $this->frontline->fresh()->status, 'A plain trade leaves statuses alone.');
        $this->assertSame('approved', ApparatusInspection::query()->sole()->review_status);
        $this->assertSame($this->unit->id, ApparatusDefect::query()->sole()->apparatus_id);
        $this->assertSame('401.2', $this->unit->fresh()->current_engine_hours);
        $this->assertSame('V-202', $decided->decision_audit['before']['unit']['vehicle_number']);
        $this->assertSame('V-303', $decided->decision_audit['before']['other_unit']['vehicle_number']);
        $this->assertSame('V-303', $decided->decision_audit['after']['unit']['vehicle_number']);
        $this->assertSame('V-202', $decided->decision_audit['after']['other_unit']['vehicle_number']);
        // Nothing else about either unit changed.
        $this->assertSame('E3', $this->frontline->fresh()->designation);
        $this->assertSame(5000, $this->frontline->fresh()->current_miles);
    }

    #[DataProvider('demotions')]
    public function test_approve_replace_moves_the_old_vehicle_and_demotes_it(string $demoteTo): void
    {
        $swap = $this->heldRequest('V-909');
        $manager = $this->fleetOperationsManager();

        $decided = $this->service()->decide($swap->id, $manager, VehicleSwapService::DECISION_APPROVE_REPLACE, null, $demoteTo);

        $this->assertSame('approved_replace', $decided->status);
        $this->assertSame('V-909', $this->unit->fresh()->vehicle_number);
        $this->assertSame('V-202', $this->reserve->fresh()->vehicle_number);
        $this->assertSame($demoteTo, $this->reserve->fresh()->status);
        $this->assertSame('In Service', $this->unit->fresh()->status);
        $this->assertSame($demoteTo, $decided->decision_audit['old_vehicle_demoted_to']);
        $this->assertSame('Reserve', $decided->decision_audit['before']['other_unit']['status']);
    }

    /** @return array<string, array{string}> */
    public static function demotions(): array
    {
        return ['reserve' => ['Reserve'], 'out of service' => ['Out of Service']];
    }

    public function test_approve_replace_for_a_vehicle_on_no_unit_assigns_it_and_records_the_old_one(): void
    {
        $swap = $this->heldRequest('X-77');
        $this->assertFalse($swap->reported_vehicle_listed);

        $decided = $this->service()->decide($swap->id, $this->fleetOperationsManager(), VehicleSwapService::DECISION_APPROVE_REPLACE);

        $this->assertSame('X-77', $this->unit->fresh()->vehicle_number);
        $this->assertSame('V-202', $decided->decision_audit['old_vehicle_without_unit']);
        $this->assertSame('V-303', $this->frontline->fresh()->vehicle_number);
        $this->assertSame('approved', ApparatusInspection::query()->sole()->review_status);
    }

    public function test_a_trade_needs_a_unit_to_trade_with_and_a_refusal_changes_nothing(): void
    {
        $swap = $this->heldRequest('X-77');

        try {
            $this->service()->decide($swap->id, $this->fleetOperationsManager(), VehicleSwapService::DECISION_APPROVE_SWAP);
            $this->fail('There is no unit to trade with.');
        } catch (VehicleSwapDecisionException $exception) {
            $this->assertStringContainsString('not on any unit', $exception->getMessage());
        }

        $this->assertSame('pending', $swap->fresh()->status);
        $this->assertSame('V-202', $this->unit->fresh()->vehicle_number);
        $this->assertSame('pending_vehicle_swap', ApparatusInspection::query()->sole()->processing_status);
        $this->assertDatabaseCount('apparatus_defects', 0);
    }

    public function test_an_assignment_that_changed_since_the_report_blocks_approval(): void
    {
        $swap = $this->heldRequest();
        $this->unit->update(['vehicle_number' => 'V-404']);

        try {
            $this->service()->decide($swap->id, $this->fleetOperationsManager(), VehicleSwapService::DECISION_APPROVE_SWAP);
            $this->fail('The report no longer matches the unit.');
        } catch (VehicleSwapDecisionException $exception) {
            $this->assertStringContainsString('changed after this report', $exception->getMessage());
        }

        $this->assertSame('pending', $swap->fresh()->status);
        $this->assertSame('V-404', $this->unit->fresh()->vehicle_number);
        $this->assertSame('V-303', $this->frontline->fresh()->vehicle_number);
    }

    public function test_rejection_still_works_after_the_assignment_changed(): void
    {
        $swap = $this->heldRequest();
        $this->unit->update(['vehicle_number' => 'V-404']);

        $decided = $this->service()->decide($swap->id, $this->fleetOperationsManager(), VehicleSwapService::DECISION_REJECT);

        $this->assertSame('rejected', $decided->status);
        $this->assertSame('V-404', $this->unit->fresh()->vehicle_number);
        $this->assertSame('approved', ApparatusInspection::query()->sole()->review_status);
    }

    public function test_deciding_twice_is_idempotent_and_never_applies_findings_twice(): void
    {
        $swap = $this->heldRequest();
        $manager = $this->fleetOperationsManager();

        $first = $this->service()->decide($swap->id, $manager, VehicleSwapService::DECISION_APPROVE_SWAP);
        $again = $this->service()->decide($swap->id, $manager, VehicleSwapService::DECISION_APPROVE_SWAP);

        $this->assertSame($first->decided_at?->toIso8601String(), $again->decided_at?->toIso8601String());
        $this->assertSame('V-303', $this->unit->fresh()->vehicle_number, 'A second swap would trade them back.');
        $this->assertSame('V-202', $this->frontline->fresh()->vehicle_number);
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
        $this->assertSame('V-202', $this->unit->fresh()->vehicle_number);
        $this->assertSame('pending_vehicle_swap', ApparatusInspection::query()->sole()->processing_status);
        $this->assertDatabaseCount('apparatus_defects', 0);
    }

    public function test_a_super_administrator_may_decide(): void
    {
        $swap = $this->heldRequest();

        $decided = $this->service()->decide($swap->id, $this->superAdmin(), VehicleSwapService::DECISION_REJECT);

        $this->assertSame('rejected', $decided->status);
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

    public function test_the_decision_modal_records_the_choice_and_the_old_vehicle_disposition(): void
    {
        $swap = $this->heldRequest('V-909');
        $manager = $this->fleetOperationsManager();
        $this->actingAs($manager);

        Livewire::test(ListVehicleSwapRequests::class)
            ->call('loadTable')
            ->callTableAction('decideVehicleSwap', $swap, data: [
                'decision' => VehicleSwapService::DECISION_APPROVE_REPLACE,
                'demote_to' => 'Out of Service',
                'note' => 'Confirmed with the shift officer.',
            ])
            ->assertHasNoTableActionErrors();

        $this->assertSame('approved_replace', $swap->fresh()->status);
        $this->assertSame('V-909', $this->unit->fresh()->vehicle_number);
        $this->assertSame('Out of Service', $this->reserve->fresh()->status);
        $this->assertSame('Confirmed with the shift officer.', $swap->fresh()->decision_note);
        $this->assertSame($manager->id, $swap->fresh()->decided_by_user_id);
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
        $this->assertSame('V-303', $inspection->vehicleSwapRequest->reported_vehicle_number);
        $this->assertInstanceOf(User::class, $inspection->vehicleSwapRequest->decidedBy);
    }
}
