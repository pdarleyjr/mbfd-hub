<?php

declare(strict_types=1);

namespace Tests\Feature\Fleet\VehicleIdentification;

use App\Models\Apparatus;
use App\Models\ApparatusIdentificationEvent;
use App\Models\ApparatusIdentificationQuestion;
use App\Models\UnitMasterVehicleChange;
use App\Models\User;
use App\Notifications\NewSubmissionNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/** The Captain 5 path: questions ride on the workspace, answers post to the dedicated endpoint. */
final class StandaloneEndpointTest extends IdentificationTestCase
{
    private function captainUser(string $number = 'ID-CAP5'): User
    {
        return $this->actingAsCanonicalFixture($number, 'Captain Five', 'Captain');
    }

    private function url(Apparatus $unit): string
    {
        return "/api/public/apparatuses/{$unit->id}/vehicle-identification";
    }

    /** @param list<array<string, mixed>> $answers */
    private function body(Apparatus $unit, array $answers, ?string $csid = null): array
    {
        return [
            'client_submission_id' => $csid ?? (string) Str::uuid(),
            'questions_version' => $this->block($unit)['questions_version'],
            'answers' => $answers,
        ];
    }

    public function test_the_workspace_carries_the_block_for_an_eligible_viewer(): void
    {
        $unit = $this->captainFive('XK4560');
        $this->captainUser();

        $response = $this->getJson('/api/captain5/workspace')->assertOk();

        $response->assertJsonPath('apparatus_id', $unit->id)
            ->assertJsonPath('vehicle_identification.required', true)
            ->assertJsonPath('vehicle_identification.kind', 'vehicle')
            ->assertJsonPath('vehicle_identification.questions.0.field', 'plate')
            ->assertJsonPath('vehicle_identification.questions.0.confirm', true)
            ->assertJsonPath('vehicle_identification.questions.0.current_value', 'XD1904');
        $this->assertSame($this->block($unit)['questions_version'], $response->json('vehicle_identification.questions_version'));
        $this->assertStringNotContainsString('XK4560', $response->getContent());
    }

    public function test_the_workspace_block_is_empty_when_nothing_is_open_and_absent_without_the_unit(): void
    {
        $this->captainUser();
        $this->getJson('/api/captain5/workspace')->assertOk()->assertJsonMissingPath('vehicle_identification')->assertJsonMissingPath('apparatus_id');

        $unit = $this->captainFive();
        $this->getJson('/api/captain5/workspace')->assertOk()
            ->assertJsonPath('apparatus_id', $unit->id)
            ->assertJsonPath('vehicle_identification.required', false)
            ->assertJsonPath('vehicle_identification.questions', []);
    }

    public function test_an_ineligible_member_is_refused_everywhere(): void
    {
        $unit = $this->captainFive('XK4560');
        $this->actingAsCanonicalUser($this->member); // a firefighter

        $this->getJson('/api/captain5/workspace')->assertForbidden();
        $this->getJson($this->url($unit))->assertForbidden();
        $this->postJson($this->url($unit), $this->body($unit, [$this->answer('plate', 'XD1904')]))->assertForbidden();
        $this->assertSame(0, ApparatusIdentificationEvent::query()->count());
    }

    public function test_a_signed_out_request_is_refused(): void
    {
        $unit = $this->captainFive('XK4560');
        $this->logoutCanonicalSession();

        $this->postJson($this->url($unit), $this->body($unit, [$this->answer('plate', 'XD1904')]))->assertUnauthorized();
        $this->getJson($this->url($unit))->assertUnauthorized();
    }

    public function test_a_captain_confirms_the_plate_once_and_a_retry_changes_nothing(): void
    {
        $unit = $this->captainFive('XK4560');
        $crew = $this->captainUser();
        $body = $this->body($unit, [$this->answer('plate', 'xd 1904')]);

        $first = $this->postJson($this->url($unit), $body)->assertOk();
        $first->assertJsonPath('replayed', false)->assertJsonPath('vehicle_identification.required', false);
        $events = ApparatusIdentificationEvent::query()->count();
        $retry = $this->postJson($this->url($unit), $body)->assertOk();

        $retry->assertJsonPath('replayed', true);
        $this->assertSame($events, ApparatusIdentificationEvent::query()->count());
        $this->assertSame(['answered', 'XD1904', $crew->id], [
            ApparatusIdentificationQuestion::query()->sole()->state,
            ApparatusIdentificationQuestion::query()->sole()->answered_value,
            ApparatusIdentificationQuestion::query()->sole()->resolved_by_user_id,
        ]);
        $this->assertSame(1, ApparatusIdentificationEvent::query()->where('event', 'confirmed')->count());
        $this->assertSame('XD1904', $unit->fresh()->masterVehicle->tag_number);
        $this->assertSame(0, UnitMasterVehicleChange::query()->where('new_value', 'XD1904')->where('status', 'proposed')->count());
    }

    public function test_a_captain_who_reads_a_different_plate_sends_it_to_fleet_as_a_proposal(): void
    {
        $manager = $this->fleetOperationsManager('fleet.manager@example.test');
        $unit = $this->captainFive('XK4560');
        $this->captainUser();

        $this->postJson($this->url($unit), $this->body($unit, [$this->answer('plate', 'XK4560')]))->assertOk();

        $this->assertSame('XD1904', $unit->fresh()->masterVehicle->tag_number, 'the record is never overwritten');
        $this->assertSame(1, ApparatusIdentificationEvent::query()->where('event', 'conflict')->count());
        Notification::assertSentTo($manager, NewSubmissionNotification::class);
    }

