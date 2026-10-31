<?php

declare(strict_types=1);

namespace Tests\Feature\Fleet\Changeouts;

use App\Models\ApparatusChangeout;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Who reaches the routes, and the context shape the crew's screen is built on. The real templates, because this is
 * what production serves.
 */
final class ChangeoutApiTest extends ChangeoutTestCase
{
    /** @return array<string, array{0: string, 1: string}> */
    public static function protectedRoutes(): array
    {
        return [
            'context' => ['get', '/api/public/apparatuses/%d/changeout-context'],
            'submit' => ['post', '/api/public/apparatuses/%d/changeouts'],
            'show' => ['get', '/api/public/changeouts/%d'],
            'verify' => ['post', '/api/public/changeouts/%d/lines/1/verify'],
        ];
    }

    #[DataProvider('protectedRoutes')]
    public function test_every_route_needs_a_signed_in_member(string $method, string $path): void
    {
        $this->logoutCanonicalSession();

        $this->json($method, sprintf($path, $this->unit->id), [])->assertUnauthorized();
    }

    public function test_a_member_with_no_employee_profile_is_refused(): void
    {
        $this->actingAsCanonicalUser($this->memberWithoutProfile());

        $this->getJson("/api/public/apparatuses/{$this->unit->id}/changeout-context")->assertForbidden();
        $this->postChangeout($this->unit, ['to_apparatus_id' => $this->reserve->id])->assertForbidden();

        $this->assertSame(0, ApparatusChangeout::query()->count());
    }

    public function test_a_fleet_manager_with_no_employee_profile_is_allowed(): void
    {
        $this->actAsFleet();

        $this->getJson("/api/public/apparatuses/{$this->unit->id}/changeout-context")->assertOk()->assertJsonPath('can_fleet_commit', true);
    }

    public function test_the_service_refuses_a_member_with_no_profile_too(): void
    {
        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);

