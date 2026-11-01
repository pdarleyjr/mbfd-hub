<?php

declare(strict_types=1);

namespace Tests\Feature\Fleet\Changeouts;

use App\Filament\Resources\ApparatusChangeoutResource;
use App\Filament\Resources\ApparatusChangeoutResource\Pages\ListApparatusChangeouts;
use App\Filament\Resources\ApparatusChangeoutResource\Pages\ViewApparatusChangeout;
use App\Filament\Resources\ApparatusResource\Pages\ListApparatuses;
use App\Filament\Resources\ApparatusResource\Pages\ViewApparatus;
use App\Filament\Resources\ApparatusResource\RelationManagers\ChangeoutsRelationManager;
use App\Filament\Resources\ApparatusResource\RelationManagers\OperationalAlertsRelationManager;
use App\Filament\Resources\ApparatusResource\Widgets\VehicleChangesBanner;
use App\Jobs\FanOutNotification;
use App\Models\ApparatusChangeout;
use App\Models\User;
use App\Services\Fleet\ApparatusChangeoutService;
use App\Services\Fleet\VehicleSwapNotifier;
use App\Services\Notifications\NotificationCategoryRegistry;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

/** The admin side of a changed truck: told once, shown until someone acknowledges it, and never a decision. */
final class AdminVehicleChangesTest extends AdminChangeoutTestCase
{
    public function test_fleet_is_told_once_after_commit_with_a_deep_link(): void
    {
        $changeout = $this->submit($this->member);
        $manager = $this->fleetOperationsManager();

        Queue::assertPushed(FanOutNotification::class, 1);
        Queue::assertPushed(FanOutNotification::class, function (FanOutNotification $job) use ($changeout, $manager): bool {
            $payload = $job->notification->toDatabase($manager);

            return $job->categoryKey === User::NOTIFICATION_PREFERENCE_VEHICLE_SWAP_REPORTED
                && $job->eventKey !== null && str_contains($job->eventKey, 'committed')
                && ($payload['title'] ?? null) === 'E2 changed trucks: V-202 to V-909'
                && str_contains((string) ($payload['body'] ?? ''), 'Truck V-202 is marked Out of Service.')
                && str_contains((string) data_get($payload, 'actions.0.url'), '/admin/apparatus-changeouts/'.$changeout->id);
        });
    }

    public function test_a_notification_failure_never_fails_the_swap(): void
    {
        $this->mock(VehicleSwapNotifier::class)->shouldReceive('changeoutCommitted')->andThrow(new \RuntimeException('Mail is down'));

        $changeout = $this->submit($this->member);

        $this->assertSame('completed', $changeout->status);
        $this->assertSame('E2', $this->reserve->fresh()->designation);
    }

    public function test_the_category_says_what_it_now_covers(): void
    {
        $category = app(NotificationCategoryRegistry::class)->all()[User::NOTIFICATION_PREFERENCE_VEHICLE_SWAP_REPORTED] ?? null;

        $this->assertNotNull($category);
        $this->assertSame('A crew changed trucks, or reported a different truck than the one assigned.', $category->description);
    }

    public function test_the_list_banner_shows_what_is_not_acknowledged_with_acknowledge_and_view(): void
    {
        $changeout = $this->submit($this->member);
        $this->actingAs($this->fleetOperationsManager());

        $banner = Livewire::test(VehicleChangesBanner::class)
            ->assertSeeHtml('data-vehicle-changes-banner')
            ->assertSee('E2 changed trucks: V-202 to V-909,')
            ->assertSee('by '.$this->member->name)
            ->assertSee('Truck V-202 is marked Out of Service.')
            ->assertSee('Acknowledge')
            ->assertSeeHtml('/admin/apparatus-changeouts/'.$changeout->id);

        $banner->call('acknowledge', $changeout->id)->assertDispatched(VehicleChangesBanner::ACKNOWLEDGED_EVENT)
            ->assertDontSeeHtml('data-vehicle-changes-banner');
        $fresh = $changeout->fresh();
        $this->assertNotNull($fresh->acknowledged_at);
        $this->assertSame(auth()->id(), $fresh->acknowledged_by_user_id);

        $this->get('/admin/apparatuses')->assertOk();
    }

