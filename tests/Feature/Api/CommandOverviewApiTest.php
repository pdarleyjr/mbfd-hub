<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Apparatus;
use App\Models\ApparatusDefect;
use App\Models\ApparatusServiceTicket;
use App\Models\Station;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Contract for the read-only 300 Command overview aggregate. */
final class CommandOverviewApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_cannot_read_the_overview(): void
    {
        $this->getJson('/api/public/command/overview')->assertUnauthorized();
    }

    public function test_overview_lists_active_stations_with_counts_and_no_restricted_fields(): void
    {
        $station = $this->station(1, true);
        $this->station(9, false);
        $engine = $this->apparatus($station, 'E1', 'In Service');
        $this->apparatus($station, 'R1', 'Out of Service');
        ApparatusDefect::query()->create(['apparatus_id' => $engine->id, 'compartment' => 'A', 'item' => 'Light', 'status' => 'open', 'issue_type' => 'damaged', 'reported_date' => now()]);
        ApparatusServiceTicket::query()->create($this->ticket($engine, 'submitted', 'T-1'));
        ApparatusServiceTicket::query()->create($this->ticket($engine, 'completed', 'T-2'));
        $this->actingAsCanonicalFixture(rank: 'Captain');

        $response = $this->getJson('/api/public/command/overview')->assertOk();

        $response->assertJsonCount(1, 'stations')->assertJsonPath('total', 1);
        $row = $response->json('stations.0');
        $this->assertSame($station->id, $row['id']);
        $this->assertSame(1, $row['open_defects']);
        $this->assertSame(1, $row['open_service_tickets']);
        $this->assertSame(2, $row['apparatus_count']);
        $this->assertNull($row['last_checkout_at']);
        $byName = collect($row['apparatuses'])->keyBy('name');
        $this->assertSame('in_service', $byName['E1']['service_state']);
        $this->assertSame('out_of_service', $byName['R1']['service_state']);
        $this->assertSame(1, $byName['E1']['open_defects']);

        $body = $response->getContent();
        foreach (['SECRET-STATION-NOTE', 'SECRETVIN', 'SECRET-APPARATUS-NOTE', 'captain_in_charge', '"vin"', '"notes"', 'budget'] as $restricted) {
            $this->assertStringNotContainsString($restricted, $body);
        }
    }

    public function test_members_without_300_command_access_are_forbidden(): void
    {
        $this->actingAsCanonicalFixture(rank: 'Lieutenant');
        $this->getJson('/api/public/command/overview')->assertForbidden();
    }

    public function test_query_count_does_not_grow_with_station_count(): void
    {
        $this->actingAsCanonicalFixture(rank: 'Captain');
        $first = $this->station(1, true);
        $this->apparatus($first, 'E1', 'In Service');
        $this->countQueries(); // Warm the one-time permission cache read by the 300 access check.
        $small = $this->countQueries();

        foreach ([2, 3, 4, 6] as $number) {
            $station = $this->station($number, true);
            $this->apparatus($station, "E{$number}", 'In Service');
            $this->apparatus($station, "L{$number}", 'In Service');
        }

        $this->assertSame($small, $this->countQueries());
    }

    private function countQueries(): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson('/api/public/command/overview')->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    private function station(int $number, bool $active): Station
    {
        return Station::query()->create([
            'station_number' => $number, 'name' => "Station {$number}", 'address' => '1 Main St', 'city' => 'Miami Beach',
            'state' => 'FL', 'zip_code' => '33139', 'is_active' => $active, 'notes' => 'SECRET-STATION-NOTE', 'captain_in_charge' => 'Hidden Person',
        ]);
    }

    private function apparatus(Station $station, string $designation, string $status): Apparatus
    {
        return Apparatus::query()->create([
            'station_id' => $station->id, 'unit_id' => $designation.'-'.uniqid(), 'name' => $designation, 'type' => 'Engine',
            'vehicle_number' => $designation, 'designation' => $designation, 'slug' => strtolower($designation).'-'.uniqid(),
            'vin' => 'SECRETVIN123', 'status' => $status, 'notes' => 'SECRET-APPARATUS-NOTE',
        ]);
    }

    /** @return array<string, mixed> */
    private function ticket(Apparatus $apparatus, string $status, string $number): array
    {
        return [
            'ticket_number' => $number, 'apparatus_id' => $apparatus->id, 'station_id' => $apparatus->station_id,
            'unit_designation_snapshot' => $apparatus->designation,
            'title' => 'Test ticket', 'description' => 'Test', 'status' => $status, 'priority' => 'normal', 'origin' => 'test',
            'category' => 'mechanical',
        ];
    }
}
