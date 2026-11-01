<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\AccountStatus;
use App\Models\Station;
use App\Models\User;
use App\Support\Captain5\DutyCalendar;
use App\Support\HubTimezone;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;
use Tests\Support\DailyRosterSnapshotFactory;
use Tests\TestCase;

/**
 * The Today's Roster tab of a station page: only that station's units, only the crew on duty right now, and only for
 * members the Daily Roster itself admits. All names here are synthetic.
 */
final class StationRosterApiTest extends TestCase
{
    use RefreshDatabase;

    private const NOON = '2026-10-20 12:00:00';

    private const TODAY = '2026-10-20';

    private const TOMORROW = '2026-10-21';

    private const YESTERDAY = '2026-10-19';

    private Station $stationOne;

    protected function setUp(): void
    {
        parent::setUp();
        config(['daily_roster.mode' => 'publish']);
        $this->travelToLocal(self::NOON);
        $this->stationOne = $this->station(1);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_a_station_gets_only_its_own_units_in_roster_order(): void
    {
        $this->actingAsCanonicalFixture();
        DailyRosterSnapshotFactory::publish(self::TODAY, [
            'Division Chief / Division Chief 300' => [['employee_id' => '50001', 'name' => 'CHIEF, ALPHA']],
            'Station 1 / Ladder 1' => [['employee_id' => '50002', 'name' => 'LADDER, ALPHA']],
            'Station 1 / Engine 1' => [['employee_id' => '50003', 'name' => 'ENGINE, ALPHA']],
            'Station 1 / Rescue 1' => [['employee_id' => '50004', 'name' => 'RESCUE, ALPHA']],
            'Station 1 / Rescue 11' => [['employee_id' => '50005', 'name' => 'RESCUE, BETA']],
            'Station 2 / Engine 2' => [['employee_id' => '50006', 'name' => 'ENGINE, BETA']],
            'Station 11 / Engine 11' => [['employee_id' => '50007', 'name' => 'ENGINE, GAMMA']],
            'Rescue Float Pool / Rescue Float Pool' => [['employee_id' => '50008', 'name' => 'FLOAT, ALPHA']],
        ]);

        $response = $this->roster($this->stationOne)->assertOk()->assertJsonPath('available', true);

        self::assertSame(['Ladder 1', 'Engine 1', 'Rescue 1', 'Rescue 11'], array_column($response->json('sections'), 'unit'));
        $response->assertDontSee('CHIEF, ALPHA')->assertDontSee('ENGINE, BETA')->assertDontSee('ENGINE, GAMMA')->assertDontSee('FLOAT, ALPHA');
    }

    public function test_it_says_which_shift_it_is_and_carries_each_row_the_way_the_page_shows_it(): void
    {
        $this->actingAsCanonicalFixture();
        DailyRosterSnapshotFactory::publish(self::TODAY, ['Station 1 / Engine 1' => [
            ['employee_id' => '50002', 'name' => 'DAY, ALPHA', 'rank' => 'Lt', 'seat' => 0, 'code' => '+OT', 'category' => 'Overtime', 'from' => '08:00', 'through' => '20:00', 'hours' => 12.0, 'certifications' => ['PM', 'AO']],
            ['employee_id' => '50003', 'name' => 'NIGHT, ALPHA', 'rank' => 'Lt', 'seat' => 0, 'code' => '+OT', 'category' => 'Overtime', 'from' => '20:00', 'through' => '08:00', 'hours' => 12.0],
        ]]);

        $response = $this->roster($this->stationOne)->assertOk()
            ->assertJsonPath('date_label', 'Tuesday, October 20')
            ->assertJsonPath('shift', app(DutyCalendar::class)->shiftFor(self::TODAY).' Shift')
            ->assertJsonPath('tag.label', 'Data current')
            ->assertJsonPath('next_shift_posted', false);

        $seat = $response->json('sections.0.seats.0');
        self::assertCount(2, $seat, 'A split seat stays one seat.');
        self::assertSame('DAY, ALPHA', $seat[0]['name']);
        self::assertSame('50002', $seat[0]['employee_id']);
        self::assertSame(['PM', 'AO'], $seat[0]['certifications']);
        self::assertSame('ot', $seat[0]['status']['tone']);
        self::assertSame('08:00', $seat[0]['from']);
        self::assertSame('20:00', $seat[0]['through']);
        self::assertSame('12.0', $seat[0]['hours']);
        self::assertTrue($seat[0]['is_first_in_seat']);
        self::assertFalse($seat[1]['is_first_in_seat']);
        self::assertSame('20:00', $seat[1]['from']);
    }

    public function test_when_the_next_shift_is_posted_early_the_tab_stays_on_the_crew_working_until_0800(): void
    {
        $this->actingAsCanonicalFixture();
        $this->publishBoth();

        $this->roster($this->stationOne)->assertOk()
            ->assertJsonPath('next_shift_posted', true)
            ->assertJsonPath('tag.label', 'On duty now')
            ->assertSee('TODAY, CREW')
            ->assertDontSee('TOMORROW, CREW');

        // Sign in again after each jump: a day of idle time ends a session.
        $this->travelToLocal(self::TOMORROW.' 07:59:00');
        $this->logoutCanonicalSession();
        $this->actingAsCanonicalFixture('E01-TEST-LAST-MINUTE');
        $this->roster($this->stationOne)->assertOk()->assertSee('TODAY, CREW')->assertDontSee('TOMORROW, CREW');

        $this->travelToLocal(self::TOMORROW.' 08:00:00');
        $this->roster($this->stationOne)->assertOk()
            ->assertJsonPath('next_shift_posted', false)
            ->assertSee('TOMORROW, CREW')
            ->assertDontSee('TODAY, CREW');
    }

    public function test_it_never_shows_another_day_as_today(): void
    {
        $this->actingAsCanonicalFixture();

        $this->roster($this->stationOne)->assertOk()
            ->assertJsonPath('available', false)
            ->assertJsonPath('sections', []);

        DailyRosterSnapshotFactory::publish(self::YESTERDAY, ['Station 1 / Engine 1' => [['employee_id' => '50001', 'name' => 'YESTERDAY, CREW']]]);
        $this->roster($this->stationOne)->assertOk()
            ->assertJsonPath('available', false)
            ->assertDontSee('YESTERDAY, CREW');

        DailyRosterSnapshotFactory::publish(self::TOMORROW, ['Station 1 / Engine 1' => [['employee_id' => '50002', 'name' => 'TOMORROW, CREW']]]);
        $this->roster($this->stationOne)->assertOk()
            ->assertJsonPath('available', false)
            ->assertJsonPath('next_shift_posted', true)
            ->assertDontSee('TOMORROW, CREW');
    }

    public function test_a_station_the_roster_does_not_list_gets_an_empty_list_not_an_error(): void
    {
        $this->actingAsCanonicalFixture();
        DailyRosterSnapshotFactory::publish(self::TODAY, ['Station 2 / Engine 2' => [['employee_id' => '50001', 'name' => 'ENGINE, ALPHA']]]);

        $this->roster($this->stationOne)->assertOk()
            ->assertJsonPath('available', true)
            ->assertJsonPath('sections', []);
    }

    public function test_access_follows_the_daily_roster_rule_and_the_response_is_never_stored(): void
    {
        DailyRosterSnapshotFactory::publish(self::TODAY, ['Station 1 / Engine 1' => [['employee_id' => '50001', 'name' => 'ENGINE, ALPHA']]]);

        $this->roster($this->stationOne)->assertUnauthorized();

        $this->actingAsCanonicalUser(User::factory()->create());
        $refused = $this->roster($this->stationOne)->assertForbidden()->assertDontSee('ENGINE, ALPHA');
        self::assertStringContainsString('no-store', (string) $refused->headers->get('Cache-Control'));

        $this->logoutCanonicalSession();
        $this->actingAsCanonicalFixture();
        $cacheControl = (string) $this->roster($this->stationOne)->assertOk()->headers->get('Cache-Control');
        self::assertStringContainsString('no-store', $cacheControl);
        self::assertStringContainsString('private', $cacheControl);
    }

    public function test_an_administrator_without_a_personnel_profile_is_admitted_like_on_the_full_page(): void
    {
        DailyRosterSnapshotFactory::publish(self::TODAY, ['Station 1 / Engine 1' => [['employee_id' => '50001', 'name' => 'ENGINE, ALPHA']]]);
        $admin = User::factory()->create(['is_admin' => true, 'account_status' => AccountStatus::Active]);
        $admin->assignRole(Role::findOrCreate('super_admin', 'web'));
        $this->actingAsCanonicalUser($admin);

        $this->roster($this->stationOne)->assertOk()->assertSee('ENGINE, ALPHA');
        $this->getJson('/api/me/context')->assertOk()->assertJsonPath('authorization.capabilities.daily_roster', true);
    }

    public function test_the_tab_hint_follows_the_same_rule(): void
    {
        $this->actingAsCanonicalFixture();
        $this->getJson('/api/me/context')->assertOk()->assertJsonPath('authorization.capabilities.daily_roster', true);

        config(['daily_roster.mode' => 'shadow']);
        $this->getJson('/api/me/context')->assertOk()->assertJsonPath('authorization.capabilities.daily_roster', false);
        config(['daily_roster.mode' => 'publish']);

        $this->logoutCanonicalSession();
        $this->actingAsCanonicalUser(User::factory()->create());
        $this->getJson('/api/me/context')->assertOk()->assertJsonPath('authorization.capabilities.daily_roster', false);
    }

    public function test_the_daily_service_worker_does_not_keep_the_roster_offline(): void
    {
        $worker = (string) file_get_contents(resource_path('js/daily-checkout/public/service-worker.js'));
        self::assertSame(1, preg_match('/const OFFLINE_READ_MODELS = \[(.*?)\n\];/s', $worker, $list), 'The offline list moved; this test must follow it.');
        preg_match_all('/^\s*\/(\^.*\$)\/,$/m', $list[1], $bodies);
        $matches = static fn (string $path): array => array_filter($bodies[1], static fn (string $body): bool => preg_match('#'.$body.'#', $path) === 1);

        self::assertNotSame([], $matches('/api/public/stations/1/gas-meters'), 'The patterns are read correctly.');
        self::assertSame([], $matches('/api/public/stations/1/roster'), 'Names and leave codes must not stay on a shared device.');
    }

    private function publishBoth(): void
    {
        DailyRosterSnapshotFactory::publish(self::TODAY, ['Station 1 / Engine 1' => [['employee_id' => '50001', 'name' => 'TODAY, CREW']]]);
        DailyRosterSnapshotFactory::publish(self::TOMORROW, ['Station 1 / Engine 1' => [['employee_id' => '50002', 'name' => 'TOMORROW, CREW']]], [
            'generated_at' => CarbonImmutable::parse(self::TODAY.' 11:55', HubTimezone::NAME),
        ]);
    }

    private function roster(Station $station): TestResponse
    {
        return $this->getJson("/api/public/stations/{$station->id}/roster");
    }

    private function station(int $number): Station
    {
        return Station::query()->create([
            'station_number' => $number,
            'name' => "Station {$number}",
            'address' => "{$number} Test Street",
            'is_active' => true,
        ]);
    }

    private function travelToLocal(string $local): void
    {
        $moment = CarbonImmutable::parse($local, HubTimezone::NAME);
        Carbon::setTestNow($moment);
        CarbonImmutable::setTestNow($moment);
    }
}
