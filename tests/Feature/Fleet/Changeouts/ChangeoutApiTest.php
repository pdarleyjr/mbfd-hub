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

        $this->getJson("/api/public/apparatuses/{$this->unit->id}/changeout-context")->assertOk()->assertJsonPath('unit.designation', 'E2');
    }

    public function test_the_service_refuses_a_member_with_no_profile_too(): void
    {
        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);

        $this->service()->submit($this->unit, $this->memberWithoutProfile(), [
            'client_submission_id' => (string) Str::uuid(), 'expected_vehicle_number' => 'V-202', 'expected_unit_slug' => 'e2', 'to_apparatus_id' => $this->reserve->id,
        ]);
    }

    public function test_the_payload_is_validated_at_the_door(): void
    {
        $this->postChangeout($this->unit, ['client_submission_id' => 'not-a-uuid', 'to_apparatus_id' => $this->reserve->id])->assertUnprocessable()->assertJsonValidationErrors('client_submission_id');
        $this->postJson("/api/public/apparatuses/{$this->unit->id}/changeouts", ['client_submission_id' => (string) Str::uuid(), 'to_apparatus_id' => $this->reserve->id])->assertUnprocessable()->assertJsonValidationErrors(['expected_vehicle_number', 'expected_unit_slug']);
        $this->postChangeout($this->unit, ['to_apparatus_id' => $this->reserve->id, 'exceptions' => [['item_key' => 'x', 'handling' => 'burn']]])->assertUnprocessable()->assertJsonValidationErrors('exceptions.0.handling');
        $this->postChangeout($this->unit, ['to_apparatus_id' => $this->reserve->id, 'exceptions' => [['item_key' => 'x', 'handling' => 'review']]])->assertUnprocessable()->assertJsonValidationErrors('exceptions.0.handling');
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
            ->assertJsonPath('equipment.usable', true);
        $this->assertSame(64, strlen($response->json('equipment.checklist_version')));

        $candidates = collect($response->json('candidates'));
        $reserve = $candidates->firstWhere('apparatus_id', $this->reserve->id);
        $this->assertSame(['apparatus_id', 'vehicle_number', 'label', 'location', 'status', 'is_return'], array_keys($reserve));
        $this->assertSame(['V-909', 'Reserve', false], [$reserve['vehicle_number'], $reserve['status'], $reserve['is_return']]);
        $this->assertNotNull($candidates->firstWhere('apparatus_id', $outOfService->id), 'A reserve truck is offered in any status.');
        $others = collect($response->json('other_vehicles'));
        $frontline = $others->firstWhere('apparatus_id', $this->frontline->id);
        $this->assertSame(['apparatus_id', 'vehicle_number', 'label', 'location', 'status', 'listed_as'], array_keys($frontline));
        $this->assertSame(['E3', 'In Service'], [$frontline['listed_as'], $frontline['status']]);
        $this->assertNull($candidates->merge($others)->firstWhere('apparatus_id', $otherType->id), 'Another type of truck is never offered.');
        $this->assertNull($candidates->merge($others)->firstWhere('apparatus_id', $this->unit->id));

        $first = $response->json('equipment.compartments.0');
        $this->assertSame(['id', 'name', 'items'], array_keys($first));
        $this->assertSame(['item_key', 'name', 'expected_quantity', 'previous'], array_keys($first['items'][0]));
    }

    public function test_a_reserve_truck_someone_else_planned_is_still_offered(): void
    {
        $this->swapAlert($this->frontline, ['planned_replacement_apparatus_id' => $this->reserve->id]);

        $response = $this->getJson("/api/public/apparatuses/{$this->unit->id}/changeout-context")->assertOk();

        $this->assertNotNull(collect($response->json('candidates'))->firstWhere('apparatus_id', $this->reserve->id));
    }

    public function test_the_context_ignores_a_stale_or_wrong_alert_id(): void
    {
        $mine = $this->swapAlert($this->unit);
        $other = $this->swapAlert($this->frontline);

        foreach ([$other->id, 999999] as $id) {
            $this->getJson("/api/public/apparatuses/{$this->unit->id}/changeout-context?alert_id={$id}")->assertOk()->assertJsonPath('alert.id', $mine->id);
        }
    }

    public function test_real_templates_v1_and_v2_both_produce_lines_with_their_own_ids_and_nothing_is_controlled(): void
    {
        $rescue = $this->rescuePair();

        $engine = $this->changeout($this->postChangeout($this->unit, ['to_apparatus_id' => $this->reserve->id])->assertCreated());
        $rig = $this->changeout($this->postChangeout($rescue['unit'], ['to_apparatus_id' => $rescue['reserve']->id])->assertCreated());

        $this->assertGreaterThan(50, $engine->items()->count());
        $this->assertSame(0, $engine->items()->where('custody_status', '!=', 'moved')->count());
        $medBox = $rig->items()->where('item_name', 'Med Box')->sole();
        $this->assertSame(['patient_compartment-item-3', 'transfer', 'moved'], [$medBox->item_key, $medBox->handling, $medBox->custody_status]);
        $this->assertSame('complete', $engine->handoff_status);
    }
}
