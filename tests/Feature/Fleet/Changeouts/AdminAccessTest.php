<?php

declare(strict_types=1);

namespace Tests\Feature\Fleet\Changeouts;

use App\Filament\Resources\ApparatusChangeoutResource;
use App\Filament\Resources\ApparatusChangeoutResource\Pages\ListApparatusChangeouts;
use App\Filament\Resources\ApparatusChangeoutResource\Pages\ViewApparatusChangeout;
use App\Filament\Resources\ApparatusOperationalAlertResource;
use App\Filament\Resources\ApparatusOperationalAlertResource\Pages\ListApparatusOperationalAlerts;
use App\Filament\Resources\ApparatusOperationalAlertResource\Pages\ViewApparatusOperationalAlert;
use App\Filament\Resources\ApparatusResource\Pages\ViewApparatus;
use App\Filament\Resources\ApparatusResource\RelationManagers\ChangeoutsRelationManager;
use App\Filament\Resources\ApparatusResource\RelationManagers\OperationalAlertsRelationManager;
use App\Models\ApparatusOperationalAlert;
use Livewire\Livewire;

final class AdminAccessTest extends AdminChangeoutTestCase
{
    public function test_a_plain_member_gets_403_on_every_page(): void
    {
        $alert = $this->alertFor($this->frontline);
        $changeout = $this->submit($this->member);
        $this->actingAs($this->member);

        foreach ([
            '/admin/apparatus-changeouts',
            '/admin/apparatus-changeouts/'.$changeout->id,
            '/admin/apparatus-operational-alerts',
            '/admin/apparatus-operational-alerts/create',
            '/admin/apparatus-operational-alerts/'.$alert->id,
            '/admin/apparatus-operational-alerts/'.$alert->id.'/edit',
        ] as $path) {
            $this->get($path)->assertForbidden();
        }

        $this->assertFalse(ApparatusChangeoutResource::canViewAny());
        $this->assertFalse(ApparatusOperationalAlertResource::canViewAny());
        $this->assertNull(ApparatusChangeoutResource::getNavigationBadge());
        $this->assertNull(ApparatusOperationalAlertResource::getNavigationBadge());
        $this->assertFalse(ChangeoutsRelationManager::canViewForRecord($this->unit, ViewApparatus::class));
        $this->assertFalse(OperationalAlertsRelationManager::canViewForRecord($this->unit, ViewApparatus::class));
    }

    public function test_fleet_view_can_see_everything_and_act_on_nothing(): void
    {
        $alert = $this->alertFor($this->frontline);
        $changeout = $this->submit($this->member);
        $this->actingAs($this->fleetViewer());

        $this->get('/admin/apparatus-changeouts')->assertOk();
        $this->get('/admin/apparatus-changeouts/'.$changeout->id)->assertOk();
        $this->get('/admin/apparatus-operational-alerts')->assertOk();
        $this->get('/admin/apparatus-operational-alerts/'.$alert->id)->assertOk();
        $this->get('/admin/apparatus-operational-alerts/create')->assertForbidden();
        $this->get('/admin/apparatus-operational-alerts/'.$alert->id.'/edit')->assertForbidden();

        Livewire::test(ListApparatusChangeouts::class)->call('loadTable')->assertCanSeeTableRecords([$changeout]);
        Livewire::test(ViewApparatusChangeout::class, ['record' => $changeout->getRouteKey()])
            ->assertActionHidden('acknowledgeChangeout');

        Livewire::test(ListApparatusOperationalAlerts::class)->call('loadTable')->assertCanSeeTableRecords([$alert]);
        Livewire::test(ViewApparatusOperationalAlert::class, ['record' => $alert->getRouteKey()])
            ->assertActionHidden('edit')
            ->assertActionHidden('closeAlert')
            ->assertActionHidden('cancelAlert');

        Livewire::test(ViewApparatus::class, ['record' => $this->unit->getRouteKey()])
            ->assertActionHidden('scheduleChangeout')
            ->assertActionHidden('addOperationalAlert');
    }

    public function test_fleet_manage_can_act(): void
    {
        $alert = $this->alertFor($this->frontline);
        $changeout = $this->submit($this->member);
        $this->actingAs($this->fleetOperationsManager());

        Livewire::test(ViewApparatusChangeout::class, ['record' => $changeout->getRouteKey()])
            ->assertActionVisible('acknowledgeChangeout');
        Livewire::test(ViewApparatusOperationalAlert::class, ['record' => $alert->getRouteKey()])
            ->assertActionVisible('edit')
            ->assertActionVisible('closeAlert')
            ->assertActionVisible('cancelAlert');
        Livewire::test(ViewApparatus::class, ['record' => $this->unit->getRouteKey()])
            ->assertActionVisible('scheduleChangeout')
            ->assertActionVisible('addOperationalAlert');
        $this->get('/admin/apparatus-operational-alerts/create')->assertOk();
    }

