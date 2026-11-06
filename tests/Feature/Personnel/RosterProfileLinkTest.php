<?php

declare(strict_types=1);

namespace Tests\Feature\Personnel;

use App\Enums\AccountStatus;
use App\Filament\Resources\EmployeeResource;
use App\Models\Employee;
use App\Models\User;
use App\Services\DailyRoster\RosterStatusPill;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Filament\StationAdminFixtures;
use Tests\TestCase;

/** A Daily Roster name opens the member's profile by the verified Employee ID, never by name. */
final class RosterProfileLinkTest extends TestCase
{
    use RefreshDatabase;
    use StationAdminFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpStationAdmin();
    }

    public function test_an_exact_employee_id_redirects_to_the_profile(): void
    {
        $employee = $this->employee('61301');
        $this->actingAsSuperAdmin();

        $this->get('/admin/personnel/by-employee-id/61301')
            ->assertRedirect(EmployeeResource::getUrl('edit', ['record' => $employee], panel: 'admin'));
    }

    public function test_a_single_leading_zero_match_redirects_and_a_departed_member_still_resolves(): void
    {
        $employee = $this->employee('0061302', 'departed');
        $this->actingAsSuperAdmin();

        $this->get('/admin/personnel/by-employee-id/61302')
            ->assertRedirect(EmployeeResource::getUrl('edit', ['record' => $employee], panel: 'admin'));
    }

    public function test_an_unknown_id_shows_a_friendly_not_found_page(): void
    {
        $this->employee('61303');
        $this->actingAsSuperAdmin();

        $this->get('/admin/personnel/by-employee-id/99999')->assertNotFound()
            ->assertSee('No profile has this Employee ID')->assertSee('99999');
    }

    public function test_ambiguous_normalized_ids_never_redirect(): void
    {
        $this->employee('061304');
        $this->employee('0061304');
        $this->actingAsSuperAdmin();

        $response = $this->get('/admin/personnel/by-employee-id/61304');
        $response->assertStatus(409)->assertSee('More than one profile has this Employee ID');
        self::assertFalse($response->isRedirect());
    }

    public function test_a_member_without_profile_access_is_refused_before_any_lookup(): void
    {
        $this->employee('61305');
        $member = $this->member('61306');
        $this->actingAs($member);

        $this->get('/admin/personnel/by-employee-id/61305')->assertForbidden();
        $this->get('/admin/personnel/by-employee-id/00000')->assertForbidden();
    }

    public function test_seat_renders_a_profile_link_only_for_viewers_who_may_open_profiles(): void
    {
        $this->employee('61307');
        $this->actingAsSuperAdmin();
        $admin = $this->renderSeat();
        self::assertStringContainsString('href="'.route('daily-roster.profile', ['employeeId' => '61307']).'"', $admin);
        self::assertStringContainsString('data-roster-member="61307"', $admin);
        self::assertStringContainsString('class="roster-member__name roster-member__name--link"', $admin);
        self::assertStringNotContainsString('by-employee-id/"', $admin, 'A vacant row has no link.');

        $this->actingAs($this->member('61308'));
        $member = $this->renderSeat();
        self::assertStringNotContainsString('by-employee-id', $member);
        self::assertStringContainsString('<button type="button" class="roster-member__name roster-member__name--link" data-roster-member="61307" aria-haspopup="dialog">', $member);
    }

    public function test_station_roster_rows_carry_a_profile_url_only_for_profile_viewers(): void
    {
        config(['daily_roster.mode' => 'publish']);
        $noon = \Carbon\CarbonImmutable::parse('2026-10-20 12:00:00', \App\Support\HubTimezone::NAME);
        \Carbon\Carbon::setTestNow($noon);
        \Carbon\CarbonImmutable::setTestNow($noon);
        try {
            $station = \App\Models\Station::query()->create(['station_number' => 1, 'name' => 'Station 1', 'address' => '1 Test Street', 'is_active' => true]);
            \Tests\Support\DailyRosterSnapshotFactory::publish('2026-10-20', ['Station 1 / Engine 1' => [['employee_id' => '50001', 'name' => 'ENGINE, ALPHA']]]);

            $admin = User::factory()->create(['is_admin' => true, 'account_status' => AccountStatus::Active]);
            $admin->assignRole(\Spatie\Permission\Models\Role::findOrCreate('super_admin', 'web'));
            $this->actingAsCanonicalUser($admin);
            $this->getJson("/api/public/stations/{$station->id}/roster")->assertOk()
                ->assertJsonPath('sections.0.seats.0.0.profile_url', route('daily-roster.profile', ['employeeId' => '50001']));

            $this->logoutCanonicalSession();
            $this->actingAsCanonicalFixture();
            $this->getJson("/api/public/stations/{$station->id}/roster")->assertOk()
                ->assertJsonPath('sections.0.seats.0.0.name', 'ENGINE, ALPHA')
                ->assertJsonPath('sections.0.seats.0.0.profile_url', null);
        } finally {
            \Carbon\Carbon::setTestNow();
            \Carbon\CarbonImmutable::setTestNow();
        }
    }

    private function renderSeat(): string
    {
        $pill = new RosterStatusPill('On duty', 'On duty', 'neutral', 'Working', 'Working', '');
        $row = fn (string $id, string $name, bool $vacant): array => [
            'isFirstInSeat' => true, 'isVacant' => $vacant, 'rank' => 'FF', 'employeeId' => $id, 'name' => $name,
            'sourceMarker' => '', 'certifications' => [], 'pill' => $pill, 'from' => '08:00', 'through' => '08:00', 'hours' => '24',
        ];

        return view('daily-roster._seat', ['seat' => [$row('61307', 'MEMBER, ROSTER', false), $row('', 'Vacant', true)], 'seatIndex' => 0])->render();
    }

    private function employee(string $id, string $status = 'active'): Employee
    {
        return Employee::query()->create(['employee_id' => $id, 'name' => 'Member '.$id, 'rank' => 'Firefighter',
            'station' => '1', 'roster_status' => $status]);
    }

    private function member(string $id): User
    {
        $employee = Employee::query()->create(['employee_id' => $id, 'name' => 'Member '.$id, 'rank' => 'Firefighter',
            'roster_status' => 'active', 'password' => 'unused', 'must_change_password' => false]);

        return User::factory()->create(['account_status' => AccountStatus::Active, 'employee_profile_id' => $employee->id,
            'employee_id' => $id, 'must_change_password' => false])->fresh();
    }
}