    public function test_the_list_page_carries_the_banner_and_a_vehicle_changes_tab_with_a_count(): void
    {
        $this->submit($this->member);
        $this->actingAs($this->fleetOperationsManager());
        request()->attributes->remove('fleet.queue-counts');

        $this->get('/admin/apparatuses')->assertOk()->assertSee('E2 changed trucks: V-202 to V-909')->assertSee('Vehicle changes');

        $page = Livewire::test(ListApparatuses::class)->call('loadTable');
        $tabs = $page->instance()->getTabs();
        $this->assertSame(1, $tabs['vehicle_changes']->getBadge());

        $page->set('activeTab', 'vehicle_changes')->call('loadTable')
            ->assertCanSeeTableRecords([$this->unit->fresh(), $this->reserve->fresh()])
            ->assertCanNotSeeTableRecords([$this->frontline->fresh()]);
    }

    public function test_the_profile_of_both_trucks_shows_the_change_and_another_truck_does_not(): void
    {
        $this->submit($this->member);
        $this->actingAs($this->fleetOperationsManager());

        foreach ([$this->unit, $this->reserve] as $truck) {
            $this->get('/admin/apparatuses/'.$truck->id)->assertOk()->assertSeeHtml('data-vehicle-changes-banner')->assertSee('E2 changed trucks: V-202 to V-909');
            Livewire::test(VehicleChangesBanner::class, ['record' => $truck->fresh()])->assertSee('E2 changed trucks: V-202 to V-909');
        }
        $this->get('/admin/apparatuses/'.$this->frontline->id)->assertOk()->assertDontSeeHtml('data-vehicle-changes-banner');
    }

    public function test_a_fleet_viewer_sees_it_and_cannot_acknowledge_it(): void
    {
        $changeout = $this->submit($this->member);
        $viewer = $this->fleetViewer();
        $this->actingAs($viewer);

        Livewire::test(VehicleChangesBanner::class)
            ->assertSee('E2 changed trucks: V-202 to V-909')
            ->assertDontSeeHtml('wire:click="acknowledge(')
            ->call('acknowledge', $changeout->id)
            ->assertNotDispatched(VehicleChangesBanner::ACKNOWLEDGED_EVENT);
        Livewire::test(ViewApparatusChangeout::class, ['record' => $changeout->getRouteKey()])->assertActionHidden('acknowledgeChangeout');

        $this->assertNull($changeout->fresh()->acknowledged_at);
    }

    public function test_an_unauthorized_acknowledge_is_refused_by_the_service(): void
    {
        $changeout = $this->submit($this->member);

        foreach ([$this->member, $this->fleetViewer()] as $actor) {
            try {
                app(ApparatusChangeoutService::class)->acknowledge($changeout->id, $actor);
                $this->fail('Refused');
            } catch (AuthorizationException) {
                $this->assertNull($changeout->fresh()->acknowledged_at);
            }
        }
    }

    public function test_a_plain_member_does_not_get_the_banner(): void
    {
        $this->submit($this->member);
        $this->actingAs($this->member);

        $this->assertFalse(VehicleChangesBanner::canView());
    }

    public function test_acknowledging_twice_changes_nothing(): void
    {
        $changeout = $this->submit($this->member);
        $manager = $this->fleetOperationsManager();
        $first = app(ApparatusChangeoutService::class)->acknowledge($changeout->id, $manager);

        $this->travel(1)->hours();
        $second = app(ApparatusChangeoutService::class)->acknowledge($changeout->id, User::factory()->create(['account_status' => \App\Enums\AccountStatus::Active])->assignRole(\Spatie\Permission\Models\Role::findOrCreate('super_admin', 'web')));

        $this->assertEquals($first->acknowledged_at, $second->acknowledged_at);
        $this->assertSame($manager->id, $second->acknowledged_by_user_id);
        $this->assertSame(1, $changeout->events()->where('event', 'acknowledged')->count());
    }

