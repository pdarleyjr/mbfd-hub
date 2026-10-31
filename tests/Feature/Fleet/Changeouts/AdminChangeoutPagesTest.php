<?php

declare(strict_types=1);

namespace Tests\Feature\Fleet\Changeouts;

use App\Filament\Resources\ApparatusChangeoutResource\Pages\ListApparatusChangeouts;
use App\Filament\Resources\ApparatusChangeoutResource\Pages\ViewApparatusChangeout;
use App\Filament\Resources\ApparatusResource\Pages\ViewApparatus;
use App\Filament\Resources\ApparatusResource\RelationManagers\ChangeoutsRelationManager;
use App\Filament\Resources\ApparatusResource\RelationManagers\OperationalAlertsRelationManager;
use App\Models\ApparatusChangeout;
use App\Models\ApparatusChangeoutItem;
use App\Models\ApparatusOperationalAlert;
use App\Services\Fleet\ApparatusChangeoutService;
use Livewire\Livewire;

final class AdminChangeoutPagesTest extends AdminChangeoutTestCase
{
    public function test_approve_from_the_page_moves_the_unit_and_only_the_unit(): void
    {
        $changeout = $this->pendingChangeout();
        $this->assertSame('E2', $this->unit->fresh()->designation, 'Nothing moved while it waited.');
        $manager = $this->fleetOperationsManager();
        $this->actingAs($manager);

        Livewire::test(ViewApparatusChangeout::class, ['record' => $changeout->getRouteKey()])
            ->callAction('approveChangeout', data: ['old_truck_status' => 'Maintenance', 'note' => 'Confirmed with the shift officer.'])
            ->assertHasNoActionErrors()
            ->assertActionHidden('approveChangeout')
            ->assertSee('Truck V-202 was E2 (In Service).');

        $this->assertSame(ApparatusChangeout::STATUS_COMPLETED, $changeout->fresh()->status);
        $this->assertSame($manager->id, $changeout->fresh()->decided_by_user_id);
        $reserve = $this->reserve->fresh();
        $old = $this->unit->fresh();
        $this->assertSame('E2', $reserve->designation);
        $this->assertSame('V-909', $reserve->vehicle_number);
        $this->assertSame('In Service', $reserve->getAttribute('status'));
        $this->assertSame('V-202', $old->vehicle_number);
        $this->assertNotSame('E2', $old->designation);
        $this->assertSame('Maintenance', $old->getAttribute('status'));
    }

    public function test_approve_asks_which_truck_an_unlisted_number_is(): void
    {
        $changeout = $this->submit($this->member, ['to_apparatus_id' => null, 'to_vehicle_number' => 'X-77']);
        $this->assertNull($changeout->to_apparatus_id);
        $this->actingAs($this->fleetOperationsManager());

        Livewire::test(ViewApparatusChangeout::class, ['record' => $changeout->getRouteKey()])
            ->callAction('approveChangeout', data: [])
            ->assertHasActionErrors(['mapped_apparatus_id']);
        $this->assertSame(ApparatusChangeout::STATUS_PENDING_FLEET, $changeout->fresh()->status);

        Livewire::test(ViewApparatusChangeout::class, ['record' => $changeout->getRouteKey()])
            ->callAction('approveChangeout', data: ['mapped_apparatus_id' => $this->reserve->id])
            ->assertHasNoActionErrors();

        $this->assertSame($this->reserve->id, $changeout->fresh()->to_apparatus_id);
        $this->assertSame('E2', $this->reserve->fresh()->designation);
    }

    public function test_reject_needs_a_reason_and_leaves_every_truck_untouched(): void
    {
        $changeout = $this->pendingChangeout();
        $before = $this->trucksNow();
        $this->actingAs($this->fleetOperationsManager());

        Livewire::test(ViewApparatusChangeout::class, ['record' => $changeout->getRouteKey()])
            ->callAction('rejectChangeout', data: [])
            ->assertHasActionErrors(['note']);
        $this->assertSame(ApparatusChangeout::STATUS_PENDING_FLEET, $changeout->fresh()->status);

        Livewire::test(ViewApparatusChangeout::class, ['record' => $changeout->getRouteKey()])
            ->callAction('rejectChangeout', data: ['note' => 'Truck is promised to Rescue 1.'])
            ->assertHasNoActionErrors()
            ->assertSee('Truck is promised to Rescue 1.');

        $this->assertSame(ApparatusChangeout::STATUS_REJECTED, $changeout->fresh()->status);
        $this->assertSame($before, $this->trucksNow());
    }

    public function test_cancel_withdraws_it_and_leaves_every_truck_untouched(): void
    {
        $changeout = $this->pendingChangeout();
        $before = $this->trucksNow();
        $this->actingAs($this->fleetOperationsManager());

        Livewire::test(ViewApparatusChangeout::class, ['record' => $changeout->getRouteKey()])
            ->callAction('cancelChangeout', data: ['note' => 'Crew withdrew it.'])
            ->assertHasNoActionErrors();

        $this->assertSame(ApparatusChangeout::STATUS_CANCELLED, $changeout->fresh()->status);
        $this->assertSame($before, $this->trucksNow());
    }