        $this->service()->submit($this->unit, $this->memberWithoutProfile(), [
            'client_submission_id' => (string) Str::uuid(), 'expected_vehicle_number' => 'V-202', 'to_apparatus_id' => $this->reserve->id,
        ]);
    }

    public function test_the_payload_is_validated_at_the_door(): void
    {
        $this->postChangeout($this->unit, ['client_submission_id' => 'not-a-uuid', 'to_apparatus_id' => $this->reserve->id])->assertUnprocessable()->assertJsonValidationErrors('client_submission_id');
        $this->postJson("/api/public/apparatuses/{$this->unit->id}/changeouts", ['client_submission_id' => (string) Str::uuid(), 'to_apparatus_id' => $this->reserve->id])->assertUnprocessable()->assertJsonValidationErrors(['expected_vehicle_number', 'expected_unit_slug']);
        $this->postChangeout($this->unit, ['to_apparatus_id' => $this->reserve->id, 'checklist_version' => null, 'exceptions' => [['item_key' => 'x', 'handling' => 'leave']]])->assertUnprocessable()->assertJsonValidationErrors('checklist_version');
        $this->postChangeout($this->unit, ['to_apparatus_id' => $this->reserve->id, 'exceptions' => [['item_key' => 'x', 'handling' => 'burn']]])->assertUnprocessable()->assertJsonValidationErrors('exceptions.0.handling');
        $this->postChangeout($this->unit, ['to_apparatus_id' => $this->reserve->id, 'exceptions' => [['item_key' => 'x', 'condition' => 'great']]])->assertUnprocessable()->assertJsonValidationErrors('exceptions.0.condition');
    }

    public function test_an_unknown_truck_in_the_url_is_not_found(): void
    {
        $this->getJson('/api/public/apparatuses/999999/changeout-context')->assertNotFound();
    }

    public function test_the_submitter_fleet_and_members_can_read_a_changeout_and_nobody_else(): void
    {
        $id = $this->postChangeout($this->unit, ['to_apparatus_id' => $this->reserve->id])->assertCreated()->json('changeout.id');

        $this->getJson("/api/public/changeouts/{$id}")->assertOk()->assertJsonPath('changeout.id', $id)->assertJsonStructure(['changeout' => ['lines' => [['id', 'item_key', 'name', 'custody_status']]]]);

        $this->actAsOtherMember();
        $this->getJson("/api/public/changeouts/{$id}")->assertOk();

        $this->actAsFleet();
        $this->getJson("/api/public/changeouts/{$id}")->assertOk();

        $this->actingAsCanonicalUser($this->memberWithoutProfile());
        $this->getJson("/api/public/changeouts/{$id}")->assertNotFound();
    }

    public function test_the_context_follows_the_contract(): void
    {
        $alert = $this->swapAlert($this->unit, ['reason' => 'Exhaust repair', 'scheduled_window' => 'afternoon']);
        $outOfService = $this->reserveEngine('V-960', ['status' => 'Out of Service']);
        $otherType = $this->rescuePair()['reserve'];
        $this->unit->openDefects()->create(['compartment' => 'Cab', 'item' => 'Seat', 'status' => 'open', 'issue_type' => 'damaged', 'reported_date' => now()->toDateString()]);

        $response = $this->getJson("/api/public/apparatuses/{$this->unit->id}/changeout-context?alert_id={$alert->id}")->assertOk();

        $response->assertJsonPath('unit.apparatus_id', $this->unit->id)
            ->assertJsonPath('unit.designation', 'E2')
            ->assertJsonPath('unit.slug', 'e2')
            ->assertJsonPath('unit.vehicle_number', 'V-202')
            ->assertJsonPath('unit.station_label', 'Station 2')
            ->assertJsonPath('unit.status', 'In Service')
            ->assertJsonPath('alert.id', $alert->id)
            ->assertJsonPath('alert.reason', 'Exhaust repair')
            ->assertJsonPath('mode', 'start')
            ->assertJsonPath('open_issues_on_truck', 1)
            ->assertJsonPath('previous_changeout', null)
            ->assertJsonPath('pending_changeout', null)
            ->assertJsonPath('can_fleet_commit', false)
            ->assertJsonPath('equipment.usable', true);
        $this->assertSame(64, strlen($response->json('equipment.checklist_version')));

        $candidates = collect($response->json('candidates'));
        $reserve = $candidates->firstWhere('apparatus_id', $this->reserve->id);
        $this->assertSame(['V-909', 'Reserve', true, false], [$reserve['vehicle_number'], $reserve['status'], $reserve['direct'], $reserve['is_return']]);
        $others = collect($response->json('other_vehicles'));
        $this->assertSame(false, $others->firstWhere('apparatus_id', $outOfService->id)['direct']);
        $this->assertNotNull($others->firstWhere('apparatus_id', $this->frontline->id));
        $this->assertNull($candidates->merge($others)->firstWhere('apparatus_id', $otherType->id), 'Another type of truck is never offered.');
        $this->assertNull($candidates->merge($others)->firstWhere('apparatus_id', $this->unit->id));

        $first = $response->json('equipment.compartments.0');
        $this->assertSame(['id', 'name', 'items'], array_keys($first));
        $this->assertSame(['item_key', 'name', 'expected_quantity', 'controlled', 'default_handling', 'previous'], array_keys($first['items'][0]));
        $this->assertSame('transfer', $first['items'][0]['default_handling']);
    }

    public function test_the_context_shows_the_waiting_change(): void
    {
        $id = $this->postChangeout($this->unit, ['to_apparatus_id' => $this->reserve->id])->assertCreated()->json('changeout.id');

        $this->getJson("/api/public/apparatuses/{$this->unit->id}/changeout-context")->assertOk()->assertJsonPath('pending_changeout.id', $id);
    }

    public function test_a_candidate_with_a_planned_move_onto_it_is_not_offered(): void
    {
        $this->swapAlert($this->frontline, ['planned_replacement_apparatus_id' => $this->reserve->id]);

        $response = $this->getJson("/api/public/apparatuses/{$this->unit->id}/changeout-context")->assertOk();

        $this->assertNull(collect($response->json('candidates'))->firstWhere('apparatus_id', $this->reserve->id));
        $this->assertNotNull(collect($response->json('other_vehicles'))->firstWhere('apparatus_id', $this->reserve->id));
    }

    public function test_the_context_refuses_an_alert_for_another_unit(): void
    {
        $alert = $this->swapAlert($this->frontline);

        $this->getJson("/api/public/apparatuses/{$this->unit->id}/changeout-context?alert_id={$alert->id}")->assertUnprocessable()->assertJsonValidationErrors('alert_id');
    }

    public function test_real_templates_v1_and_v2_both_produce_lines_with_their_own_ids(): void
    {
        $alert = $this->swapAlert($this->unit);
        $rescue = $this->rescuePair();
        $rescueAlert = $this->swapAlert($rescue['unit']);

        $engine = $this->changeout($this->postChangeout($this->unit, ['alert_id' => $alert->id, 'to_apparatus_id' => $this->reserve->id])->assertCreated());
        $rig = $this->changeout($this->postChangeout($rescue['unit'], ['alert_id' => $rescueAlert->id, 'to_apparatus_id' => $rescue['reserve']->id])->assertCreated());

        $this->assertGreaterThan(50, $engine->items()->count());
        $this->assertSame(1, $engine->items()->where('item_class', 'controlled')->count(), 'E2 carries one Med Box.');
        $this->assertSame(['Med Box', 'Narcotic Book', 'Narcotic Seal #'], $rig->items()->where('item_class', 'controlled')->orderBy('item_name')->pluck('item_name')->all());
        $this->assertSame('field:narcotic_seal', $rig->items()->where('item_name', 'Narcotic Seal #')->sole()->item_key);
        $this->assertSame('patient_compartment-item-3', $rig->items()->where('item_name', 'Med Box')->sole()->item_key);
        $this->assertSame('pending_verification', $engine->handoff_status);
    }
}
