<?php

declare(strict_types=1);

namespace Tests\Feature\VideoConferencing;

use App\Enums\VideoConferencing\ConferenceJoinRole;
use App\Models\Employee;
use App\Models\Station;
use App\Services\VideoConferencing\ConferenceBootstrapFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Production station ids (29-33) differ from station numbers (1, 2, 3, 4, 6).
 * The member route /daily/stations/:id takes the database id, so conference
 * back links must resolve the id from the station number.
 */
final class StationConferenceBackLinkTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, int> station number => id */
    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['video-conferencing.enabled' => true, 'app.url' => 'https://www.mbfdhub.com']);
        $this->withoutVite();
        foreach ([1 => 29, 2 => 30, 3 => 31, 4 => 32, 6 => 33] as $number => $id) {
            $station = new Station;
            $station->forceFill([
                'id' => $id,
                'station_number' => $number,
                'name' => "Station {$number}",
                'address' => "{$number} Test Street",
                'is_active' => true,
            ])->save();
            $this->ids[$number] = $id;
        }
    }

    public function test_station_endpoint_back_link_opens_the_station_by_database_id(): void
    {
        foreach ([1, 2, 3, 4, 6] as $number) {
            $this->get("/video-conferencing/stations/{$number}")
                ->assertOk()
                ->assertViewHas('conferenceBootstrap', fn (array $data): bool => $data['navigation']['back'] === '/daily/stations/'.$this->ids[$number]);
        }
    }

    public function test_station_back_link_falls_back_to_the_station_list_when_the_station_is_missing(): void
    {
        Station::query()->whereKey($this->ids[6])->delete();

        $this->get('/video-conferencing/stations/6')
            ->assertOk()
            ->assertViewHas('conferenceBootstrap', fn (array $data): bool => $data['navigation']['back'] === '/daily/stations');
    }

    public function test_command_return_destination_accepts_station_ids_and_translates_legacy_station_numbers(): void
    {
        $this->actingAs($this->employee(), 'employee');
        $cases = [
            '/daily/stations/30' => '/daily/stations/30',
            'https://www.mbfdhub.com/daily/stations/33' => '/daily/stations/33',
            '/daily/stations/2' => '/daily/stations/30',
            '/daily/stations/6' => '/daily/stations/33',
            '/daily/stations/5' => '/',
            '/daily/stations/999' => '/',
            '/daily/stations/30?next=https://evil.example' => '/',
            '/employee' => '/employee',
        ];

        foreach ($cases as $target => $expected) {
            $this->get('/employee/video-conferencing/command?'.http_build_query(['return_to' => $target]))
                ->assertViewHas('conferenceBootstrap', fn (array $data): bool => $data['navigation']['back'] === $expected);
        }
    }

    public function test_station_ids_are_resolved_with_a_single_query_per_request(): void
    {
        $factory = app(ConferenceBootstrapFactory::class);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $factory->make('station', ConferenceJoinRole::Station2);
        $factory->make('station', ConferenceJoinRole::Station4);
        $stationQueries = collect(DB::getQueryLog())
            ->filter(fn (array $query): bool => str_contains((string) $query['query'], '"stations"'))
            ->count();
        DB::disableQueryLog();

        self::assertSame(1, $stationQueries);
    }

    private function employee(): Employee
    {
        return Employee::query()->create([
            'employee_id' => 'F043',
            'name' => 'Jordan Lee',
            'rank' => 'Captain',
            'password' => 'ConferenceTest!1',
            'must_change_password' => false,
        ]);
    }
}