    public function test_badges_count_the_active_alerts_and_the_unacknowledged_changes_from_one_query(): void
    {
        $this->alertFor($this->frontline);
        $closed = $this->alertFor($this->reserve);
        app(\App\Services\Fleet\ApparatusOperationalAlertService::class)->close($closed, $this->fleetOperationsManager());
        $this->submit($this->member);
        $this->actingAs($this->fleetViewer());
        request()->attributes->remove('fleet.queue-counts');

        $queries = [];
        \Illuminate\Support\Facades\DB::listen(function ($query) use (&$queries): void {
            if (str_contains($query->sql, 'apparatus_operational_alerts') || str_contains($query->sql, 'apparatus_changeouts')) {
                $queries[] = $query->sql;
            }
        });

        $this->assertSame('1', ApparatusOperationalAlertResource::getNavigationBadge());
        $this->assertSame('1', ApparatusChangeoutResource::getNavigationBadge());
        $this->assertSame('1', ApparatusChangeoutResource::getNavigationBadge());
        $this->assertCount(1, $queries, 'Both badges and every redraw share one query per request.');
    }

    public function test_lists_put_active_alerts_and_unacknowledged_changes_first(): void
    {
        $manager = $this->fleetOperationsManager();
        $service = app(\App\Services\Fleet\ApparatusOperationalAlertService::class);
        $open = $this->alertFor($this->frontline);
        $closed = $this->alertFor($this->unit);
        $service->close($closed, $manager);

        $unseen = $this->submit($this->member);
        $changeouts = app(\App\Services\Fleet\ApparatusChangeoutService::class);
        $seen = $changeouts->submit($this->reserve->fresh(), $this->member, [
            'client_submission_id' => (string) \Illuminate\Support\Str::uuid(), 'expected_vehicle_number' => 'V-909', 'expected_unit_slug' => 'e2', 'to_apparatus_id' => $this->unit->id,
        ])['changeout'];
        $changeouts->acknowledge($seen->id, $manager);
        $this->actingAs($manager);

        $alertIds = Livewire::test(ListApparatusOperationalAlerts::class)->call('loadTable')->instance()->getTableRecords()->pluck('id')->all();
        $this->assertSame([$open->id, $closed->id], $alertIds, 'The closed alert is newer, and active still comes first.');

        $changeoutIds = Livewire::test(ListApparatusChangeouts::class)->call('loadTable')->instance()->getTableRecords()->pluck('id')->all();
        $this->assertSame([$unseen->id, $seen->id], $changeoutIds);
    }

    public function test_alert_filters_by_status_kind_and_station_including_extra_stations(): void
    {
        $extra = $this->frontline->station_id;
        $swap = $this->alertFor($this->unit, ['additional_station_ids' => [$extra]]);
        $recall = $this->alertFor($this->reserve, ['category' => ApparatusOperationalAlert::CATEGORY_RECALL, 'title' => 'Recall on the pump']);
        $stationOfNeither = \App\Models\Station::query()->create(['station_number' => 9, 'name' => 'Station 9', 'address' => '9 Test Way', 'is_active' => true]);
        $this->actingAs($this->fleetViewer());

        Livewire::test(ListApparatusOperationalAlerts::class)->call('loadTable')
            ->filterTable('category', ApparatusOperationalAlert::CATEGORY_RECALL)
            ->assertCanSeeTableRecords([$recall])->assertCanNotSeeTableRecords([$swap])
            ->resetTableFilters()
            ->filterTable('status', ApparatusOperationalAlert::STATUS_CLOSED)
            ->assertCanNotSeeTableRecords([$swap, $recall])
            ->resetTableFilters()
            ->filterTable('station', $this->unit->station_id)
            ->assertCanSeeTableRecords([$swap])->assertCanNotSeeTableRecords([$recall])
            ->resetTableFilters()
            ->filterTable('station', $extra)
            ->assertCanSeeTableRecords([$swap, $recall])
            ->resetTableFilters()
            ->filterTable('station', $stationOfNeither->id)
            ->assertCanNotSeeTableRecords([$swap, $recall]);
    }
}