    public function test_approving_from_a_stale_screen_after_someone_else_answered_changes_nothing(): void
    {
        $changeout = $this->pendingChangeout();
        $this->actingAs($this->fleetOperationsManager());
        $page = Livewire::test(ViewApparatusChangeout::class, ['record' => $changeout->getRouteKey()]);
        app(ApparatusChangeoutService::class)->decide($changeout->id, $this->fleetOperationsManager(), 'reject', 'Someone else answered.');
        $before = $this->trucksNow();

        $page->callAction('approveChangeout', data: []);

        $this->assertSame(ApparatusChangeout::STATUS_REJECTED, $changeout->fresh()->status);
        $this->assertSame($before, $this->trucksNow());
    }

    public function test_verifying_the_last_open_line_closes_the_handoff_and_the_alert(): void
    {
        $alert = $this->alertFor($this->unit, ['planned_replacement_apparatus_id' => $this->reserve->id, 'scheduled_date' => \App\Support\HubTimezone::today()->toDateString()]);
        $key = $this->firstItemKey();
        $changeout = $this->submit($this->member, [
            'alert_id' => $alert->id,
            'exceptions' => [['item_key' => $key, 'handling' => 'review', 'note' => 'Could not find it.']],
        ]);
        $this->assertSame(ApparatusChangeout::STATUS_COMPLETED, $changeout->status, 'The alert authorized the move.');
        $this->assertSame(ApparatusChangeout::HANDOFF_PENDING_VERIFICATION, $changeout->handoff_status);
        $this->assertSame(ApparatusOperationalAlert::STATUS_ACTIVE, $alert->fresh()->status, 'An open line keeps the alert up.');
        $line = $changeout->items()->where('item_key', $key)->sole();
        $this->assertSame(ApparatusChangeoutItem::CUSTODY_NEEDS_REVIEW, $line->custody_status);

        $this->actingAs($this->fleetOperationsManager());
        $open = $changeout->items()->whereIn('custody_status', ApparatusChangeoutItem::OPEN_CUSTODY)->orderBy('id')->get();
        $this->assertTrue($open->contains('id', $line->id));
        $page = Livewire::test(ViewApparatusChangeout::class, ['record' => $changeout->getRouteKey()])
            ->assertActionVisible('verifyLine')
            ->assertActionHidden('approveChangeout')
            ->assertSee('Still open ('.$open->count().')')
            ->assertSee('Could not find it.');

        $page->callAction('verifyLine', data: ['line_id' => $line->id])
            ->assertHasActionErrors(['note']);
        $this->assertSame(ApparatusChangeoutItem::CUSTODY_NEEDS_REVIEW, $line->fresh()->custody_status, 'A line marked for review needs what was found.');

        foreach ($open as $item) {
            $last = Livewire::test(ViewApparatusChangeout::class, ['record' => $changeout->getRouteKey()])
                ->callAction('verifyLine', data: ['line_id' => $item->id, 'note' => 'Found it in the side bin.'])
                ->assertHasNoActionErrors();
        }
        $last->assertActionHidden('verifyLine')->assertDontSee('Still open (');

        $this->assertSame(ApparatusChangeout::HANDOFF_COMPLETE, $changeout->fresh()->handoff_status);
        $this->assertSame(ApparatusOperationalAlert::STATUS_COMPLETED, $alert->fresh()->status);
        $this->assertSame($changeout->id, $alert->fresh()->completed_by_changeout_id);
    }

    public function test_the_page_shows_lines_grouped_the_trucks_as_links_and_the_corrections_warning(): void
    {
        $key = $this->firstItemKey();
        $changeout = $this->submit($this->member, ['exceptions' => [['item_key' => $key, 'handling' => 'leave', 'note' => 'Spare stays with the truck.']]]);
        $this->actingAs($this->fleetOperationsManager());
        app(ApparatusChangeoutService::class)->decide($changeout->id, auth()->user(), 'approve');

        $page = Livewire::test(ViewApparatusChangeout::class, ['record' => $changeout->getRouteKey()])
            ->assertSee('Exceptions (1)')
            ->assertSee('Left on the old truck.')
            ->assertSee('Spare stays with the truck.')
            ->assertSee('Moved (')
            ->assertDontSee('Corrections stayed on the old truck')
            ->assertSee('Unit moved to the new truck')
            ->assertSeeHtml('/admin/apparatuses/'.$this->unit->id)
            ->assertSeeHtml('/admin/apparatuses/'.$this->reserve->id);
        $this->assertStringNotContainsString('—', $page->html());

        $changeout->fresh()->update(['move_audit' => [...$changeout->fresh()->move_audit, 'corrections_left_on_truck' => 2]]);
        Livewire::test(ViewApparatusChangeout::class, ['record' => $changeout->getRouteKey()])
            ->assertSee('Corrections stayed on the old truck')
            ->assertSee('2 approved checklist corrections stayed on truck V-202');
    }

