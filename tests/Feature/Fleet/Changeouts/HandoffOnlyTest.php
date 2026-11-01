<?php

declare(strict_types=1);

namespace Tests\Feature\Fleet\Changeouts;

use App\Models\ApparatusChangeout;
use App\Models\ApparatusFleetEvent;
use App\Models\ApparatusOperationalAlert;
use App\Models\ApparatusOperationalStatusEvent;
use App\Services\Fleet\VehicleSwapService;
use Illuminate\Support\Str;

/**
 * Section 4.4: Fleet approved a Daily Checkout swap report first, so the identity is already on the new truck. The
 * crew records the equipment against the existing pair, with no move. These run against the real templates, since a
 * held checkout has to pass the real checklist.
 */
final class HandoffOnlyTest extends ChangeoutTestCase
{
    private ApparatusOperationalAlert $alert;

    protected function setUp(): void
    {
        parent::setUp();
        $this->alert = $this->swapAlert($this->unit);
        $swap = $this->heldRequest(withFinding: false);
        app(VehicleSwapService::class)->decide($swap->id, $this->fleetOperationsManager(), VehicleSwapService::DECISION_APPROVE_SWAP);
        $this->actingAsCanonicalUser($this->member);
    }

    public function test_decide_moved_the_unit_and_left_the_alert_open(): void
    {
        $this->assertSame('E2', $this->frontline->fresh()->designation);
        $this->assertSame('active', $this->alert->fresh()->status);
    }

    public function test_the_context_offers_only_the_handoff(): void
    {
        $this->getJson("/api/public/apparatuses/{$this->frontline->id}/changeout-context?alert_id={$this->alert->id}")
            ->assertOk()
            ->assertJsonPath('mode', 'handoff_only')
            ->assertJsonPath('unit.apparatus_id', $this->frontline->id)
            ->assertJsonPath('unit.designation', 'E2')
            ->assertJsonPath('unit.vehicle_number', 'V-303')
            ->assertJsonPath('alert.vehicle_number', 'V-202')
            ->assertJsonPath('candidates', [])
            ->assertJsonPath('other_vehicles', []);
    }

    public function test_the_handoff_records_the_existing_pair_with_no_move(): void
    {
        $statusEvents = ApparatusOperationalStatusEvent::query()->count();
        $identity = static fn (array $row): array => array_diff_key($row, array_flip(['status', 'updated_at']));
        $before = [$identity($this->unit->fresh()->getAttributes()), $identity($this->frontline->fresh()->getAttributes())];

        $response = $this->postChangeout($this->frontline, ['alert_id' => $this->alert->id]);

        $response->assertCreated()
            ->assertJsonPath('changeout.status', 'completed')
            ->assertJsonPath('changeout.alert_completed', true)
            ->assertJsonPath('changeout.old_truck_status', 'Out of Service')
            ->assertJsonPath('changeout.from_vehicle_number', 'V-202')
            ->assertJsonPath('changeout.to_vehicle_number', 'V-303')
            ->assertJsonPath('changeout.unit_designation', 'E2')
            ->assertJsonPath('changeout.next_daily_checkout_path', '/daily/apparatus/e2');
        $changeout = $this->changeout($response);
        $this->assertSame([$this->unit->id, $this->frontline->id, 'e2'], [$changeout->from_apparatus_id, $changeout->to_apparatus_id, $changeout->unit_slug]);
        $this->assertSame($before, [$identity($this->unit->fresh()->getAttributes()), $identity($this->frontline->fresh()->getAttributes())], 'No identity moves.');
        $this->assertSame('Out of Service', $this->unit->fresh()->getAttribute('status'), 'The truck they left is Out of Service, as on every swap.');
        $this->assertSame($statusEvents + 1, ApparatusOperationalStatusEvent::query()->count());
        $this->assertTrue(ApparatusFleetEvent::query()->where('changeout_id', $changeout->id)->where('event', 'handoff_only')->exists());
        $this->assertFalse(ApparatusFleetEvent::query()->where('changeout_id', $changeout->id)->where('event', 'committed')->exists());
        $this->assertNull($changeout->move_audit['before']);
        $this->assertSame('handoff_only', $changeout->move_audit['mode']);
        $this->assertSame('completed', $this->alert->fresh()->status);
        $this->assertStringContainsString('recorded', $response->json('changeout.message'));
    }

    public function test_a_page_still_open_on_the_old_truck_reaches_the_same_handoff(): void
    {
        $this->getJson("/api/public/apparatuses/{$this->unit->id}/changeout-context?alert_id={$this->alert->id}")
            ->assertOk()->assertJsonPath('mode', 'handoff_only')->assertJsonPath('unit.apparatus_id', $this->frontline->id);

        $response = $this->postJson("/api/public/apparatuses/{$this->unit->id}/changeouts", [
            'client_submission_id' => (string) Str::uuid(), 'alert_id' => $this->alert->id, 'expected_vehicle_number' => 'V-303', 'expected_unit_slug' => 'e2',
        ]);

        $response->assertCreated()->assertJsonPath('changeout.alert_completed', true)->assertJsonPath('changeout.to_vehicle_number', 'V-303');
    }

    public function test_a_screen_that_still_expects_the_old_truck_is_told_where_the_unit_is(): void
    {
        $this->postJson("/api/public/apparatuses/{$this->unit->id}/changeouts", [
            'client_submission_id' => (string) Str::uuid(), 'alert_id' => $this->alert->id,
            'expected_vehicle_number' => 'V-202', 'expected_unit_slug' => 'e2', 'to_apparatus_id' => $this->reserve->id,
        ])->assertStatus(409)
            ->assertJsonPath('code', 'CHANGEOUT_STALE')
            ->assertJsonPath('current_vehicle_number', 'V-303')
            ->assertJsonPath('current_apparatus_id', $this->frontline->id);

        $this->assertSame(0, ApparatusChangeout::query()->count());
    }

    public function test_the_alert_does_not_go_with_a_move_on_to_a_third_truck(): void
    {
        $this->postChangeout($this->frontline, ['alert_id' => $this->alert->id, 'to_apparatus_id' => $this->reserve->id])
            ->assertCreated()->assertJsonPath('changeout.status', 'completed')->assertJsonPath('changeout.alert_id', null);

        $this->assertSame('E2', $this->reserve->fresh()->designation);
        $this->assertSame('active', $this->alert->fresh()->status, 'It was raised from another truck.');
    }

    public function test_a_replay_of_the_handoff_returns_the_first_result(): void
    {
        $id = (string) Str::uuid();
        $payload = ['client_submission_id' => $id, 'alert_id' => $this->alert->id];

        $first = $this->postChangeout($this->frontline, $payload)->assertCreated();
        $this->postChangeout($this->frontline, $payload)->assertOk()->assertJsonPath('replayed', true)->assertJsonPath('changeout.id', $first->json('changeout.id'));

        $this->assertSame(1, ApparatusChangeout::query()->count());
    }
}
