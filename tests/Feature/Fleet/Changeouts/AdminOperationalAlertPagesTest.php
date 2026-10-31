<?php

declare(strict_types=1);

namespace Tests\Feature\Fleet\Changeouts;

use App\Filament\Resources\ApparatusOperationalAlertResource\Pages\CreateApparatusOperationalAlert;
use App\Filament\Resources\ApparatusOperationalAlertResource\Pages\EditApparatusOperationalAlert;
use App\Filament\Resources\ApparatusOperationalAlertResource\Pages\ListApparatusOperationalAlerts;
use App\Filament\Resources\ApparatusOperationalAlertResource\Pages\ViewApparatusOperationalAlert;
use App\Filament\Resources\ApparatusResource\Pages\ListApparatuses;
use App\Filament\Resources\ApparatusResource\Pages\ViewApparatus;
use App\Models\ApparatusFleetEvent;
use App\Models\ApparatusOperationalAlert;
use App\Support\HubTimezone;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

final class AdminOperationalAlertPagesTest extends AdminChangeoutTestCase
{
    public function test_scheduling_from_the_apparatus_page_creates_one_alert_and_changes_no_truck(): void
    {
        $this->actingAs($this->fleetOperationsManager());
        $before = $this->trucksNow();
        $date = $this->futureDate(3);

        Livewire::test(ViewApparatus::class, ['record' => $this->unit->getRouteKey()])
            ->callAction('scheduleChangeout', data: [
                'reason' => 'Exhaust repair',
                'scheduled_date' => $date,
                'scheduled_window' => 'afternoon',
                'planned_replacement_apparatus_id' => $this->reserve->id,
                'old_truck_status_after' => 'Maintenance',
            ])
            ->assertHasNoActionErrors();

        $alert = ApparatusOperationalAlert::query()->sole();
        $this->assertSame(ApparatusOperationalAlert::CATEGORY_UPCOMING_SWAP, $alert->category);
        $this->assertSame('Upcoming Apparatus Changeout: E2', $alert->title, 'The title is filled in from the designation.');
        $this->assertSame('e2', strtolower($alert->unit_slug));
        $this->assertSame('V-202', $alert->vehicle_number);
        $this->assertSame($this->reserve->id, $alert->planned_replacement_apparatus_id);
        $this->assertSame('Maintenance', $alert->old_truck_status_after);
        $this->assertSame($before, $this->trucksNow(), 'A planned changeout is not a completed swap.');
    }

    public function test_a_date_only_schedule_never_gains_a_time(): void
    {
        $this->actingAs($this->fleetOperationsManager());
        $date = $this->futureDate();

        Livewire::test(ViewApparatus::class, ['record' => $this->unit->getRouteKey()])
            ->callAction('scheduleChangeout', data: ['reason' => 'Repair', 'scheduled_date' => $date])
            ->assertHasNoActionErrors();

        $alert = ApparatusOperationalAlert::query()->sole();
        $this->assertNull($alert->scheduled_at);
        $this->assertNull($alert->scheduled_window);
        $this->assertSame($date, $alert->scheduled_date->format('Y-m-d'));
        $this->assertStringNotContainsString(':', (string) $alert->scheduleLabel());
    }

    public function test_a_past_date_is_refused_in_hub_time(): void
    {
        $this->actingAs($this->fleetOperationsManager());

        Livewire::test(ViewApparatus::class, ['record' => $this->unit->getRouteKey()])
            ->callAction('scheduleChangeout', data: ['reason' => 'Repair', 'scheduled_date' => HubTimezone::today()->subDay()->format('Y-m-d')])
            ->assertHasActionErrors(['scheduled_date']);

        $this->assertSame(0, ApparatusOperationalAlert::query()->count());
    }

    public function test_sending_the_same_form_twice_keeps_one_alert(): void
    {
        $this->actingAs($this->fleetOperationsManager());
        $data = ['dedupe_key' => 'schedule-changeout:fixed', 'reason' => 'Repair', 'scheduled_date' => $this->futureDate()];

        foreach ([1, 2] as $attempt) {
            Livewire::test(ViewApparatus::class, ['record' => $this->unit->getRouteKey()])
                ->callAction('scheduleChangeout', data: $data)
                ->assertHasNoActionErrors();
        }

        $this->assertSame(1, ApparatusOperationalAlert::query()->count());
    }

