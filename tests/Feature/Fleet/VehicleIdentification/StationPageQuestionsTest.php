<?php

declare(strict_types=1);

namespace Tests\Feature\Fleet\VehicleIdentification;

use App\Enums\AccountStatus;
use App\Models\Apparatus;
use App\Models\ApparatusIdentificationEvent;
use App\Models\UnitMasterVehicleChange;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Units with no Daily Checkout are asked on their station page: the station detail payload carries
 * `vehicle_identification_units`, and answers post to the existing vehicle-identification endpoint.
 */
final class StationPageQuestionsTest extends IdentificationTestCase
{
    /** A 2 as production has it: an administrative unit at Station 2 whose vehicle record lacks only the plate. */
    private function aTwo(): Apparatus
    {
        $unit = $this->liveApparatus('A 2', 'V-A2', [
            'station_id' => $this->unit->station_id,
            'type' => 'SUV',
            'daily_checkout_requirement' => 'administrative',
            'daily_checkout_template' => 'pending',
        ]);
        $this->completeMasterExcept($unit, ['tag_number']);

        return $unit->fresh();
    }

    private function stationUrl(int $stationId): string
    {
        return "/api/public/stations/{$stationId}";
    }

    private function postUrl(Apparatus $unit): string
    {
        return "/api/public/apparatuses/{$unit->id}/vehicle-identification";
    }

    /** @return array<string, mixed> */
    private function body(Apparatus $unit, array $answers): array
    {
        return [
            'client_submission_id' => (string) Str::uuid(),
            'questions_version' => $this->block($unit)['questions_version'],
            'answers' => $answers,
        ];
    }

    public function test_the_station_payload_carries_the_card_for_a_unit_with_no_daily_checkout(): void
    {
        $aTwo = $this->aTwo();

        $response = $this->getJson($this->stationUrl($this->unit->station_id))->assertOk();

        $units = $response->json('vehicle_identification_units');
        $this->assertCount(1, $units, 'E2 does a Daily Checkout, so it is asked there, not here');
        $this->assertSame(['apparatus_id', 'designation', 'vehicle_identification'], array_keys($units[0]));
        $this->assertSame([$aTwo->id, 'A 2'], [$units[0]['apparatus_id'], $units[0]['designation']]);
        $block = $units[0]['vehicle_identification'];
        $this->assertSame(array_keys($this->block($aTwo)), array_keys($block), 'the same block the checklist GET returns');
        $this->assertSame(['plate'], array_column($block['questions'], 'field'));
        $this->assertTrue($block['required']);
        $this->assertTrue($block['can_answer']);
        $this->assertSame($this->block($aTwo)['questions_version'], $block['questions_version']);
        $this->assertSame('License plate', $block['questions'][0]['label']);
    }

    public function test_the_list_is_empty_when_no_unit_at_the_station_needs_answers(): void
    {
        $response = $this->getJson($this->stationUrl($this->unit->station_id))->assertOk();
        $this->assertSame([], $response->json('vehicle_identification_units'));
        $this->assertTrue($response->json('vehicle_identification_units') === []);

        $aTwo = $this->aTwo();
        $this->completeMasterExcept($aTwo);
        $this->assertSame([], $this->getJson($this->stationUrl($this->unit->station_id))->json('vehicle_identification_units'));
    }

    public function test_only_units_asked_on_a_station_page_are_listed(): void
    {
        $station = $this->unit->station_id;
        $reserve = $this->legacyApparatus('P 2', null, ['station_id' => $station, 'daily_checkout_requirement' => 'reserve']);
        $this->legacyApparatus('P 3', null, ['station_id' => $station, 'daily_checkout_requirement' => 'inactive']);
        $this->legacyApparatus('ZZ-VERIFY', null, ['station_id' => $station, 'unit_id' => 'ZZ-VERIFY', 'daily_checkout_requirement' => 'administrative']);
        $this->legacyApparatus('P 5', null, ['daily_checkout_requirement' => 'administrative']); // no station
        $this->captainFive()->forceFill(['station_id' => $station])->save();

        $designations = array_column($this->getJson($this->stationUrl($station))->json('vehicle_identification_units'), 'designation');

        $this->assertSame(['P 2'], $designations);
        $this->assertContains($reserve->designation, $designations);
    }