    public function test_the_changeout_page_shows_took_and_left_and_has_no_decision(): void
    {
        $changeout = $this->submit($this->member, ['exceptions' => [['item_key' => $this->firstItemKey(), 'handling' => 'leave', 'note' => 'Spare stays with the truck.']]]);
        $this->actingAs($this->fleetOperationsManager());

        $page = Livewire::test(ViewApparatusChangeout::class, ['record' => $changeout->getRouteKey()])
            ->assertSee('E2 changed trucks: V-202 to V-909')
            ->assertSee('Truck V-202 is marked Out of Service.')
            ->assertSee('Left on the old truck (1)')
            ->assertSee('Spare stays with the truck.')
            ->assertSee('Took to the new truck (')
            ->assertDontSee('Corrections stayed on the old truck')
            ->assertSee('Unit moved to the new truck')
            ->assertSee('Not yet acknowledged')
            ->assertSeeHtml('/admin/apparatuses/'.$this->unit->id)
            ->assertSeeHtml('/admin/apparatuses/'.$this->reserve->id)
            ->assertActionVisible('acknowledgeChangeout')
            ->assertActionDoesNotExist('approveChangeout')
            ->assertActionDoesNotExist('rejectChangeout')
            ->assertActionDoesNotExist('verifyLine');
        $this->assertStringNotContainsString('—', $page->html());

        $page->callAction('acknowledgeChangeout');
        $this->assertNotNull($changeout->fresh()->acknowledged_at);
        $page->assertActionHidden('acknowledgeChangeout');

        $changeout->fresh()->update(['move_audit' => [...$changeout->fresh()->move_audit, 'corrections_left_on_truck' => 2]]);
        Livewire::test(ViewApparatusChangeout::class, ['record' => $changeout->getRouteKey()])
            ->assertSee('Corrections stayed on the old truck')
            ->assertSee('2 approved checklist corrections stayed on truck V-202');
    }

    public function test_a_review_note_is_on_the_changeout_page(): void
    {
        $changeout = $this->submit($this->member, ['to_apparatus_id' => $this->frontline->id, 'expected_vehicle_number' => 'V-202']);
        $this->actingAs($this->fleetOperationsManager());

        Livewire::test(ViewApparatusChangeout::class, ['record' => $changeout->getRouteKey()])
            ->assertSee('Please review')
            ->assertSee('E3 was on truck V-303 and is now listed on truck V-202, which is Out of Service. Please review.');
    }

    public function test_the_list_badge_is_the_unacknowledged_count_and_the_row_acknowledges(): void
    {
        $changeout = $this->submit($this->member);
        $this->actingAs($this->fleetOperationsManager());
        request()->attributes->remove('fleet.queue-counts');
        $this->assertSame('1', ApparatusChangeoutResource::getNavigationBadge());

        Livewire::test(ListApparatusChangeouts::class)->call('loadTable')
            ->assertCanSeeTableRecords([$changeout])
            ->callTableAction('acknowledge', $changeout)
            ->assertHasNoTableActionErrors();

        $this->assertNotNull($changeout->fresh()->acknowledged_at);
        request()->attributes->remove('fleet.queue-counts');
        $this->assertNull(ApparatusChangeoutResource::getNavigationBadge());
    }