    public function test_the_apparatus_list_offers_the_same_actions_and_adds_no_query_per_row(): void
    {
        $this->actingAs($this->fleetOperationsManager());
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            // The sidebar badge is one query per HTTP request, and is counted in GoLiveCapacityProbeTest.
            if ((str_contains($query->sql, 'apparatus_operational_alerts') || str_contains($query->sql, 'apparatus_changeouts'))
                && ! str_contains($query->sql, 'active_alerts')) {
                $queries[] = $query->sql;
            }
        });

        $list = Livewire::test(ListApparatuses::class)->call('loadTable')
            ->assertTableActionVisible('scheduleChangeout', $this->unit)
            ->assertTableActionVisible('addOperationalAlert', $this->unit);
        $this->assertSame([], $queries, 'Drawing the rows reads no alert or changeout tables.');

        $list->callTableAction('addOperationalAlert', $this->unit, data: [
            'category' => ApparatusOperationalAlert::CATEGORY_RECALL,
            'title' => 'Recall on the pump',
            'message' => 'Check the pump panel.',
        ])->assertHasNoTableActionErrors();
        $this->assertSame(ApparatusOperationalAlert::CATEGORY_RECALL, ApparatusOperationalAlert::query()->sole()->category);
    }

    public function test_the_create_page_saves_through_the_service_and_keeps_the_exact_time_as_eastern(): void
    {
        $manager = $this->fleetOperationsManager();
        $this->actingAs($manager);

        Livewire::test(CreateApparatusOperationalAlert::class)
            ->fillForm([
                'apparatus_id' => $this->unit->id,
                'category' => ApparatusOperationalAlert::CATEGORY_MAINTENANCE,
                'title' => 'Pump service',
                'message' => 'Pump service at Fleet.',
                'scheduled_at' => '2026-11-03 15:00:00',
                'additional_station_ids' => [$this->frontline->station_id],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $alert = ApparatusOperationalAlert::query()->sole();
        $this->assertSame('2026-11-03T20:00:00+00:00', $alert->scheduled_at->utc()->toIso8601String(), '3:00 PM Eastern on November 3 is 8:00 PM UTC.');
        $this->assertSame([$this->frontline->station_id], $alert->additional_station_ids);
        $this->assertSame($manager->id, $alert->created_by_user_id);
        $this->assertSame(1, ApparatusFleetEvent::query()->where('alert_id', $alert->id)->where('event', 'alert_created')->count());
    }

    public function test_the_edit_page_saves_through_the_service_and_shows_up_in_history(): void
    {
        $alert = $this->alertFor($this->unit);
        $this->actingAs($this->fleetOperationsManager());
        $date = $this->futureDate(5);

        Livewire::test(EditApparatusOperationalAlert::class, ['record' => $alert->getRouteKey()])
            ->assertFormSet(['title' => $alert->title, 'scheduled_date' => $alert->scheduled_date->format('Y-m-d')])
            ->fillForm([
                'title' => 'Changeout moved to later this week',
                'reason' => 'Parts delayed',
                'scheduled_date' => $date,
                'scheduled_window' => 'morning',
                'instructions' => 'Call Fleet first.',
                'planned_replacement_apparatus_id' => $this->reserve->id,
                'old_truck_status_after' => 'Out of Service',
                'additional_station_ids' => [$this->frontline->station_id],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $fresh = $alert->fresh();
        $this->assertSame('Changeout moved to later this week', $fresh->title);
        $this->assertSame($date, $fresh->scheduled_date->format('Y-m-d'));
        $this->assertNull($fresh->scheduled_at);
        $this->assertSame('Out of Service', $fresh->old_truck_status_after);
        $this->assertSame(ApparatusOperationalAlert::CATEGORY_UPCOMING_SWAP, $fresh->category, 'The kind of alert is fixed once created.');

        Livewire::test(ViewApparatusOperationalAlert::class, ['record' => $alert->getRouteKey()])
            ->assertSuccessful()
            ->assertSee('Alert edited')
            ->assertSee('Alert created');
    }

    public function test_cancel_and_close_end_the_alert_with_a_note_and_stay_in_history(): void
    {
        $manager = $this->fleetOperationsManager();
        $cancelled = $this->alertFor($this->unit);
        $closed = $this->alertFor($this->frontline);
        $before = $this->trucksNow();
        $this->actingAs($manager);

        Livewire::test(ViewApparatusOperationalAlert::class, ['record' => $cancelled->getRouteKey()])
            ->callAction('cancelAlert', data: ['note' => 'Plan changed.'])
            ->assertHasNoActionErrors()
            ->assertActionHidden('cancelAlert')
            ->assertActionHidden('closeAlert')
            ->assertActionHidden('edit')
            ->assertSee('Plan changed.');
        Livewire::test(ViewApparatusOperationalAlert::class, ['record' => $closed->getRouteKey()])
            ->callAction('closeAlert', data: ['note' => 'Done by phone.'])
            ->assertHasNoActionErrors();

        $this->assertSame(ApparatusOperationalAlert::STATUS_CANCELLED, $cancelled->fresh()->status);
        $this->assertSame('Plan changed.', $cancelled->fresh()->close_note);
        $this->assertSame(ApparatusOperationalAlert::STATUS_CLOSED, $closed->fresh()->status);
        $this->assertSame($manager->id, $closed->fresh()->closed_by_user_id);
        $this->assertSame($before, $this->trucksNow());
        $this->get('/admin/apparatus-operational-alerts/'.$closed->id.'/edit')->assertForbidden();
    }

    public function test_export_works_with_the_custom_sort(): void
    {
        $this->alertFor($this->unit);
        $this->actingAs($this->fleetViewer());

        foreach (['xlsx' => 'PK', 'pdf' => '%PDF'] as $format => $magic) {
            $download = Livewire::test(ListApparatusOperationalAlerts::class)->call('loadTable')
                ->callTableAction('export', data: ['format' => $format, 'scope' => 'filtered', 'include_toggled_off' => true])
                ->assertHasNoTableActionErrors();
            $this->assertStringStartsWith($magic, base64_decode((string) $download->effects['download']['content'], true));
        }
    }
}
