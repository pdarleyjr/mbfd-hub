<?php

declare(strict_types=1);

namespace Tests\Feature\Fleet\Changeouts;

use App\Support\HubTimezone;

/** A scheduled change is carried out by any changeout of that unit from that truck, whichever truck they chose. */
final class AlertAttachmentTest extends ChangeoutTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->useSmallChecklists();
    }

    public function test_the_alert_closes_on_any_truck_for_the_same_unit(): void
    {
        $alert = $this->swapAlert($this->unit, ['planned_replacement_apparatus_id' => $this->frontline->id]);

        $response = $this->moveToReserve()->assertCreated();

        $response->assertJsonPath('changeout.alert_id', $alert->id)->assertJsonPath('changeout.alert_completed', true);
        $fresh = $alert->fresh();
        $this->assertSame(['completed', $response->json('changeout.id')], [$fresh->status, $fresh->completed_by_changeout_id]);
        $this->assertSame('E3', $this->frontline->fresh()->designation, 'The suggested truck is only a suggestion.');
    }

    public function test_the_alert_closes_when_the_crew_names_it_too(): void
    {
        $alert = $this->swapAlert($this->unit);

        $this->moveToReserve(['alert_id' => $alert->id])->assertCreated()->assertJsonPath('changeout.alert_completed', true);

        $this->assertSame('completed', $alert->fresh()->status);
    }

    public function test_a_changeout_for_a_different_unit_never_closes_it(): void
    {
        $alert = $this->swapAlert($this->unit);

        $this->postChangeout($this->frontline, ['to_apparatus_id' => $this->reserve->id])->assertCreated()
            ->assertJsonPath('changeout.alert_id', null)->assertJsonPath('changeout.alert_completed', false);

        $this->assertSame('active', $alert->fresh()->status);
    }

    public function test_naming_another_units_alert_goes_through_and_closes_nothing(): void
    {
        $other = $this->swapAlert($this->frontline);

        $this->moveToReserve(['alert_id' => $other->id])->assertCreated()->assertJsonPath('changeout.alert_id', null);

        $this->assertSame('active', $other->fresh()->status);
    }

    public function test_a_closed_or_unknown_alert_id_goes_through(): void
    {
        $alert = $this->swapAlert($this->unit);
        $alert->forceFill(['status' => 'closed'])->save();

        $this->moveToReserve(['alert_id' => $alert->id])->assertCreated()->assertJsonPath('changeout.alert_id', null);
        $this->postChangeout($this->frontline, ['alert_id' => 999999, 'to_apparatus_id' => $this->unit->id])->assertCreated();
    }

    public function test_a_notice_of_another_kind_is_not_a_scheduled_change(): void
    {
        $notice = $this->swapAlert($this->unit, ['category' => 'general']);

        $this->moveToReserve(['alert_id' => $notice->id])->assertCreated()->assertJsonPath('changeout.alert_id', null);

        $this->assertSame('active', $notice->fresh()->status);
    }

    public function test_there_is_no_date_gate(): void
    {
        $alert = $this->swapAlert($this->unit, ['scheduled_date' => HubTimezone::today()->addDays(30)->toDateString()]);

        $this->moveToReserve()->assertCreated()->assertJsonPath('changeout.status', 'completed')->assertJsonPath('changeout.alert_id', $alert->id);
    }

    public function test_the_ticket_on_the_alert_stays_open(): void
    {
        $ticket = $this->openTicketOn($this->unit);
        $alert = $this->swapAlert($this->unit, ['apparatus_service_ticket_id' => $ticket->id]);

        $this->moveToReserve()->assertCreated();

        $this->assertSame('completed', $alert->fresh()->status);
        $this->assertSame('submitted', $ticket->fresh()->status);
    }

    public function test_the_alert_stays_with_the_unit_when_another_crew_trades_onto_its_truck(): void
    {
        $alert = $this->swapAlert($this->unit);

        $this->postChangeout($this->frontline, ['to_apparatus_id' => $this->unit->id])->assertCreated();

        $this->assertSame('active', $alert->fresh()->status);
        $this->assertSame('E2', $this->frontline->fresh()->designation);
        $this->getJson("/api/public/apparatuses/{$this->frontline->id}/changeout-context")->assertOk()
            ->assertJsonPath('mode', 'handoff_only')->assertJsonPath('alert.id', $alert->id);
    }

    public function test_the_context_says_which_truck_is_suggested(): void
    {
        $alert = $this->swapAlert($this->unit, ['planned_replacement_apparatus_id' => $this->reserve->id, 'reason' => 'Exhaust repair']);

        $this->getJson("/api/public/apparatuses/{$this->unit->id}/changeout-context")->assertOk()
            ->assertJsonPath('alert.id', $alert->id)
            ->assertJsonPath('alert.planned_replacement_apparatus_id', $this->reserve->id)
            ->assertJsonPath('alert.vehicle_number', 'V-202')
            ->assertJsonPath('alert.changeout_id', null);
    }
}
