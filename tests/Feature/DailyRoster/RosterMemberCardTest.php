<?php

declare(strict_types=1);

namespace Tests\Feature\DailyRoster;

use App\Enums\AccountStatus;
use App\Models\DailyRoster\RosterAssignment;
use App\Models\Employee;
use App\Models\EmployeeBidAssignment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\DailyRosterSnapshotFactory;
use Tests\TestCase;

/** The card behind a name on the Daily Roster: same audience as the roster, and only people on the roster on screen. */
final class RosterMemberCardTest extends TestCase
{
    use RefreshDatabase;

    private const TODAY = '2026-10-30';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(CarbonImmutable::parse('2026-10-30 10:30:00', 'America/New_York'));
        $this->roster();
    }

    public function test_a_guest_is_sent_to_login(): void
    {
        $this->get($this->card('90001'))->assertRedirect('/login');
    }

    public function test_an_account_that_cannot_read_the_roster_is_refused(): void
    {
        $this->actingAs(User::factory()->create(['account_status' => AccountStatus::Active]));

        $this->get($this->card('90001'))->assertForbidden();
    }

    public function test_it_shows_roster_certifications_bid_and_profile_and_is_never_cached(): void
    {
        $employee = $this->employee('90001', ['display_name' => 'Alpha Tester', 'rank' => 'Lieutenant', 'station' => 'Station 3', 'city_email' => 'alpha.tester@example.gov', 'phone' => '305-555-0199']);
        $this->bid($employee);
        $this->actingAs($this->viewer());

        $response = $this->get($this->card('90001'))->assertOk();

        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $response->assertSeeInOrder(['Alpha Tester', 'Lieutenant', '90001']);
        $response->assertSee('Engine 1')->assertSee('Combat Lieutenant')->assertSee('08:00')->assertSee('SP');
        foreach (['PM', 'DE', 'HMO'] as $certification) {
            $response->assertSee($certification);
        }
        foreach (['2026–2027', 'C Shift', 'Combat Float Pool', 'Combat Float', 'Firefighter #5', 'Group 4', 'Floating'] as $bid) {
            $response->assertSee($bid);
        }
        $response->assertSee('Station 3')->assertSee('mailto:alpha.tester@example.gov', false);
    }

    public function test_it_never_shows_the_phone_number_or_account_details(): void
    {
        $this->employee('90001', ['phone' => '305-555-0199', 'city_email' => 'alpha.tester@example.gov']);
        $this->actingAs($this->viewer());

        $this->get($this->card('90001'))->assertOk()->assertDontSee('305-555-0199')->assertDontSee('Last login')->assertDontSee('Account status');
    }

    public function test_every_row_and_every_printed_certification_for_the_member_appears(): void
    {
        $this->roster([
            'Station 1 / Engine 1' => [['employee_id' => '90001', 'name' => 'TESTER, ALPHA', 'through' => '20:00', 'code' => 'SP', 'category' => 'Scheduled', 'certifications' => ['PM']]],
            'Station 3 / Rescue 3' => [['employee_id' => '90001', 'name' => 'TESTER, ALPHA', 'from' => '20:00', 'through' => '08:00', 'code' => '+OT', 'category' => 'Overtime', 'certifications' => ['HMT']]],
        ]);
        $this->actingAs($this->viewer());

        $this->get($this->card('90001'))->assertOk()
            ->assertSee('Engine 1')->assertSee('Rescue 3')->assertSee('PM')->assertSee('HMT')->assertSee('20:00');
    }

    public function test_an_id_not_on_the_roster_on_screen_gets_nothing_so_the_directory_cannot_be_walked(): void
    {
        $this->employee('90002', ['city_email' => 'elsewhere@example.gov']);
        $this->actingAs($this->viewer());

        $this->get($this->card('90002'))->assertNotFound();
        $this->get('/daily-roster/members/'.Str::repeat('9', 40))->assertNotFound();
    }

    public function test_opening_cards_has_its_own_limit_and_cannot_starve_the_roster_refresh(): void
    {
        $this->actingAs($this->viewer());

        for ($open = 0; $open < 30; $open++) {
            $this->get($this->card('90001'))->assertOk();
        }

        $this->get($this->card('90001'))->assertTooManyRequests();
        $this->get('/daily-roster/sections')->assertOk();
    }

    public function test_a_vacancy_has_no_card_and_no_control(): void
    {
        $this->actingAs($this->viewer());

        $this->get('/daily-roster')->assertOk()
            ->assertSee('data-roster-member="90001"', false)
            ->assertDontSee('data-roster-member=""', false);
    }

    public function test_it_reads_the_date_on_screen(): void
    {
        DailyRosterSnapshotFactory::publish('2026-10-29', ['Station 3 / Rescue 3' => [['employee_id' => '90009', 'name' => 'EARLIER, NINE', 'code' => 'SP', 'category' => 'Scheduled']]]);
        $this->actingAs($this->viewer());

        $this->get($this->card('90009'))->assertNotFound();
        $this->get($this->card('90009').'?date=2026-10-29')->assertOk()->assertSee('Rescue 3')->assertSee('EARLIER, NINE');
    }

    public function test_it_follows_the_page_when_the_next_shift_is_already_posted(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-31 07:30:00', 'America/New_York'));
        DailyRosterSnapshotFactory::publish('2026-10-31', ['Station 3 / Rescue 3' => [['employee_id' => '90009', 'name' => 'NEXT, NINE', 'code' => 'SP', 'category' => 'Scheduled']]]);
        $this->actingAs($this->viewer());

        $this->get('/daily-roster')->assertOk()->assertSee('NEXT, NINE');
        $this->get($this->card('90009'))->assertOk()->assertSee('Rescue 3');
        $this->get($this->card('90001').'?date='.self::TODAY)->assertOk()->assertSee('Engine 1');
    }

    public function test_a_member_with_no_hub_profile_shows_telestaff_data_only(): void
    {
        $this->actingAs($this->viewer());

        $this->get($this->card('90001'))->assertOk()->assertSee('TESTER, ALPHA')->assertSee('PM')->assertSee('No Hub profile is linked');
    }

    public function test_a_departed_member_shows_telestaff_data_only(): void
    {
        $this->employee('90001', ['roster_status' => 'departed', 'display_name' => 'Gone Member', 'city_email' => 'gone@example.gov']);
        $this->actingAs($this->viewer());

        $this->get($this->card('90001'))->assertOk()->assertSee('TESTER, ALPHA')->assertDontSee('Gone Member')->assertDontSee('gone@example.gov');
    }

    public function test_source_text_is_escaped(): void
    {
        $this->roster(['Station 1 / Engine 1' => [['employee_id' => '90001', 'name' => '<script>alert(1)</script>', 'code' => 'SP', 'category' => 'Scheduled', 'certifications' => ['<b>X</b>']]]]);
        $this->actingAs($this->viewer());

        $this->get($this->card('90001'))->assertOk()->assertDontSee('<script>alert(1)</script>', false)->assertDontSee('<b>X</b>', false);
    }

    private function card(string $employeeId): string
    {
        return '/daily-roster/members/'.$employeeId;
    }

    /** @param array<string, list<array<string, mixed>>>|null $sections */
    private function roster(?array $sections = null): void
    {
        DailyRosterSnapshotFactory::publish(self::TODAY, $sections ?? [
            'Station 1 / Engine 1' => [
                ['employee_id' => '90001', 'name' => 'TESTER, ALPHA', 'rank' => 'Combat Lieutenant', 'code' => 'SP', 'label' => 'SP', 'category' => 'Scheduled', 'certifications' => ['PM', 'DE', 'HMO']],
                ['kind' => RosterAssignment::KIND_VACANCY_OPEN, 'name' => '?,?', 'code' => null, 'rank' => 'Firefighter'],
            ],
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function employee(string $employeeId, array $attributes = []): Employee
    {
        return Employee::query()->create([
            'employee_id' => $employeeId, 'name' => 'Member '.$employeeId, 'rank' => 'Firefighter', 'roster_status' => 'active', ...$attributes,
        ]);
    }

    private function viewer(): User
    {
        $employee = $this->employee('70001');

        return User::factory()->create([
            'account_status' => AccountStatus::Active,
            'employee_id' => '70001',
            'employee_profile_id' => $employee->id,
            'must_change_password' => false,
        ]);
    }

    private function bid(Employee $employee): void
    {
        EmployeeBidAssignment::query()->create([
            'employee_profile_id' => $employee->id, 'payload_version' => 2, 'bid_year' => 2026, 'term_label' => '2026–2027',
            'bid_session_id' => (string) Str::ulid(), 'position_id' => 'C713', 'rank_label' => 'Firefighter', 'shift_label' => 'C Shift',
            'station_label' => 'Combat Float Pool', 'division_label' => 'Combat', 'unit_label' => 'Combat Float', 'position_label' => 'Firefighter #5',
            'bid_selection_label' => 'Combat Float', 'assignment_type' => 'Floating', 'assignment_source' => 'bid_award', 'a_day_code' => 'G4', 'a_day_label' => 'Group 4',
            'picked_at' => now(), 'idempotency_key' => (string) Str::uuid(), 'payload_hash' => hash('sha256', 'x'), 'is_forced' => false,
        ]);
    }
}
