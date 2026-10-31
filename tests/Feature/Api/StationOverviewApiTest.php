<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\StationRequestStatus;
use App\Models\Apparatus;
use App\Models\ApparatusInspection;
use App\Models\Station;
use App\Models\StationRequest;
use App\Services\StationOverviewService;
use App\Support\HubTimezone;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class StationOverviewApiTest extends TestCase
{
    use RefreshDatabase;

    private Station $station;

    protected function setUp(): void
    {
        parent::setUp();
        $this->station = $this->station(1);
        $this->actingAsCanonicalFixture();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_a_unit_counts_only_for_a_checkout_turned_in_between_7_am_and_7_am(): void
    {
        $this->at('2026-10-30 09:00:00');
        $early = $this->unit('E 1');
        $previous = $this->unit('R 1');
        $none = $this->unit('L 1');
        $this->inspection($early, '2026-10-30 07:00:00');
        $this->inspection($previous, '2026-10-30 06:59:59');

        $units = collect($this->overview()->assertOk()->json('units'))->keyBy('designation');

        $this->assertSame('submitted', $units['E 1']['state']);
        $this->assertSame('not_submitted', $units['R 1']['state']);
        $this->assertSame('not_submitted', $units['L 1']['state']);
        $this->assertSame((int) $none->id, $units['L 1']['apparatus_id']);
        $this->assertSame(['E 1', 'L 1', 'R 1'], collect($this->overview()->json('units'))->pluck('designation')->all());
    }

    public function test_a_late_night_checkout_still_counts_after_midnight(): void
    {
        $this->at('2026-10-30 22:00:00');
        $unit = $this->unit('E 1');
        $this->inspection($unit, '2026-10-30 21:30:00');

        $this->at('2026-10-31 03:00:00');
        $this->overview()->assertJsonPath('units.0.state', 'submitted');

        $this->at('2026-10-31 07:00:00');
        $this->overview()->assertJsonPath('units.0.state', 'not_submitted');
    }

    public function test_the_alert_names_frontline_units_without_a_checkout_from_noon_on(): void
    {
        $this->at('2026-10-30 11:59:59');
        $this->unit('E 1');
        $this->inspection($this->unit('R 1'), '2026-10-30 08:00:00');
        $this->overview()->assertJsonPath('alert.active', false)->assertJsonPath('alert.units', []);

        $this->at('2026-10-30 12:00:00');
        $alert = $this->overview()->assertJsonPath('alert.active', true)->json('alert.units');
        $this->assertSame(['E 1'], array_column($alert, 'designation'));

        $this->at('2026-10-31 06:59:00');
        $this->overview()->assertJsonPath('alert.active', true);

        $this->at('2026-10-31 07:00:00');
        $this->overview()->assertJsonPath('alert.active', false);
    }

    public function test_the_alert_leaves_out_reserve_and_out_of_service_units(): void
    {
        $this->at('2026-10-30 13:00:00');
        $this->unit('E 31', ['daily_checkout_requirement' => 'reserve', 'status' => 'Available']);
        $this->unit('Captain 5', ['daily_checkout_requirement' => 'administrative']);
        $this->unit('A 1', ['daily_checkout_requirement' => 'unknown']);
        $this->unit('R 3', ['status' => 'Out of Service']);

        $response = $this->overview()->assertJsonPath('alert.active', false);

        $units = $response->json('units');
        $this->assertSame(['R 3'], array_column($units, 'designation'), 'Only units that owe a daily inspection are listed.');
        $this->assertSame('out_of_service', $units[0]['state']);
    }

    public function test_a_reserve_unit_in_use_appears_once_it_turns_in_a_checkout(): void
    {
        $this->at('2026-10-30 09:00:00');
        $reserve = $this->unit('E 31', ['daily_checkout_requirement' => 'reserve']);
        $this->inspection($reserve, '2026-10-30 08:30:00');

        $this->overview()
            ->assertJsonPath('units.0.designation', 'E 31')
            ->assertJsonPath('units.0.frontline', false)
            ->assertJsonPath('units.0.state', 'submitted')
            ->assertJsonPath('checkouts.0.designation', 'E 31');
    }

    public function test_a_checkout_waiting_for_review_counts_but_rejected_and_test_checkouts_do_not(): void
    {
        $this->at('2026-10-30 13:00:00');
        $this->inspection($this->unit('E 1'), '2026-10-30 08:00:00', ['review_status' => 'pending_review', 'processing_status' => 'held_for_review']);
        $this->inspection($this->unit('L 1'), '2026-10-30 08:00:00', ['review_status' => 'rejected', 'processing_status' => 'rejected']);
        $excluded = $this->inspection($this->unit('R 1'), '2026-10-30 08:00:00');
        $excluded->forceFill(['test_excluded_at' => now()])->saveQuietly();

        $response = $this->overview()->assertOk();

        $this->assertSame(['L 1', 'R 1'], array_column($response->json('alert.units'), 'designation'));
        $this->assertCount(1, $response->json('checkouts'));
        $response->assertJsonPath('checkouts.0.status', 'Needs approval')->assertJsonPath('checkouts.0.needs_attention', true);
    }

    public function test_a_checkout_row_names_the_unit_and_who_submitted_it_without_personnel_numbers_or_the_signature(): void
    {
        $this->at('2026-10-30 09:00:00');
        $unit = $this->unit('E 1', ['vehicle_number' => '20503']);
        $inspection = $this->inspection($unit, '2026-10-30 08:15:00', ['rank' => 'Lieutenant', 'operator_name' => 'Alex Rivera', 'shift' => 'B']);

        $row = $this->overview()->assertOk()->json('checkouts.0');

        $this->assertSame((int) $inspection->id, $row['id']);
        $this->assertSame('E 1', $row['designation']);
        $this->assertSame('20503', $row['vehicle_number']);
        $this->assertSame('Lieutenant Alex Rivera', $row['submitted_by']);
        $this->assertSame('B', $row['shift']);
        foreach (['officer_signature', 'employee_id', 'results', 'client_submission_id'] as $private) {
            $this->assertArrayNotHasKey($private, $row);
        }
    }

    public function test_only_this_stations_units_are_counted(): void
    {
        $this->at('2026-10-30 09:00:00');
        $other = $this->station(2);
        $this->inspection($this->unit('E 2', ['station_id' => $other->id]), '2026-10-30 08:00:00');

        $response = $this->overview()->assertOk();

        $this->assertSame([], $response->json('units'));
        $this->assertSame([], $response->json('checkouts'));
    }

    public function test_requests_show_every_open_one_and_closed_ones_from_the_last_24_hours(): void
    {
        $this->at('2026-10-30 12:00:00');
        $this->request('Old and open', '2026-09-01 10:00:00', StationRequestStatus::Pending);
        $this->request('Closed this morning', '2026-10-30 09:00:00', StationRequestStatus::Completed);
        $this->request('Closed just inside 24 hours', '2026-10-29 12:00:00', StationRequestStatus::Completed);
        $this->request('Closed long ago', '2026-10-29 11:59:59', StationRequestStatus::Completed);
        $other = $this->request('Other station', '2026-10-30 09:00:00', StationRequestStatus::Pending);
        $other->forceFill(['station_id' => $this->station(2)->id])->saveQuietly();

        $response = $this->overview()->assertOk();

        $this->assertSame(
            ['Old and open', 'Closed this morning', 'Closed just inside 24 hours'],
            array_column($response->json('requests'), 'title'),
        );
        $response->assertJsonPath('requests_total', 3);
    }

    public function test_the_request_cap_never_drops_an_open_request_before_a_closed_one(): void
    {
        $this->at('2026-10-30 12:00:00');
        $this->request('Oldest open', '2026-01-01 10:00:00', StationRequestStatus::Pending);
        foreach (range(1, StationOverviewService::MAX_REQUESTS + 2) as $number) {
            $this->request("Closed $number", '2026-10-30 09:00:00', StationRequestStatus::Completed);
        }

        $response = $this->overview()->assertOk();

        $response->assertJsonPath('requests.0.title', 'Oldest open');
        $this->assertCount(StationOverviewService::MAX_REQUESTS, $response->json('requests'));
        $response->assertJsonPath('requests_total', StationOverviewService::MAX_REQUESTS + 3);
    }

    public function test_the_window_holds_across_the_night_the_clocks_fall_back(): void
    {
        $this->at('2026-11-01 06:45:00');
        $unit = $this->unit('E 1');
        $this->inspection($unit, '2026-11-01 06:30:00');
        $this->overview()->assertJsonPath('units.0.state', 'submitted');

        $this->at('2026-11-01 07:00:00');
        $this->overview()->assertJsonPath('units.0.state', 'not_submitted');

        $this->at('2026-11-01 12:00:00');
        $this->overview()->assertJsonPath('alert.active', true);
    }

    public function test_the_whole_checkout_opens_with_every_item_and_no_storage_paths(): void
    {
        $this->at('2026-10-30 09:00:00');
        $inspection = $this->inspection($this->unit('E 1'), '2026-10-30 08:15:00', [
            'engine_hours' => 1234.5,
            'miles' => 40210,
            'results' => [
                ['id' => 'cab', 'name' => 'Front Cab', 'items' => [
                    ['id' => 'a', 'name' => 'Lights', 'status' => 'Present', 'notes' => null, 'observed' => true],
                    ['id' => 'b', 'name' => 'Siren', 'status' => 'Damaged', 'notes' => 'Cracked horn', 'observed' => true, 'photo_path' => 'inspections/secret.jpg'],
                ]],
                ['id' => 'rear', 'name' => 'Rear', 'items' => [['id' => 'c', 'name' => 'Oxygen', 'status' => 'Present', 'value' => 1800]]],
            ],
            'officer_signature' => 'data:image/png;base64,AAAA',
        ]);

        $response = $this->checkout($this->station, (int) $inspection->id)->assertOk();

        $response->assertJsonPath('checkout.engine_hours', '1234.5')
            ->assertJsonPath('checkout.miles', 40210)
            ->assertJsonPath('checkout.compartments.0.name', 'Front Cab')
            ->assertJsonPath('checkout.compartments.0.items.1.status', 'Damaged')
            ->assertJsonPath('checkout.compartments.0.items.1.notes', 'Cracked horn')
            ->assertJsonPath('checkout.compartments.0.items.1.has_photo', true)
            ->assertJsonPath('checkout.compartments.1.items.0.value', '1800');
        $body = $response->getContent();
        $this->assertStringNotContainsString('secret.jpg', (string) $body);
        $this->assertStringNotContainsString('base64', (string) $body);
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_a_checkout_cannot_be_read_through_another_station_or_once_it_is_old(): void
    {
        $this->at('2026-10-30 09:00:00');
        $inspection = $this->inspection($this->unit('E 1'), '2026-10-30 08:15:00');
        $other = $this->station(2);

        $this->checkout($other, (int) $inspection->id)->assertNotFound();
        $this->checkout($this->station, 999_999)->assertNotFound();

        $this->at('2026-10-31 08:00:00');
        $this->checkout($this->station, (int) $inspection->id)->assertOk();

        $this->at('2026-11-01 08:00:00');
        $this->checkout($this->station, (int) $inspection->id)->assertNotFound();
    }

    public function test_signing_in_is_required(): void
    {
        Auth::logout();
        Auth::forgetGuards();

        $this->getJson("/api/public/stations/{$this->station->id}/overview")->assertUnauthorized();
        $this->getJson("/api/public/stations/{$this->station->id}/checkouts/1")->assertUnauthorized();
    }

    private function overview(): TestResponse
    {
        return $this->getJson("/api/public/stations/{$this->station->id}/overview");
    }

    private function checkout(Station $station, int $inspection): TestResponse
    {
        return $this->getJson("/api/public/stations/{$station->id}/checkouts/{$inspection}");
    }

    private function at(string $eastern): void
    {
        $now = CarbonImmutable::parse($eastern, HubTimezone::NAME);
        Carbon::setTestNow($now);
        CarbonImmutable::setTestNow($now);
        // The member session's idle clock follows the test clock, so sign in again at each moment.
        $this->actingAsCanonicalFixture('E-'.Str::upper(Str::random(8)));
    }

    private function station(int $number): Station
    {
        return Station::create([
            'station_number' => $number,
            'name' => "Station {$number}",
            'address' => '123 Main St',
            'city' => 'Miami Beach',
            'state' => 'FL',
            'zip_code' => '33139',
            'phone' => '305-555-0100',
            'is_active' => true,
        ]);
    }

    /** @param  array<string, mixed>  $overrides */
    private function unit(string $designation, array $overrides = []): Apparatus
    {
        $slug = Str::slug($designation).'-'.Str::lower(Str::random(5));
        $requirement = $overrides['daily_checkout_requirement'] ?? 'required';
        unset($overrides['daily_checkout_requirement']);
        $unit = Apparatus::create($overrides + [
            'station_id' => $this->station->id,
            'unit_id' => $slug,
            'name' => $designation,
            'type' => 'Engine',
            'vehicle_number' => 'V'.random_int(1000, 9999),
            'designation' => $designation,
            'slug' => $slug,
            'make' => 'Pierce',
            'model' => 'Enforcer',
            'year' => 2020,
            'status' => 'In Service',
        ]);
        Apparatus::query()->whereKey($unit->id)->update(['daily_checkout_requirement' => $requirement]);

        return $unit->fresh();
    }

    /** @param  array<string, mixed>  $overrides */
    private function inspection(Apparatus $unit, string $completedEastern, array $overrides = []): ApparatusInspection
    {
        return ApparatusInspection::create($overrides + [
            'apparatus_id' => $unit->id,
            'client_submission_id' => (string) Str::uuid(),
            'operator_name' => 'Sam Lee',
            'rank' => 'Captain',
            'shift' => 'A',
            'review_status' => 'approved',
            'processing_status' => 'accepted',
            'results' => [],
            'completed_at' => CarbonImmutable::parse($completedEastern, HubTimezone::NAME)->utc(),
        ]);
    }

    private function request(string $title, string $createdEastern, StationRequestStatus $status): StationRequest
    {
        $request = StationRequest::create([
            'station_id' => $this->station->id,
            'requester_name_snapshot' => 'Sam Lee',
            'request_type' => 'equipment',
            'subject_type' => 'PPE',
            'title' => $title,
            'description' => 'Details',
            'priority' => 'normal',
            'status' => $status->value,
        ]);
        $request->forceFill(['created_at' => CarbonImmutable::parse($createdEastern, HubTimezone::NAME)->utc()])->saveQuietly();

        return $request;
    }
}
