<?php

declare(strict_types=1);

namespace Tests\Feature\DailyRoster;

use App\Enums\AccountStatus;
use App\Models\DailyRoster\RosterAssignment;
use App\Models\DailyRoster\RosterImport;
use App\Models\User;
use App\Services\DailyRoster\RosterFreshnessResolver;
use App\Services\Identity\CanonicalLoginDestination;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\DailyRosterSnapshotFactory;
use Tests\TestCase;

final class DailyRosterPageTest extends TestCase
{
    use RefreshDatabase;

    private const PAGE = '/daily-roster';

    private const FRAGMENT = '/daily-roster/sections';

    private const VERSION = '/api/daily-roster/version';

    /** Friday 10:30 ET: inside the duty day that started at 08:00. */
    private const NOW = '2026-10-30 10:30:00';

    private const TODAY = '2026-10-30';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(CarbonImmutable::parse(self::NOW, 'America/New_York'));
    }

    public function test_last_updated_is_when_the_hub_updated_in_miami_beach_time(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-30 09:14:25', 'America/New_York'));
        DailyRosterSnapshotFactory::publish(self::TODAY, ['Station 2 / Captain 5' => [['employee_id' => '90001', 'name' => 'TESTER, ALPHA']]], [
            'generated_at' => CarbonImmutable::parse('2026-10-30 09:14:15', 'America/New_York'),
        ]);
        $this->travelTo(CarbonImmutable::parse(self::NOW, 'America/New_York'));
        $this->actingAsCanonicalFixture();

        $this->get(self::PAGE)->assertOk()->assertSeeInOrder(['Last updated', 'Oct 30, 9:14 AM EDT']);
    }

    public function test_last_updated_names_standard_time_after_the_clock_change(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-02 09:14:25', 'America/New_York'));
        DailyRosterSnapshotFactory::publish('2026-11-02', ['Station 2 / Captain 5' => [['employee_id' => '90001', 'name' => 'TESTER, ALPHA']]], [
            'generated_at' => CarbonImmutable::parse('2026-11-02 09:14:15', 'America/New_York'),
        ]);
        $this->actingAsCanonicalFixture();

        $this->get(self::PAGE)->assertOk()->assertSeeInOrder(['Last updated', 'Nov 2, 9:14 AM EST']);
    }

    public function test_a_guest_is_sent_to_login_and_the_deep_link_survives(): void
    {
        $this->get(self::PAGE.'?date='.self::TODAY)->assertRedirect('/login');
        $this->get(self::FRAGMENT)->assertRedirect('/login');
        $this->getJson(self::VERSION)->assertUnauthorized();

        $this->get(self::PAGE)->assertRedirect('/login');
        self::assertStringEndsWith(self::PAGE, (string) session('url.intended'));
        $member = User::factory()->create(['account_status' => AccountStatus::Active]);
        self::assertSame(
            self::PAGE,
            parse_url(app(CanonicalLoginDestination::class)->resolve($member, session('url.intended')), PHP_URL_PATH),
        );
    }

    public function test_an_account_without_a_personnel_profile_is_refused_everywhere(): void
    {
        $this->publishRoster();
        $this->actingAsCanonicalUser(User::factory()->create());

        foreach ([self::PAGE, self::FRAGMENT, self::VERSION] as $url) {
            $response = $this->get($url)->assertForbidden();
            $response->assertDontSee('ALPHA, MEMBER');
            $this->assertNoStore($response);
        }
    }

    public function test_a_member_with_a_profile_can_read_the_roster(): void
    {
        $this->publishRoster();
        $this->actingAsCanonicalFixture();

        $response = $this->get(self::PAGE)->assertOk();

        $this->assertNoStore($response);
        $response->assertSee('Daily Roster')
            ->assertSee('Friday, October 30')
            ->assertSee('Suppression/Rescue')
            ->assertSee('ALPHA, MEMBER')
            ->assertSee('Data current')
            ->assertSee('TeleStaff')
            ->assertSee('B Shift')
            ->assertSee('Duty window')
            ->assertSee('Oct 30, 08:00 to Oct 31, 08:00 ET')
            ->assertSee('Report generated')
            ->assertSee('Last updated')
            ->assertSee('data-hub-shell', false)
            ->assertDontSee('Personnel')
            ->assertDontSee('Export')
            ->assertDontSee('Print');
    }

    public function test_an_administrator_without_a_profile_can_read_the_roster(): void
    {
        $this->publishRoster();
        $admin = User::factory()->create(['is_admin' => true, 'account_status' => AccountStatus::Active]);
        $admin->assignRole(\Spatie\Permission\Models\Role::findOrCreate('super_admin', 'web'));
        $this->actingAsCanonicalUser($admin);

        $this->get(self::PAGE)->assertOk()->assertSee('ALPHA, MEMBER');
    }

    public function test_all_three_endpoints_are_no_store_and_answer_304_for_a_matching_version(): void
    {
        $this->publishRoster();
        $this->actingAsCanonicalFixture();

        $first = $this->getJson(self::VERSION)->assertOk();
        $version = (string) $first->json('version');
        self::assertSame($first->headers->get('ETag'), '"'.$version.'"');
        self::assertSame(app(RosterFreshnessResolver::class)->status()->version, $version);
        self::assertSame('current', $first->json('state'));
        self::assertSame(self::TODAY, $first->json('duty_date'));
        $this->assertNoStore($first);

        foreach (['"'.$version.'"', 'W/"'.$version.'"', '"other", "'.$version.'"'] as $header) {
            $notModified = $this->get(self::VERSION, ['If-None-Match' => $header])->assertStatus(304);
            $this->assertNoStore($notModified);
            self::assertSame('', $notModified->getContent());
        }
        $this->get(self::VERSION, ['If-None-Match' => '"other"'])->assertOk();

        $fragment = $this->get(self::FRAGMENT)->assertOk();
        $this->assertNoStore($fragment);
        $this->assertNoStore($this->get(self::FRAGMENT, ['If-None-Match' => (string) $fragment->headers->get('ETag')])->assertStatus(304));
    }

    public function test_the_fragment_is_the_swappable_body_without_the_page_shell(): void
    {
        $this->publishRoster();
        $this->actingAsCanonicalFixture();

        $version = app(RosterFreshnessResolver::class)->status()->version;
        $this->get(self::FRAGMENT)->assertOk()
            ->assertSee('data-roster-body', false)
            ->assertSee('data-version="'.$version.'"', false)
            ->assertSee('ALPHA, MEMBER')
            ->assertDontSee('<html', false)
            ->assertDontSee('data-hub-shell', false);
    }

    public function test_a_new_export_changes_the_version_and_the_page(): void
    {
        $this->publishRoster();
        $this->actingAsCanonicalFixture();
        $version = (string) $this->getJson(self::VERSION)->json('version');

        DailyRosterSnapshotFactory::publish(self::TODAY, [
            'Station 1 / Engine 1' => [['employee_id' => '50001', 'name' => 'NEWLY, PUBLISHED']],
        ], ['generated_at' => CarbonImmutable::parse(self::NOW)]);

        $this->get(self::VERSION, ['If-None-Match' => '"'.$version.'"'])->assertOk();
        $this->get(self::FRAGMENT)->assertSee('NEWLY, PUBLISHED')->assertDontSee('ALPHA, MEMBER');
    }

    public function test_cell_text_is_escaped(): void
    {
        $this->publishRoster(['Station 1 / <b>Engine 1</b>' => [[
            'employee_id' => '<i>50001</i>',
            'name' => '<script>alert(1)</script>',
            'code' => '<img src=x onerror=alert(2)>',
            'availability' => RosterAssignment::AVAILABILITY_UNKNOWN,
            'rank' => '<u>Firefighter</u>',
            'certifications' => ['<svg onload=alert(3)>'],
        ]]]);
        $this->actingAsCanonicalFixture();

        $this->get(self::PAGE)->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertDontSee('<img src=x', false)
            ->assertDontSee('<svg onload', false)
            ->assertDontSee('<b>Engine 1</b>', false)
            ->assertDontSee('<u>Firefighter</u>', false)
            ->assertDontSee('<i>50001</i>', false)
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
    }

    public function test_only_published_dates_are_accepted_and_previous_and_next_link_them(): void
    {
        $this->publishRoster();
        DailyRosterSnapshotFactory::publish('2026-10-29', ['Station 2 / Engine 2' => [['employee_id' => '60001', 'name' => 'YESTERDAY, MEMBER']]]);
        DailyRosterSnapshotFactory::publish('2026-10-28', ['Station 3 / Engine 3' => [['employee_id' => '60002', 'name' => 'OLDER, MEMBER']]]);
        $this->actingAsCanonicalFixture();

        foreach (['2020-01-01', 'yesterday', "2026-10-29'--", '2026-10-29T00:00', ''] as $date) {
            $this->get(self::PAGE.'?date='.urlencode($date))->assertOk()
                ->assertSee('ALPHA, MEMBER')->assertDontSee('YESTERDAY, MEMBER');
        }
        $this->get(self::PAGE.'?date[]=2026-10-29')->assertOk()->assertSee('ALPHA, MEMBER');

        $current = $this->get(self::PAGE)->assertOk();
        $current->assertSee('Previous: Oct 29')->assertDontSee('Next:');

        $dated = $this->get(self::PAGE.'?date=2026-10-29')->assertOk();
        $dated->assertSee('YESTERDAY, MEMBER')
            ->assertDontSee('ALPHA, MEMBER')
            ->assertSee('Thursday, October 29')
            ->assertSee('Earlier roster')
            ->assertDontSee('Data current')
            ->assertSee('Previous: Oct 28')
            ->assertSee('Next: Oct 30')
            ->assertSee('You are viewing the roster for');

        $this->get(self::FRAGMENT.'?date=2026-10-29')->assertSee('YESTERDAY, MEMBER');
    }

    public function test_with_no_roster_for_today_the_last_good_one_shows_with_an_honest_warning(): void
    {
        DailyRosterSnapshotFactory::publish('2026-10-29', ['Station 2 / Engine 2' => [['employee_id' => '60001', 'name' => 'YESTERDAY, MEMBER']]]);

        $this->travelTo(CarbonImmutable::parse('2026-10-30 08:30:00', 'America/New_York'));
        $this->actingAsCanonicalFixture();
        $this->get(self::PAGE)->assertOk()
            ->assertSee('YESTERDAY, MEMBER')
            ->assertSee('Thursday, October 29')
            ->assertSee('Awaiting today&#039;s roster', false)
            ->assertSee('This is the last published roster, from Thursday, October 29.')
            ->assertDontSee('Data current');

        $this->logoutCanonicalSession();
        $this->travelTo(CarbonImmutable::parse('2026-10-30 11:00:00', 'America/New_York'));
        $this->actingAsCanonicalFixture('E01-TEST-LATER');
        $this->get(self::PAGE)->assertOk()
            ->assertSee('YESTERDAY, MEMBER')
            ->assertSee('Update delayed')
            ->assertSee('has not arrived and is late');
    }

    public function test_the_last_good_roster_keeps_its_last_updated_time_beside_the_honest_warning(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-29 09:14:25', 'America/New_York'));
        DailyRosterSnapshotFactory::publish('2026-10-29', ['Station 2 / Engine 2' => [['employee_id' => '60001', 'name' => 'YESTERDAY, MEMBER']]]);
        $this->travelTo(CarbonImmutable::parse('2026-10-30 11:00:00', 'America/New_York'));
        $this->actingAsCanonicalFixture();

        foreach ([self::PAGE, self::FRAGMENT] as $url) {
            $this->get($url)->assertOk()
                ->assertSeeInOrder(['Last updated', 'Oct 29, 9:14 AM EDT', 'Update delayed', 'has not arrived and is late', 'YESTERDAY, MEMBER']);
        }
    }

    public function test_with_nothing_published_the_page_is_an_empty_state_with_a_way_home(): void
    {
        $this->actingAsCanonicalFixture();

        $this->get(self::PAGE)->assertOk()
            ->assertSee('data-roster-empty', false)
            ->assertSee('No roster has been published yet')
            ->assertSee('Hub Home')
            ->assertSee('Awaiting today&#039;s roster', false)
            ->assertDontSee('data-roster-section', false);

        self::assertSame('none', $this->getJson(self::VERSION)->assertOk()->json('state'));
    }

    public function test_a_held_export_shows_needs_review_while_the_last_accepted_roster_stays_up(): void
    {
        $this->publishRoster();
        RosterImport::query()->create([
            'correlation_id' => (string) Str::uuid(),
            'state' => RosterImport::STATE_NEEDS_REVIEW,
            'state_changed_at' => now(),
            'message_id_sha256' => hash('sha256', 'held'),
            'sender' => 'sender@example.test',
            'received_at' => now(),
            'duty_date' => self::TODAY,
        ]);
        $this->actingAsCanonicalFixture();

        $this->get(self::PAGE)->assertOk()
            ->assertSee('Needs review')
            ->assertSee('A newer TeleStaff export is being reviewed.')
            ->assertSee('ALPHA, MEMBER')
            ->assertDontSee('Data current');
    }

    public function test_sections_keep_source_order_and_every_row_shows(): void
    {
        $this->publishRoster([
            'Station 4 / Rescue 4' => [['employee_id' => '70001', 'name' => 'ZULU, FIRST']],
            'Station 1 / Engine 1' => [['employee_id' => '70002', 'name' => 'ALPHA, SECOND'], ['employee_id' => '70003', 'name' => 'BRAVO, SECOND']],
            'Division Chief / Division Chief 300' => [['employee_id' => '70004', 'name' => 'CHARLIE, THIRD', 'rank' => 'Division Chief']],
        ]);
        $this->actingAsCanonicalFixture();

        $response = $this->get(self::PAGE)->assertOk();
        $response->assertSeeInOrder(['Station 4 / Rescue 4', 'Station 1 / Engine 1', 'Division Chief / Division Chief 300']);
        $response->assertSeeInOrder(['ZULU, FIRST', 'ALPHA, SECOND', 'BRAVO, SECOND', 'CHARLIE, THIRD']);
        self::assertSame(4, substr_count((string) $response->getContent(), 'data-roster-row'));
        $response->assertSee('2 entries')->assertSee('1 entry')->assertSee('4 entries in 3 sections');
    }

    public function test_a_repeated_person_is_not_deduplicated_and_a_continuation_row_is_grouped_with_its_seat(): void
    {
        $this->publishRoster(['Station 1 / Ladder 1' => [
            ['employee_id' => '80001', 'name' => 'REPEAT, MEMBER', 'code' => '+OT', 'category' => 'Overtime', 'rank' => 'Captain', 'seat' => 0, 'through' => '20:00', 'hours' => 12.0],
            ['employee_id' => '80001', 'name' => 'REPEAT, MEMBER', 'code' => '+OT', 'category' => 'Overtime', 'rank' => 'Captain', 'seat' => 0, 'continuation' => true, 'from' => '20:00', 'through' => '08:00', 'hours' => 12.0],
            ['employee_id' => '80002', 'name' => 'NEXT, SEAT', 'rank' => 'Firefighter', 'seat' => 1],
        ]]);
        $this->actingAsCanonicalFixture();

        $html = (string) $this->get(self::PAGE)->assertOk()->getContent();

        self::assertSame(2, substr_count($html, 'data-search="repeat, member 80001"'));
        self::assertSame(2, substr_count($html, 'data-roster-seat'));
        self::assertStringContainsString('12.0', $html);
        self::assertStringContainsString('roster-rank-continued', $html);
        self::assertStringContainsString(', same seat', $html);
    }

    public function test_a_continuation_row_takes_its_rank_from_the_seat(): void
    {
        $this->publishRoster(['Station 1 / Ladder 1' => [
            ['employee_id' => '80001', 'name' => 'FIRST, MEMBER', 'rank' => 'Combat Captain', 'seat' => 0, 'through' => '20:00', 'hours' => 12.0],
            ['employee_id' => '80003', 'name' => 'SECOND, MEMBER', 'rank' => 'Combat Captain', 'seat' => 0, 'continuation' => true, 'from' => '20:00', 'through' => '08:00', 'hours' => 12.0],
        ]]);
        // What RosterPublisher stores: the rank is on the seat, not on a continuation row.
        RosterAssignment::query()->where('is_continuation', true)->update(['rank_label' => null]);
        $this->actingAsCanonicalFixture();

        $html = (string) $this->get(self::PAGE)->assertOk()->getContent();

        self::assertStringContainsString('<span class="roster-rank-continued">Combat Captain<span class="sr-only">, same seat</span></span>', $html);
        self::assertSame(2, substr_count($html, 'roster-rank-continued'), 'the rank cell and the phone name line, and only on the continuation row');
    }

    public function test_vacancies_read_as_vacancies_and_keep_the_source_marker(): void
    {
        $this->publishRoster(['Station 1 / Engine 1' => [
            ['kind' => RosterAssignment::KIND_VACANCY_OPEN, 'name' => '?,?', 'code' => null],
            ['kind' => RosterAssignment::KIND_VACANCY_SUPPRESSED, 'name' => '-,-', 'code' => null],
        ]]);
        $this->actingAsCanonicalFixture();

        $this->get(self::PAGE)->assertOk()
            ->assertSee('Open seat')
            ->assertSee('Suppressed vacancy')
            ->assertSee('TeleStaff shows ?,?')
            ->assertSee('TeleStaff shows -,-')
            ->assertSee('roster-row--vacant', false)
            ->assertSee('value="Open seat"', false)
            ->assertSee('value="Suppressed vacancy"', false);
    }

    public function test_status_pills_show_the_printed_code_and_a_day_keeps_the_raw_code_available(): void
    {
        $this->publishRoster(['Station 1 / Engine 1' => [
            ['employee_id' => '90001', 'name' => 'ADAY, MEMBER', 'code' => '.ADONIS DAY', 'base_code' => 'ADONIS DAY', 'label' => 'A-Day', 'category' => 'A-Day', 'availability' => RosterAssignment::AVAILABILITY_NOT_WORKING],
            ['employee_id' => '90002', 'name' => 'VACATION, MEMBER', 'code' => '.VACA', 'base_code' => 'VACA', 'label' => 'VACA', 'category' => 'Vacation', 'availability' => RosterAssignment::AVAILABILITY_NOT_WORKING],
            ['employee_id' => '90003', 'name' => 'MYSTERY, MEMBER', 'code' => 'GWA', 'availability' => RosterAssignment::AVAILABILITY_UNKNOWN, 'category' => 'Other'],
            ['employee_id' => '90004', 'name' => 'LONG, CERTS', 'code' => 'SP', 'certifications' => ['COWD', 'DE', 'FBOQ']],
        ]]);
        $this->actingAsCanonicalFixture();

        $response = $this->get(self::PAGE)->assertOk();
        $response->assertSee('title=".ADONIS DAY"', false)
            ->assertSee('source code .ADONIS DAY')
            ->assertSee('.VACA')
            ->assertSee('roster-pill--aday', false)
            ->assertSee('roster-pill--vaca', false)
            ->assertSee('roster-pill--unknown', false)
            ->assertSee('Status not recognized')
            ->assertSee('COWD/')
            ->assertSee('FBOQ');
        // The legend lists what is on this roster, nothing else.
        $response->assertDontSee('roster-pill--milnp', false);
    }

    public function test_the_home_tile_follows_access(): void
    {
        config(['daily_roster.mode' => 'publish']);
        $this->actingAsCanonicalFixture();
        $this->get('/')->assertOk()
            ->assertSee('Daily Roster')
            ->assertSee('Today&#039;s shift roster from TeleStaff', false)
            ->assertSee('href="'.route('daily-roster.show').'"', false);

        $this->logoutCanonicalSession();
        $this->actingAsCanonicalUser(User::factory()->create());
        $this->get('/')->assertOk()
            ->assertDontSee('Daily Roster')
            ->assertDontSee('href="'.route('daily-roster.show').'"', false);
    }

    /** @param array<string, list<array<string, mixed>>>|null $sections */
    private function publishRoster(?array $sections = null): void
    {
        DailyRosterSnapshotFactory::publish(self::TODAY, $sections ?? [
            'Station 1 / Engine 1' => [['employee_id' => '40001', 'name' => 'ALPHA, MEMBER', 'code' => 'SP', 'category' => 'Scheduled', 'certifications' => ['DE', 'HMA']]],
        ], ['shift' => 'B', 'generated_at' => CarbonImmutable::parse('2026-10-30 09:14:00', 'America/New_York')]);
    }

    private function assertNoStore(\Illuminate\Testing\TestResponse $response): void
    {
        $header = (string) $response->headers->get('Cache-Control');
        self::assertStringContainsString('no-store', $header);
        self::assertStringContainsString('private', $header);
    }
}