    public function test_export_works_with_the_custom_sort(): void
    {
        $this->submit($this->member);
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
        $changeout = $this->submit($this->member);
        $this->actingAs($this->fleetOperationsManager());

        foreach ([$this->unit, $this->reserve] as $truck) {
            Livewire::test(ChangeoutsRelationManager::class, ['ownerRecord' => $truck->fresh(), 'pageClass' => ViewApparatus::class])
                ->call('loadTable')
                ->assertCanSeeTableRecords([$changeout]);
            $this->assertSame('1', ChangeoutsRelationManager::getBadge($truck->fresh(), ViewApparatus::class));
        }
        Livewire::test(ChangeoutsRelationManager::class, ['ownerRecord' => $this->frontline->fresh(), 'pageClass' => ViewApparatus::class])
            ->call('loadTable')
            ->assertCanNotSeeTableRecords([$changeout]);
    }

    public function test_alerts_follow_the_unit_on_the_apparatus_page_and_stay_on_the_truck_they_were_raised_on(): void
    {
        $alert = $this->alertFor($this->unit);
        $other = $this->alertFor($this->frontline);
        // A trade moves E2 off its truck without a changeout of its own, so its alert stays active.
        $this->actingAs($this->fleetOperationsManager());
        app(ApparatusChangeoutService::class)->submit($this->frontline->fresh(), $this->fleetOperationsManager(), [
            'client_submission_id' => (string) \Illuminate\Support\Str::uuid(), 'expected_vehicle_number' => 'V-303', 'expected_unit_slug' => 'e3', 'to_apparatus_id' => $this->unit->id,
        ]);

        foreach ([$this->frontline, $this->unit] as $truck) {
            Livewire::test(OperationalAlertsRelationManager::class, ['ownerRecord' => $truck->fresh(), 'pageClass' => ViewApparatus::class])
                ->call('loadTable')
                ->assertCanSeeTableRecords([$alert]);
        }
        $this->assertSame('1', OperationalAlertsRelationManager::getBadge($this->frontline->fresh(), ViewApparatus::class));
        $this->assertSame('completed', $other->fresh()->status, 'E3 left the truck its alert was raised on.');
    }

    public function test_the_apparatus_page_renders_with_the_new_tabs_and_the_current_assignment_card(): void
    {
        $alert = $this->alertFor($this->frontline);
        $this->submit($this->member);
        $this->actingAs($this->fleetOperationsManager());

        Livewire::test(ViewApparatus::class, ['record' => $this->frontline->getRouteKey()])
            ->assertSuccessful()
            ->assertSeeHtml('data-current-assignment')
            ->assertSee('Current assignment')
            ->assertSee($alert->title)
            ->assertSee('Operational alerts')
            ->assertSee('Vehicle changeouts');
        Livewire::test(ViewApparatus::class, ['record' => $this->unit->getRouteKey()])
            ->assertSuccessful()
            ->assertSee('Last truck change')
            ->assertSee('Not yet acknowledged');
    }

    public function test_relation_manager_exports_stay_inside_the_truck(): void
    {
        $this->submit($this->member);
        $this->alertFor($this->frontline);
        $this->actingAs($this->fleetViewer());

        foreach ([ChangeoutsRelationManager::class, OperationalAlertsRelationManager::class] as $manager) {
            $download = Livewire::test($manager, ['ownerRecord' => $this->unit->fresh(), 'pageClass' => ViewApparatus::class])
                ->call('loadTable')
                ->callTableAction('export', data: ['format' => 'xlsx', 'scope' => 'filtered', 'include_toggled_off' => true])
                ->assertHasNoTableActionErrors();
            $this->assertStringStartsWith('PK', base64_decode((string) $download->effects['download']['content'], true));
        }
    }

    public function test_nothing_is_ever_waiting_on_a_decision(): void
    {
        $changeout = $this->submit($this->member);

        $this->assertSame([ApparatusChangeout::STATUS_COMPLETED, ApparatusChangeout::HANDOFF_COMPLETE], [$changeout->status, $changeout->handoff_status]);
        $this->assertNull($changeout->fresh()->getAttribute('decided_at'));
    }
}
