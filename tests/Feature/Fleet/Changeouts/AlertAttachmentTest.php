<?php

declare(strict_types=1);

namespace Tests\Feature\Fleet\Changeouts;

use App\Models\ApparatusChangeout;
use App\Services\Fleet\ApparatusChangeoutService;
use App\Services\Fleet\ApparatusOperationalAlertService;
use App\Services\Fleet\VehicleSwapService;
use App\Support\HubTimezone;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use ReflectionMethod;

/**
 * An alert belongs to the move Fleet planned, on the day Fleet planned it. Anything else stays open for Fleet.
 */
final class AlertAttachmentTest extends ChangeoutTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->useSmallChecklists();
    }

    public function test_a_named_alert_for_a_different_planned_truck_is_refused(): void
    {
        $alert = $this->swapAlert($this->unit, ['planned_replacement_apparatus_id' => $this->reserveEngine('V-910')->id]);

        $this->moveToReserve(['alert_id' => $alert->id])->assertUnprocessable()->assertJsonValidationErrors('alert_id');
        $this->postChangeout($this->unit, ['alert_id' => $alert->id, 'to_vehicle_number' => 'NEW-77'])->assertUnprocessable()->assertJsonValidationErrors('alert_id');

        $this->assertSame(0, ApparatusChangeout::query()->count());
    }

    public function test_a_named_alert_raised_on_another_truck_is_refused_for_a_move(): void
    {
        $alert = $this->swapAlert($this->unit);
        $this->actAsFleet();
        $this->moveToReserve()->assertCreated(); // Fleet moved the unit, and the alert went with it.
        $this->assertSame('completed', $alert->fresh()->status);

        $second = $this->swapAlert($this->reserve->fresh());
        $second->forceFill(['apparatus_id' => $this->frontline->id])->save();
        $other = $this->reserveEngine('V-910');

        $this->postChangeout($this->reserve, ['alert_id' => $second->id, 'to_apparatus_id' => $other->id])->assertUnprocessable()->assertJsonValidationErrors('alert_id');
    }

    public function test_a_return_does_not_complete_an_alert_planned_for_a_different_truck(): void
    {
        $this->moveToReserve(['alert_id' => $this->swapAlert($this->unit)->id])->assertCreated();
        $planned = $this->reserveEngine('V-910');
        $alert = $this->swapAlert($this->reserve->fresh(), ['planned_replacement_apparatus_id' => $planned->id]);

        $back = $this->postChangeout($this->reserve, ['to_apparatus_id' => $this->unit->id]);

        $back->assertCreated()->assertJsonPath('changeout.status', 'completed')->assertJsonPath('changeout.alert_id', null)->assertJsonPath('changeout.alert_completed', false);
        $this->assertSame('E2', $this->unit->fresh()->designation);
        $this->assertSame('active', $alert->fresh()->status);
    }

    public function test_an_approval_onto_a_truck_the_alert_did_not_plan_does_not_complete_it(): void
    {
        $alert = $this->swapAlert($this->unit, ['scheduled_date' => HubTimezone::today()->addDays(2)->toDateString(), 'old_truck_status_after' => 'Maintenance']);
        $changeout = $this->changeout($this->moveToReserve(['alert_id' => $alert->id])->assertCreated()->assertJsonPath('changeout.status', 'pending_fleet'));
        // Fleet replans the alert after the crew sent this.
        $alert->update(['planned_replacement_apparatus_id' => $this->reserveEngine('V-910')->id]);

        $this->service()->decide($changeout->id, $this->fleetOperationsManager(), 'approve');

        $this->assertSame('active', $alert->fresh()->status);
        $this->assertSame('Reserve', $this->unit->fresh()->getAttribute('status'), 'The status it set belongs to the planned move.');
    }

    public function test_an_unrelated_approved_swap_does_not_offer_a_handoff_against_a_planned_truck(): void
    {
        $planned = $this->reserveEngine('V-910');
        $alert = $this->swapAlert($this->unit, ['planned_replacement_apparatus_id' => $planned->id]);
        $swap = $this->heldRequest(withFinding: false);
        app(VehicleSwapService::class)->decide($swap->id, $this->fleetOperationsManager(), VehicleSwapService::DECISION_APPROVE_SWAP);
        $this->actingAsCanonicalUser($this->member);

        $this->getJson("/api/public/apparatuses/{$this->frontline->id}/changeout-context?alert_id={$alert->id}")
            ->assertOk()->assertJsonPath('mode', 'start');
        $this->postChangeout($this->frontline, ['alert_id' => $alert->id])->assertUnprocessable()->assertJsonValidationErrors('alert_id');

        $card = app(ApparatusOperationalAlertService::class)->forStation((int) $alert->station_id, $this->member)[0];
        $this->assertSame('none', $card['action']['kind']);
        $this->assertSame('active', $alert->fresh()->status);
    }

    public function test_the_handoff_is_offered_when_the_unit_reached_the_planned_truck_by_a_decision(): void
    {
        $alert = $this->swapAlert($this->unit, ['planned_replacement_apparatus_id' => $this->frontline->id]);
        $swap = $this->heldRequest(withFinding: false);
        app(VehicleSwapService::class)->decide($swap->id, $this->fleetOperationsManager(), VehicleSwapService::DECISION_APPROVE_SWAP);
        $this->actingAsCanonicalUser($this->member);

        $this->getJson("/api/public/apparatuses/{$this->frontline->id}/changeout-context?alert_id={$alert->id}")->assertOk()->assertJsonPath('mode', 'handoff_only');
        $this->assertSame('handoff', app(ApparatusOperationalAlertService::class)->forStation((int) $alert->station_id, $this->member)[0]['action']['kind']);
    }

    public function test_a_member_waits_for_fleet_before_the_scheduled_day(): void
    {
        $alert = $this->swapAlert($this->unit, ['scheduled_date' => HubTimezone::today()->addDay()->toDateString()]);

        $context = $this->getJson("/api/public/apparatuses/{$this->unit->id}/changeout-context?alert_id={$alert->id}")->assertOk();
        $this->assertFalse(collect($context->json('candidates'))->firstWhere('apparatus_id', $this->reserve->id)['direct']);

        $this->moveToReserve(['alert_id' => $alert->id])->assertCreated()->assertJsonPath('changeout.status', 'pending_fleet')->assertJsonPath('changeout.alert_id', $alert->id);
        $this->assertSame('E2', $this->unit->fresh()->designation);
    }

    public function test_a_fleet_manager_does_not_wait_for_the_day(): void
    {
        $alert = $this->swapAlert($this->unit, ['scheduled_date' => HubTimezone::today()->addDays(5)->toDateString()]);
        $this->actAsFleet();

        $this->moveToReserve(['alert_id' => $alert->id])->assertCreated()->assertJsonPath('changeout.status', 'completed');
    }

    public function test_the_day_is_the_eastern_day_not_the_utc_day(): void
    {
        // 01:00 UTC on November 1 is still the evening of October 31 in Miami Beach.
        $this->travelTo(CarbonImmutable::parse('2026-11-01 01:00:00', 'UTC'));
        $this->actingAsCanonicalUser($this->member);
        $alert = $this->swapAlert($this->unit, ['scheduled_date' => '2026-11-01']);

        $this->moveToReserve(['alert_id' => $alert->id])->assertCreated()->assertJsonPath('changeout.status', 'pending_fleet');
    }

    public function test_the_scheduled_day_itself_and_a_passed_day_are_direct(): void
    {
        $this->moveToReserve(['alert_id' => $this->swapAlert($this->unit)->id])->assertCreated()->assertJsonPath('changeout.status', 'completed');

        $other = $this->reserveEngine('V-910');
        $passed = $this->swapAlert($this->reserve->fresh(), ['scheduled_date' => HubTimezone::today()->subDays(4)->toDateString()]);
        $this->postChangeout($this->reserve, ['alert_id' => $passed->id, 'to_apparatus_id' => $other->id])->assertCreated()->assertJsonPath('changeout.status', 'completed');
    }

    public function test_an_exact_time_counts_for_its_whole_day(): void
    {
        $laterToday = $this->swapAlert($this->unit, ['scheduled_date' => null, 'scheduled_at' => HubTimezone::today()->setTime(23, 59)->toDateTimeString()]);
        $this->moveToReserve(['alert_id' => $laterToday->id])->assertCreated()->assertJsonPath('changeout.status', 'completed');

        $other = $this->reserveEngine('V-910');
        $tomorrow = $this->swapAlert($this->reserve->fresh(), ['scheduled_date' => null, 'scheduled_at' => HubTimezone::today()->addDay()->setTime(0, 5)->toDateTimeString()]);
        $this->postChangeout($this->reserve, ['alert_id' => $tomorrow->id, 'to_apparatus_id' => $other->id])->assertCreated()->assertJsonPath('changeout.status', 'pending_fleet');
    }

    public function test_an_alert_with_no_date_has_no_wait(): void
    {
        $alert = $this->swapAlert($this->unit, ['scheduled_date' => null]);

        $this->moveToReserve(['alert_id' => $alert->id])->assertCreated()->assertJsonPath('changeout.status', 'completed');
    }

    public function test_a_send_that_won_while_this_one_waited_on_the_locks_is_a_replay(): void
    {
        $service = $this->service();
        $payload = ['client_submission_id' => (string) Str::uuid(), 'expected_vehicle_number' => 'V-202', 'expected_unit_slug' => 'e2', 'to_apparatus_id' => $this->reserve->id];
        $normalized = new ReflectionMethod(ApparatusChangeoutService::class, 'normalized');
        $hash = $normalized->invoke($service, $this->unit, $this->member, $payload)['payload_hash'];
        $won = null;
        // The other send commits after this one's first look, while it is reading the trucks.
        Event::listen('eloquent.retrieved: App\Models\Apparatus', function () use (&$won, $payload, $hash): void {
            $won ??= ApparatusChangeout::query()->create([
                'client_submission_id' => $payload['client_submission_id'], 'payload_hash' => $hash, 'unit_slug' => 'e2', 'unit_designation' => 'E2',
                'from_apparatus_id' => $this->unit->id, 'to_apparatus_id' => $this->reserve->id, 'to_vehicle_number' => 'V-909',
                'status' => ApparatusChangeout::STATUS_COMPLETED, 'handoff_status' => ApparatusChangeout::HANDOFF_COMPLETE, 'submitted_by_name' => 'First send',
            ]);
        });

        $result = $service->submit($this->unit, $this->member, $payload);

        $this->assertTrue($result['replayed']);
        $this->assertFalse($result['created']);
        $this->assertSame($won->id, $result['changeout']->id);
        $this->assertSame(1, ApparatusChangeout::query()->count());
        $this->assertSame('E2', $this->unit->fresh()->designation, 'Nothing moves twice.');
    }
}