    public function test_bad_items_come_back_as_field_errors_and_record_nothing(): void
    {
        $unit = $this->captainFive(null, ['year', 'serial_number']);
        $this->captainUser();

        $this->postJson($this->url($unit), $this->body($unit, [
            $this->answer('year', '1899'),
            $this->answer('vin', 'TOO SHORT'),
            $this->unavailable('make', 'other'),
        ]))->assertUnprocessable()->assertJsonValidationErrors(['answers.0', 'answers.1']);

        $this->assertSame(0, ApparatusIdentificationEvent::query()->count());
    }

    public function test_the_request_shape_is_validated(): void
    {
        $unit = $this->captainFive('XK4560');
        $this->captainUser();

        $this->postJson($this->url($unit), [])->assertUnprocessable()->assertJsonValidationErrors(['client_submission_id', 'questions_version', 'answers']);
        $this->postJson($this->url($unit), ['client_submission_id' => 'nope', 'questions_version' => 'x', 'answers' => []])
            ->assertUnprocessable()->assertJsonValidationErrors(['client_submission_id', 'answers']);
    }

    public function test_a_submission_id_cannot_be_reused_for_another_unit(): void
    {
        $unit = $this->captainFive('XK4560');
        $this->captainUser();
        $csid = (string) Str::uuid();
        $this->postJson($this->url($unit), $this->body($unit, [$this->answer('plate', 'XD1904')], $csid))->assertOk();

        $this->postJson($this->url($this->unit), $this->body($this->unit, [$this->answer('plate', 'XE1234')], $csid))->assertStatus(409);
    }

    public function test_a_second_crew_answering_a_closed_question_is_superseded_and_a_difference_goes_to_fleet(): void
    {
        $manager = $this->fleetOperationsManager('fleet.manager@example.test');
        $unit = $this->captainFive('XK4560');
        $this->captainUser('ID-CAP5-A');
        $this->postJson($this->url($unit), $this->body($unit, [$this->answer('plate', 'XD1904')]))->assertOk();

        $this->captainUser('ID-CAP5-B');
        $this->postJson($this->url($unit), $this->body($unit, [$this->answer('plate', 'XD1904')]))->assertOk();
        $this->assertSame(1, ApparatusIdentificationEvent::query()->where('event', 'superseded')->count());
        $this->assertSame(0, ApparatusIdentificationEvent::query()->where('event', 'conflict')->count());
        Notification::assertNothingSent();

        $this->captainUser('ID-CAP5-C');
        $this->postJson($this->url($unit), $this->body($unit, [$this->answer('plate', 'ZZ0001')]))->assertOk();

        $this->assertSame(2, ApparatusIdentificationEvent::query()->where('event', 'superseded')->count());
        $this->assertSame(1, ApparatusIdentificationEvent::query()->where('event', 'conflict')->count());
        $this->assertSame('XD1904', $unit->fresh()->masterVehicle->tag_number);
        $this->assertSame('XD1904', ApparatusIdentificationQuestion::query()->sole()->answered_value, 'the first answer is kept');
        Notification::assertSentTo($manager, NewSubmissionNotification::class);
    }

    public function test_fleet_managers_may_answer_for_any_asked_unit(): void
    {
        $captain = $this->captainFive('XK4560');
        $this->actingAsCanonicalUser($this->fleetOperationsManager());

        $this->postJson($this->url($captain), $this->body($captain, [$this->answer('plate', 'XD1904')]))->assertOk();
        $this->postJson($this->url($this->unit), $this->body($this->unit, [$this->answer('plate', 'XE1234')]))->assertOk();
        $this->getJson($this->url($this->unit))->assertOk()->assertJsonPath('vehicle_identification.kind', 'vehicle');
    }

    public function test_a_member_may_answer_for_a_checkout_unit_but_not_the_captain_5_unit(): void
    {
        $captain = $this->captainFive('XK4560');
        $this->actingAsCanonicalUser($this->member);

        $this->postJson($this->url($this->unit), $this->body($this->unit, [$this->answer('plate', 'XE1234')]))->assertOk();
        $this->postJson($this->url($captain), $this->body($captain, [$this->answer('plate', 'XD1904')]))->assertForbidden();
    }

    public function test_units_that_are_not_asked_do_not_exist_to_this_route(): void
    {
        $administrative = $this->legacyApparatus('A 9', null, ['daily_checkout_requirement' => 'administrative']);
        $test = $this->legacyApparatus('ZZ-VERIFY', null, ['unit_id' => 'ZZ-VERIFY']);
        $this->actingAsCanonicalUser($this->fleetOperationsManager());

        $this->getJson($this->url($administrative))->assertNotFound();
        $this->getJson($this->url($test))->assertNotFound();
        $this->postJson($this->url($administrative), $this->body($administrative, [$this->answer('plate', 'XE1234')]))->assertNotFound();
        $this->getJson('/api/public/apparatuses/999999/vehicle-identification')->assertNotFound();
    }
}