    public function test_a_viewer_who_may_not_answer_is_told_so_in_the_card(): void
    {
        $aTwo = $this->aTwo();
        $this->assertTrue($this->block($aTwo)['can_answer']);

        $this->actingAsCanonicalUser($this->fleetOperationsManager());
        $units = $this->getJson($this->stationUrl($this->unit->station_id))->assertOk()->json('vehicle_identification_units');

        $this->assertTrue($units[0]['vehicle_identification']['can_answer'], 'Fleet may always answer');
    }

    public function test_a_member_answers_through_the_existing_endpoint_and_the_card_goes_away(): void
    {
        $aTwo = $this->aTwo();

        $this->postJson($this->postUrl($aTwo), $this->body($aTwo, [$this->answer('plate', 'ab 1234')]))
            ->assertOk()
            ->assertJsonPath('replayed', false)
            ->assertJsonPath('vehicle_identification.required', false);

        $master = $aTwo->fresh()->masterVehicle;
        $this->assertSame('AB1234', $master->tag_number);
        $change = UnitMasterVehicleChange::query()->where('unit_master_vehicle_id', $master->id)->where('field', 'tag_number')->where('source', 'daily_checkout')->sole();
        $this->assertSame($this->member->id, $change->user_id);
        $this->assertStringStartsWith('Reported on the station page by Swap Reporter', (string) $change->note);
        $this->assertSame(1, ApparatusIdentificationEvent::query()->where('event', 'master_filled')->count());
        $this->assertSame([], $this->getJson($this->stationUrl($this->unit->station_id))->json('vehicle_identification_units'));
    }

    public function test_a_signed_out_request_is_refused(): void
    {
        $aTwo = $this->aTwo();
        $this->logoutCanonicalSession();

        $this->postJson($this->postUrl($aTwo), $this->body($aTwo, [$this->answer('plate', 'AB1234')]))->assertUnauthorized();
        $this->getJson($this->postUrl($aTwo))->assertUnauthorized();
        $this->assertSame(0, ApparatusIdentificationEvent::query()->count());
    }

    public function test_an_account_without_an_employee_profile_is_refused(): void
    {
        $aTwo = $this->aTwo();
        $account = User::factory()->create(['account_status' => AccountStatus::Active]);
        $this->actingAsCanonicalUser($account);

        $this->postJson($this->postUrl($aTwo), $this->body($aTwo, [$this->answer('plate', 'AB1234')]))->assertForbidden();
        $this->assertNull($aTwo->fresh()->masterVehicle->tag_number);
    }

    public function test_a_disabled_member_is_refused(): void
    {
        $aTwo = $this->aTwo();
        $body = $this->body($aTwo, [$this->answer('plate', 'AB1234')]);
        $this->member->forceFill(['account_status' => AccountStatus::Disabled])->save();

        $this->assertContains($this->postJson($this->postUrl($aTwo), $body)->getStatusCode(), [401, 403]);
        $this->assertNull($aTwo->fresh()->masterVehicle->tag_number);
    }

    public function test_an_inactive_unit_cannot_be_answered_for(): void
    {
        $inactive = $this->legacyApparatus('E 77', null, ['station_id' => $this->unit->station_id, 'daily_checkout_requirement' => 'inactive']);

        $this->postJson($this->postUrl($inactive), $this->body($inactive, [$this->answer('plate', 'AB1234')]))->assertNotFound();
        $this->getJson($this->postUrl($inactive))->assertNotFound();
    }
}