    public function test_export_works_with_the_custom_sort(): void
    {
        $this->pendingChangeout();
        $this->actingAs($this->fleetViewer());

        foreach (['xlsx' => 'PK', 'pdf' => '%PDF'] as $format => $magic) {
            $download = Livewire::test(ListApparatusChangeouts::class)->call('loadTable')
                ->callTableAction('export', data: ['format' => $format, 'scope' => 'filtered', 'include_toggled_off' => true])
                ->assertHasNoTableActionErrors();
            $this->assertStringStartsWith($magic, base64_decode((string) $download->effects['download']['content'], true));
        }
    }

    public function test_changeouts_follow_the_truck_on_the_apparatus_page(): void
    {
        $changeout = $this->pendingChangeout();
        $this->actingAs($this->fleetOperationsManager());
        app(ApparatusChangeoutService::class)->decide($changeout->id, auth()->user(), 'approve');

        foreach ([$this->unit, $this->reserve] as $truck) {
            Livewire::test(ChangeoutsRelationManager::class, ['ownerRecord' => $truck->fresh(), 'pageClass' => ViewApparatus::class])
                ->call('loadTable')
                ->assertCanSeeTableRecords([$changeout]);
        }
        Livewire::test(ChangeoutsRelationManager::class, ['ownerRecord' => $this->frontline->fresh(), 'pageClass' => ViewApparatus::class])
            ->call('loadTable')
            ->assertCanNotSeeTableRecords([$changeout]);
    }

    public function test_alerts_follow_the_unit_on_the_apparatus_page_and_stay_on_the_truck_they_were_raised_on(): void
    {
        $alert = $this->alertFor($this->unit);
        $other = $this->alertFor($this->frontline);
        $changeout = $this->submit($this->fleetOperationsManager(), ['exceptions' => [['item_key' => $this->firstItemKey(), 'handling' => 'review']]]);
        $this->assertSame(ApparatusChangeout::STATUS_COMPLETED, $changeout->status);
        $this->assertSame(ApparatusOperationalAlert::STATUS_ACTIVE, $alert->fresh()->status);
        $this->actingAs($this->fleetOperationsManager());

        foreach ([$this->reserve, $this->unit] as $truck) {
            Livewire::test(OperationalAlertsRelationManager::class, ['ownerRecord' => $truck->fresh(), 'pageClass' => ViewApparatus::class])
                ->call('loadTable')
                ->assertCanSeeTableRecords([$alert])
                ->assertCanNotSeeTableRecords([$other]);
        }
        $this->assertSame('1', OperationalAlertsRelationManager::getBadge($this->reserve->fresh(), ViewApparatus::class));
    }

    public function test_the_apparatus_page_renders_with_the_new_tabs_and_the_current_assignment_card(): void
    {
        // Fleet planned a different truck, so the crew's choice of the reserve truck waits on a decision.
        $alert = $this->alertFor($this->unit, ['planned_replacement_apparatus_id' => $this->frontline->id]);
        $pending = $this->pendingChangeout();
        $this->actingAs($this->fleetOperationsManager());

        Livewire::test(ViewApparatus::class, ['record' => $this->unit->getRouteKey()])
            ->assertSuccessful()
            ->assertSeeHtml('data-current-assignment')
            ->assertSee('Current assignment')
            ->assertSee($alert->title)
            ->assertSee('Move to truck V-909')
            ->assertSee('Operational alerts')
            ->assertSee('Vehicle changeouts');
        $this->get('/admin/apparatuses/'.$this->unit->id)->assertOk()->assertSee('Current assignment');
        $this->assertSame('1', ChangeoutsRelationManager::getBadge($this->unit, ViewApparatus::class));
        $this->assertSame($pending->id, ChangeoutsRelationManager::forTruck($this->unit)->sole()->id);

        Livewire::test(ViewApparatus::class, ['record' => $this->frontline->getRouteKey()])
            ->assertSuccessful()
            ->assertDontSeeHtml('data-current-assignment');
    }

    public function test_relation_manager_exports_stay_inside_the_truck(): void
    {
        $this->pendingChangeout();
        $this->alertFor($this->unit);
        $this->actingAs($this->fleetViewer());

        foreach ([ChangeoutsRelationManager::class, OperationalAlertsRelationManager::class] as $manager) {
            $download = Livewire::test($manager, ['ownerRecord' => $this->unit->fresh(), 'pageClass' => ViewApparatus::class])
                ->call('loadTable')
                ->callTableAction('export', data: ['format' => 'xlsx', 'scope' => 'filtered', 'include_toggled_off' => true])
                ->assertHasNoTableActionErrors();
            $this->assertStringStartsWith('PK', base64_decode((string) $download->effects['download']['content'], true));
        }
    }
}
