<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\StationRequestStatus;
use App\Models\Apparatus;
use App\Models\ApparatusDefect;
use App\Models\ApparatusInspection;
use App\Models\ApparatusServiceTicket;
use App\Models\Station;
use App\Models\StationRequest;
use App\Models\StationRequestUpdate;
use App\Support\HubTimezone;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\DailyRosterSnapshotFactory;
use Tests\TestCase;

/** The read-only 300 station board: gate, payload shape, drill-ins and a bounded query count. */
final class Command300StationsApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['daily_roster.mode' => 'publish']);
        $moment = CarbonImmutable::parse('2026-10-20 12:00:00', HubTimezone::NAME);
        Carbon::setTestNow($moment);
        CarbonImmutable::setTestNow($moment);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_guests_and_members_without_300_access_are_refused_everywhere(): void
    {
        $station = $this->station(1);
        $unit = $this->unit($station, 'E1');
        $inspection = $this->inspection($unit, '2026-10-20 08:00:00');

        $this->getJson('/api/public/command/stations')->assertUnauthorized();

        $this->actingAsCanonicalFixture(rank: 'Lieutenant');
        $this->getJson('/api/public/command/stations')->assertForbidden();
        $this->getJson("/api/public/command/stations/{$station->id}")->assertForbidden();
        $this->getJson("/api/public/command/stations/{$station->id}/checkouts/{$inspection->id}")->assertForbidden();
    }

    public function test_the_board_is_read_only(): void
    {
        $this->actingAsCanonicalFixture(rank: 'Captain');
        $station = $this->station(1);

        foreach (['post', 'put', 'patch', 'delete'] as $verb) {
            $this->{$verb.'Json'}('/api/public/command/stations')->assertStatus(405);
            $this->{$verb.'Json'}("/api/public/command/stations/{$station->id}")->assertStatus(405);
        }
    }

    public function test_the_board_summarizes_checkouts_requests_issues_and_crew_per_station(): void
    {
        $one = $this->station(1);
        $this->station(9, false);
        $engine = $this->unit($one, 'E1');
        $ladder = $this->unit($one, 'L1');
        $this->unit($one, 'R1', 'Out of Service');
        $this->inspection($engine, '2026-10-20 08:30:00');
        $this->inspection($ladder, '2026-10-19 08:30:00'); // previous window: not counted
        ApparatusDefect::query()->create(['apparatus_id' => $ladder->id, 'compartment' => 'A', 'item' => 'Cracked mirror', 'status' => 'open', 'issue_type' => 'damaged', 'reported_date' => now(), 'severity' => 'significant']);
        ApparatusServiceTicket::query()->create($this->ticket($ladder, 'submitted', 'T-1'));
        $pending = $this->request($one, 'Broken recliner', '2026-10-20 09:00:00', StationRequestStatus::Pending);
        $old = $this->request($one, 'Leaky faucet', '2026-10-18 09:00:00', StationRequestStatus::InProgress);
        $this->request($one, 'Done thing', '2026-10-18 09:00:00', StationRequestStatus::Completed);
        StationRequestUpdate::query()->create(['station_request_id' => $old->id, 'status' => 'in_progress', 'public_note' => 'Plumber booked']);
        DailyRosterSnapshotFactory::publish('2026-10-20', [
            'Station 1 / Engine 1' => [['employee_id' => '50003', 'name' => 'ENGINE, ALPHA', 'rank' => 'Lt', 'seat' => 0, 'code' => 'SP', 'category' => 'Scheduled']],
            'Station 2 / Engine 2' => [['employee_id' => '50006', 'name' => 'OTHER, BETA']],
        ]);
        $this->actingAsCanonicalFixture(rank: 'Captain');

        $response = $this->getJson('/api/public/command/stations')->assertOk();

        $response->assertJsonCount(1, 'stations')->assertJsonPath('summary.stations', 1);
        $summary = $response->json('summary');
        $this->assertSame(1, $summary['checkouts_done']);
        $this->assertSame(2, $summary['checkouts_expected']);
        $this->assertSame(2, $summary['requests_open']);
        $this->assertSame(1, $summary['requests_pending']);
        $this->assertSame(1, $summary['requests_new_today']);
        $this->assertSame(1, $summary['vehicles_with_issues']);

        $card = $response->json('stations.0');
        $this->assertSame('submitted', collect($card['checkouts']['units'])->firstWhere('designation', 'E1')['state']);
        $this->assertSame('not_submitted', collect($card['checkouts']['units'])->firstWhere('designation', 'L1')['state']);
        $this->assertSame('Captain Sam Lee', collect($card['checkouts']['units'])->firstWhere('designation', 'E1')['submitted_by']);
        $this->assertSame(1, $card['checkouts']['out_of_service']);
        $this->assertSame('attention', $card['attention']['level']);
        $this->assertSame(1, $card['requests']['updated_recently']);
        $this->assertSame(['Broken recliner', 'Leaky faucet'], array_column($card['requests']['items'], 'title'));
        $this->assertSame($pending->id, $card['requests']['items'][0]['id']);
        $this->assertTrue($card['crew']['available']);
        $this->assertSame(['ENGINE, ALPHA'], array_column($card['crew']['members'], 'name'));
        $this->assertSame('working', $card['crew']['members'][0]['presence']);
        $this->assertSame('Cracked mirror', $card['vehicle_issues']['units'][0]['top'][0]['label']);

        foreach (['SECRET', 'employee_id', '50003', 'requester', 'photo', '"vin"'] as $restricted) {
            $this->assertStringNotContainsString($restricted, $response->getContent());
        }
    }

    public function test_the_board_says_so_when_the_roster_is_not_available(): void
    {
        $this->station(1);
        $this->actingAsCanonicalFixture(rank: 'Captain');

        $response = $this->getJson('/api/public/command/stations')->assertOk();

        $response->assertJsonPath('roster.available', false)->assertJsonPath('stations.0.crew.available', false);
        $this->assertNotEmpty($response->json('stations.0.crew.message'));
        $this->assertContains('Roster not available yet', $response->json('stations.0.attention.reasons'));
    }

    public function test_a_station_drill_in_has_requests_with_updates_issues_by_unit_and_checkouts(): void
    {
        $station = $this->station(1);
        $engine = $this->unit($station, 'E1');
        $this->inspection($engine, '2026-10-20 08:30:00');
        ApparatusDefect::query()->create(['apparatus_id' => $engine->id, 'compartment' => 'Cab', 'item' => 'Seat belt', 'status' => 'open', 'issue_type' => 'damaged', 'reported_date' => now(), 'notes' => 'Frayed']);
        $request = $this->request($station, 'Broken recliner', '2026-10-20 09:00:00', StationRequestStatus::Acknowledged);
        StationRequestUpdate::query()->create(['station_request_id' => $request->id, 'status' => 'acknowledged', 'public_note' => 'Looking into it']);
        $this->actingAsCanonicalFixture(rank: 'Captain');

        $response = $this->getJson("/api/public/command/stations/{$station->id}")->assertOk();

        $response->assertJsonPath('station.id', $station->id)->assertJsonPath('requests_total', 1);
        $this->assertSame('Looking into it', $response->json('requests.0.updates.0.public_note'));
        $this->assertArrayNotHasKey('member_resolution', $response->json('requests.0'));
        $this->assertSame('Seat belt', $response->json('vehicle_issues.0.defects.0.item'));
        $this->assertSame('Frayed', $response->json('vehicle_issues.0.defects.0.notes'));
        $this->assertSame('submitted', $response->json('units.0.state'));
        $this->assertNotNull($response->json('units.0.checkout.submitted_by'));
        $this->assertCount(1, $response->json('checkouts'));
    }

    public function test_a_checkout_drill_in_includes_that_vehicles_issues_and_stays_inside_the_station_and_window(): void
    {
        $station = $this->station(1);
        $other = $this->station(2);
        $engine = $this->unit($station, 'E1');
        $inspection = $this->inspection($engine, '2026-10-20 08:30:00', ['results' => [['name' => 'Cab', 'items' => [['name' => 'Belt', 'status' => 'damaged', 'notes' => 'Frayed', 'photo_path' => 'private/p.jpg']]]]]);
        $old = $this->inspection($engine, '2026-10-10 08:30:00');
        ApparatusDefect::query()->create(['apparatus_id' => $engine->id, 'apparatus_inspection_id' => $inspection->id, 'compartment' => 'Cab', 'item' => 'Belt', 'status' => 'open', 'issue_type' => 'damaged', 'reported_date' => now()]);
        $this->actingAsCanonicalFixture(rank: 'Captain');

        $response = $this->getJson("/api/public/command/stations/{$station->id}/checkouts/{$inspection->id}")->assertOk();

        $response->assertJsonPath('checkout.compartments.0.items.0.name', 'Belt')
            ->assertJsonPath('checkout.issues.defect_count', 1)
            ->assertJsonPath('checkout.issues.defects.0.from_this_checkout', true);
        $this->assertStringNotContainsString('private/p.jpg', $response->getContent());
        $this->getJson("/api/public/command/stations/{$other->id}/checkouts/{$inspection->id}")->assertNotFound();
        $this->getJson("/api/public/command/stations/{$station->id}/checkouts/{$old->id}")->assertNotFound();
    }

    public function test_query_count_does_not_grow_with_the_number_of_stations(): void
    {
        $this->actingAsCanonicalFixture(rank: 'Captain');
        $this->seedStation(1);
        $this->countQueries(); // Warm one-time caches (permissions).
        $small = $this->countQueries();

        foreach ([2, 3, 4, 5, 6] as $number) {
            $this->seedStation($number);
        }

        $this->assertSame($small, $this->countQueries());
    }

    private function seedStation(int $number): void
    {
        $station = $this->station($number);
        foreach (['E', 'L', 'R'] as $letter) {
            $unit = $this->unit($station, $letter.$number);
            $this->inspection($unit, '2026-10-20 08:30:00');
            ApparatusDefect::query()->create(['apparatus_id' => $unit->id, 'compartment' => 'A', 'item' => 'Light', 'status' => 'open', 'issue_type' => 'damaged', 'reported_date' => now()]);
            ApparatusServiceTicket::query()->create($this->ticket($unit, 'submitted', "T-{$unit->id}"));
        }
        $request = $this->request($station, "Request {$number}", '2026-10-20 09:00:00', StationRequestStatus::Pending);
        StationRequestUpdate::query()->create(['station_request_id' => $request->id, 'status' => 'pending', 'public_note' => 'Seen']);
    }

    private function countQueries(): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson('/api/public/command/stations')->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    private function station(int $number, bool $active = true): Station
    {
        return Station::query()->create(['station_number' => $number, 'name' => "Station {$number}", 'address' => "{$number} Test Street", 'is_active' => $active, 'notes' => 'SECRET-NOTE']);
    }

    private function unit(Station $station, string $designation, string $status = 'In Service'): Apparatus
    {
        return Apparatus::query()->create([
            'station_id' => $station->id, 'unit_id' => $designation.'-'.uniqid(), 'name' => $designation, 'type' => 'Engine',
            'vehicle_number' => $designation, 'designation' => $designation, 'slug' => strtolower($designation).'-'.uniqid(),
            'vin' => 'SECRETVIN', 'status' => $status, 'daily_checkout_requirement' => 'required',
        ]);
    }

    /** @param  array<string, mixed>  $overrides */
    private function inspection(Apparatus $unit, string $completedEastern, array $overrides = []): ApparatusInspection
    {
        return ApparatusInspection::create($overrides + [
            'apparatus_id' => $unit->id, 'client_submission_id' => (string) Str::uuid(), 'operator_name' => 'Sam Lee', 'rank' => 'Captain',
            'shift' => 'A', 'review_status' => 'approved', 'processing_status' => 'accepted', 'results' => [],
            'completed_at' => CarbonImmutable::parse($completedEastern, HubTimezone::NAME)->utc(),
        ]);
    }

    private function request(Station $station, string $title, string $createdEastern, StationRequestStatus $status): StationRequest
    {
        $request = StationRequest::create([
            'station_id' => $station->id, 'requester_name_snapshot' => 'Sam Lee', 'request_type' => 'equipment', 'subject_type' => 'PPE',
            'title' => $title, 'description' => 'Details', 'priority' => 'normal', 'status' => $status->value,
        ]);
        $request->forceFill(['created_at' => CarbonImmutable::parse($createdEastern, HubTimezone::NAME)->utc()])->saveQuietly();

        return $request;
    }

    /** @return array<string, mixed> */
    private function ticket(Apparatus $apparatus, string $status, string $number): array
    {
        return [
            'ticket_number' => $number, 'apparatus_id' => $apparatus->id, 'station_id' => $apparatus->station_id,
            'unit_designation_snapshot' => $apparatus->designation, 'title' => 'Test ticket', 'description' => 'Test', 'status' => $status,
            'priority' => 'normal', 'origin' => 'test', 'category' => 'mechanical',
        ];
    }
}
