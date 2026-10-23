<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Filament\Employee\Pages\MyInventoryCorrectionView;
use App\Filament\Employee\Pages\MyVehicleInspections;
use App\Filament\Resources\ApparatusResource\Pages\ViewApparatus;
use App\Filament\Resources\ApparatusResource\RelationManagers\InventoryCorrectionsRelationManager;
use App\Filament\Resources\InspectionResource;
use App\Filament\Resources\InventoryCorrectionResource;
use App\Filament\Resources\InventoryCorrectionResource\Pages\ListInventoryCorrections;
use App\Filament\Resources\InventoryCorrectionResource\Pages\ViewInventoryCorrection;
use App\Models\ApparatusInspection;
use App\Models\ApparatusInventoryCorrection;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Feature\Inventory\Concerns\BuildsInventoryCorrectionFixtures;
use Tests\TestCase;

/** The Logistics review page, its entry points and the member's portal page. */
final class InventoryCorrectionAdminTest extends TestCase
{
    use BuildsInventoryCorrectionFixtures;
    use RefreshDatabase;

    private User $logistics;

    private ApparatusInventoryCorrection $correction;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpInventoryFleet();
        $draft = (string) Str::uuid();
        $passports = $this->item('E1', 'Passports');
        $this->correction = $this->reported('E1', [
            'reason' => 'moved', 'compartment_id' => $passports['compartment_id'], 'item_id' => $passports['item_id'],
            'proposal' => ['to_compartment_id' => 'comp_a1'], 'note' => 'Now in Driver L4.', 'inspection_draft_id' => $draft,
        ]);
        $this->postJson("/api/public/apparatuses/{$this->units['E1']->id}/inspections", [...$this->inspectionPayload('E1'), 'inventory_draft_id' => $draft])->assertCreated();
        $this->logistics = $this->logistics();
        $this->actingAs($this->logistics);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_the_queue_lists_open_corrections_by_default(): void
    {
        $this->actingAsCanonicalUser($this->member);
        $rejected = $this->reported('E3', ['reason' => 'no_longer_carried', 'compartment_id' => 'front_cab', 'item_id' => $this->item('E3', 'Passports')['item_id']]);
        $this->service()->reject($rejected->id, $this->logistics, 'Still carried.');
        $this->actingAs($this->logistics);

        Livewire::test(ListInventoryCorrections::class)
            ->loadTable()
            ->assertOk()
            ->assertCanSeeTableRecords([$this->correction])
            ->assertCanNotSeeTableRecords([$rejected]);
        $this->assertSame('1', InventoryCorrectionResource::getNavigationBadge());
    }

    public function test_the_review_page_shows_before_proposal_and_conversation(): void
    {
        $this->get(InventoryCorrectionResource::getUrl('view', ['record' => $this->correction]))
            ->assertOk()
            ->assertSee('Checklist now')
            ->assertSee('Front Cab')
            ->assertSee('Driver L4')
            ->assertSee('Now in Driver L4.')
            ->assertSee('Open the inspection');
    }

    public function test_approving_from_the_page_with_the_suggested_family_decision_updates_every_engine(): void
    {
        $this->page()
            ->mountAction('approve')
            ->assertActionDataSet(['op' => 'modify', 'compartment_id' => 'comp_a1', 'scope' => 'unit'])
            ->setActionData(['scope' => 'family', 'expectedQuantity' => 3])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $this->assertSame('applied', $this->correction->fresh()->status);
        foreach (['E1' => 'comp_a1', 'E3' => 'comp_a1', 'E2' => 'comp_l1'] as $designation => $compartment) {
            $this->assertSame($compartment, $this->item($designation, 'Passports')['compartment_id'], $designation);
            $this->assertSame(3, $this->item($designation, 'Passports')['item']['expectedQuantity'], $designation);
        }
        $this->assertSame('front_cab', $this->item('E21', 'Passports')['compartment_id'], 'reserves need an explicit choice');
        $this->get(InventoryCorrectionResource::getUrl('view', ['record' => $this->correction]))->assertOk()->assertSee('Inventory changes made')->assertSee('In effect');
    }

    public function test_reject_clarify_and_internal_notes_from_the_page(): void
    {
        $this->page()->callAction('reply', ['visibility' => 'internal', 'body' => 'Check the photo first.'])->assertHasNoActionErrors();
        $this->page()->callAction('requestClarification', ['message' => 'Which side?'])->assertHasNoActionErrors();
        $this->assertSame('clarification_requested', $this->correction->fresh()->status);
        $this->page()->callAction('reject', ['reason' => 'It belongs in the front cab.'])->assertHasNoActionErrors();
        $this->assertSame('rejected', $this->correction->fresh()->status);
        $this->page()->assertActionHidden('approve')->assertActionHidden('reject');
    }

    public function test_entry_points_from_the_inspection_and_the_apparatus_profile(): void
    {
        $inspection = ApparatusInspection::query()->latest('id')->firstOrFail();
        $this->assertSame($inspection->id, $this->correction->fresh()->apparatus_inspection_id);
        $this->get(InspectionResource::getUrl('view', ['record' => $inspection]))->assertOk()->assertSee('Inventory corrections reported')->assertSee($this->correction->reference());

        Livewire::test(InventoryCorrectionsRelationManager::class, ['ownerRecord' => $this->unit('E1'), 'pageClass' => ViewApparatus::class])
            ->loadTable()
            ->assertOk()
            ->assertCanSeeTableRecords([$this->correction]);
    }

    public function test_the_member_sees_status_and_can_answer_a_question(): void
    {
        $this->service()->requestClarification($this->correction->id, $this->logistics, 'Which side?', $this->correction->lock_version);
        $this->actingAsCanonicalUser($this->member);
        Filament::setCurrentPanel(Filament::getPanel('employee'));
        $this->bindCanonicalSessionToLivewireTestRequests();

        $this->get(MyVehicleInspections::getUrl(panel: 'employee'))->assertOk()->assertSee('Inventory corrections you reported')->assertSee('Reply needed');
        Livewire::test(MyInventoryCorrectionView::class, ['correction' => $this->correction->id])
            ->assertSee('Which side?')
            ->set('reply', 'Driver side, lower shelf.')
            ->call('sendReply')
            ->assertHasNoErrors();
        $this->assertSame('submitted', $this->correction->fresh()->status);
    }

    private function page(): Testable
    {
        return Livewire::test(ViewInventoryCorrection::class, ['record' => $this->correction->getRouteKey()]);
    }
}
