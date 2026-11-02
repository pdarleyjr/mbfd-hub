<?php

declare(strict_types=1);

namespace Tests\Feature\Home;

use App\Enums\AccountStatus;
use App\Models\AuthenticationSession;
use App\Models\User;
use App\Services\Home\HomeActivity;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Home page visits: what counts as a visit, the rolling past-24-hours and all-time numbers, their place on the Admin
 * Overview for administrators only, and the promise that none of it can slow down or break Home.
 */
final class HomeActivityTest extends TestCase
{
    use RefreshDatabase;

    private const TABLE = 'home_page_view_hours';

    /** 09:35 in New York (standard time has just begun), so "Today" and "Yesterday" are not midnight edge cases. */
    private const NOW = '2026-11-01 14:35:00';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(CarbonImmutable::parse(self::NOW, 'UTC'));
    }

    // ---- What counts as a visit ----------------------------------------------------------------

    public function test_a_home_render_counts_in_the_current_hour_and_every_reload_counts(): void
    {
        $this->actingAsCanonicalFixture();

        $this->get('/')->assertOk();
        $this->assertSame(1, $this->viewsIn('2026-11-01 14:00:00'));

        $this->get('/')->assertOk();
        $this->assertSame(2, $this->viewsIn('2026-11-01 14:00:00'));
        $this->assertSame(1, DB::table(self::TABLE)->count(), 'one row per hour, never one per visit');

        $this->travel(30)->minutes();
        $this->get('/')->assertOk();
        $this->assertSame(1, $this->viewsIn('2026-11-01 15:00:00'));
    }

    public function test_a_guest_is_redirected_and_counts_nothing(): void
    {
        $this->get('/')->assertRedirect('/login');

        $this->assertSame(0, DB::table(self::TABLE)->count());
    }

    public function test_the_admin_panel_is_not_a_home_visit(): void
    {
        $this->admin();

        $this->get('/admin');

        $this->assertSame(0, DB::table(self::TABLE)->count());
    }

    public function test_prefetches_and_head_probes_are_not_visits(): void
    {
        $this->actingAsCanonicalFixture();

        $this->withHeader('Sec-Purpose', 'prefetch;prerender')->get('/')->assertOk();
        $this->withHeader('Purpose', 'prefetch')->get('/')->assertOk();
        $this->call('HEAD', '/');

        $this->assertSame(0, DB::table(self::TABLE)->count());
    }

    // ---- The numbers ---------------------------------------------------------------------------

    public function test_the_window_rolls_over_hours_and_includes_imported_history(): void
    {
        DB::table(self::TABLE)->insert([
            ['hour' => '2026-09-01 00:00:00', 'views' => 0, 'imported_views' => 0],   // no count: not the start date
            ['hour' => '2026-10-02 02:00:00', 'views' => 0, 'imported_views' => 40],  // earliest record
            ['hour' => '2026-10-31 13:00:00', 'views' => 5, 'imported_views' => 0],   // one hour too old for the window
            ['hour' => '2026-10-31 14:00:00', 'views' => 3, 'imported_views' => 0],   // first hour inside it
            ['hour' => '2026-11-01 10:00:00', 'views' => 2, 'imported_views' => 7],
        ]);

        $summary = app(HomeActivity::class)->summary();

        $this->assertSame(12, $summary['past_24_hours']);
        $this->assertSame(57, $summary['total']);
        // 02:00 UTC on Oct 2 is still Oct 1 in New York.
        $this->assertSame('Oct 1, 2026', $summary['since']);
    }

    public function test_an_old_hour_falls_out_of_the_window_as_time_passes(): void
    {
        DB::table(self::TABLE)->insert(['hour' => '2026-11-01 14:00:00', 'views' => 6, 'imported_views' => 0]);
        $this->assertSame(6, app(HomeActivity::class)->summary()['past_24_hours']);

        $this->travel(24)->hours();
        $this->app['cache']->flush();
        $this->assertSame(6, app(HomeActivity::class)->summary()['past_24_hours'], 'still the first hour of the window');

        $this->travel(1)->hours();
        $this->app['cache']->flush();
        $summary = app(HomeActivity::class)->summary();
        $this->assertSame(0, $summary['past_24_hours']);
        $this->assertSame(6, $summary['total']);
    }

    public function test_a_visit_to_home_raises_the_numbers_an_administrator_reads(): void
    {
        $this->admin();

        $this->getJson('/home/activity')->assertOk()->assertExactJson(['past_24_hours' => 0, 'total' => 0, 'since' => null, 'signed_in' => 0]);
        $this->get('/')->assertOk();
        $this->getJson('/home/activity')->assertOk()->assertJson(['past_24_hours' => 1, 'total' => 1, 'since' => 'Nov 1, 2026']);
        $this->get('/')->assertOk();
        $this->getJson('/home/activity')->assertOk()->assertJson(['past_24_hours' => 2, 'total' => 2]);
    }

    public function test_the_counters_are_json_never_stored_and_for_administrators_only(): void
    {
        $this->getJson('/home/activity')->assertUnauthorized();

        $this->actingAsCanonicalFixture();
        $this->getJson('/home/activity')->assertForbidden();

        $this->admin();
        $response = $this->getJson('/home/activity', ['X-Hub-Background-Refresh' => '1'])->assertOk();
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_the_counters_say_how_many_members_signed_in(): void
    {
        $admin = $this->admin();
        $admin->forceFill(['last_login_at' => CarbonImmutable::now()])->save();
        $this->member('Bob Baker', null, CarbonImmutable::now()->subHour());
        $this->member('Dan Day', null, CarbonImmutable::now()->subHours(25));

        $this->getJson('/home/activity')->assertOk()->assertJson(['signed_in' => 2]);
    }

    // ---- Where it appears ------------------------------------------------------------------------

    public function test_home_no_longer_shows_any_of_it_to_anyone(): void
    {
        $this->actingAsCanonicalFixture();
        $this->get('/')->assertOk()->assertDontSee('data-home-activity', false)->assertDontSee('Past 24 hours');

        $this->admin();
        $this->get('/')->assertOk()->assertDontSee('data-home-activity', false)->assertDontSee('signed in');
    }

    public function test_the_admin_overview_has_it_collapsed_beside_the_title_and_reads_nothing_until_opened(): void
    {
        $this->admin();
        DB::table(self::TABLE)->insert(['hour' => '2026-11-01 10:00:00', 'views' => 7, 'imported_views' => 0]);

        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $html = $this->get('/admin')->assertOk()
            ->assertSee('data-home-activity', false)
            ->assertSee('Home page visits')
            ->assertSee('data-members-url="/home/activity/members"', false)
            ->assertSee('No one has signed in during the past 24 hours.')
            ->getContent();

        // Livewire leaves a marker comment around an @if.
        $this->assertMatchesRegularExpression('#<h1 class="mbfd-dash-header__title">Admin Overview</h1>\s*(?:<!--\[if BLOCK\]><!\[endif\]-->)?\s*<details\s#', $html, 'right beside the title');
        $this->assertDoesNotMatchRegularExpression('#<details[^>]*data-home-activity[^>]*\sopen#', $html, 'starts collapsed');
        $this->assertSame([], array_values(array_filter($queries, static fn (string $sql): bool => str_contains($sql, self::TABLE))), 'the page itself reads no counts');
    }

    public function test_the_wall_display_does_not_show_it(): void
    {
        $this->admin();

        $this->get('/admin?display=1')->assertDontSee('data-home-activity', false);
    }

    public function test_the_page_opening_it_needs_no_table_so_a_missing_table_cannot_break_the_admin_overview(): void
    {
        $this->admin();
        Schema::drop(self::TABLE);

        $this->get('/admin')->assertOk()->assertSee('data-home-activity', false);
    }

    // ---- Members who signed in -----------------------------------------------------------------

    public function test_the_member_list_has_only_the_past_24_hours_most_recent_first_and_no_private_fields(): void
    {
        $admin = $this->admin();
        $admin->forceFill(['last_login_at' => CarbonImmutable::now()->subMinutes(10)])->save();
        $this->member('Alice Anders', 'Alicia A.', CarbonImmutable::now()->subHour());
        $this->member('Bob Baker', null, CarbonImmutable::now()->subHours(5));
        $this->member('Cara Cole', null, CarbonImmutable::now()->subHours(23));
        $this->member('Dan Day', null, CarbonImmutable::now()->subHours(25));
        $this->member('Eve Eden', null, null);

        $response = $this->getJson('/home/activity/members')->assertOk();

        $this->assertSame([
            'count' => 4,
            'members' => [
                ['name' => $admin->name, 'at' => 'Today 9:25 AM'],
                ['name' => 'Alicia A.', 'at' => 'Today 8:35 AM'],
                ['name' => 'Bob Baker', 'at' => 'Today 4:35 AM'],
                ['name' => 'Cara Cole', 'at' => 'Yesterday 11:35 AM'],
            ],
        ], $response->json());
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringNotContainsString('@', $response->getContent(), 'no email addresses');
    }

    public function test_the_member_list_is_for_administrators_only(): void
    {
        $this->getJson('/home/activity/members')->assertUnauthorized();

        $this->actingAsCanonicalFixture();
        $this->getJson('/home/activity/members')->assertForbidden();
    }

    // ---- It can never break Home -----------------------------------------------------------------

    public function test_home_still_renders_when_the_table_is_missing_and_the_failure_is_logged(): void
    {
        $this->admin();
        Schema::drop(self::TABLE);
        $logged = [];
        $this->app['events']->listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
            $logged[] = [$event->level, $event->message];
        });

        $this->get('/')->assertOk()->assertSee('Department Updates');
        $this->getJson('/home/activity')->assertStatus(503);

        $this->assertContains(['warning', 'Home activity could not be read or written.'], $logged);
    }

    // ---- Sessions ----------------------------------------------------------------------------------

    public function test_a_background_refresh_does_not_keep_the_session_alive_but_a_member_action_does(): void
    {
        $member = $this->admin();
        $before = $this->activityOf($member);

        $this->getJson('/home/activity', ['X-Hub-Background-Refresh' => '1'])->assertOk();
        $this->assertEquals($before, $this->activityOf($member), 'a timer on an open page must not extend the idle clock');

        $this->travel(5)->minutes();
        $this->getJson('/home/activity', ['X-Hub-Background-Refresh' => '1'])->assertOk();
        $this->assertEquals($before, $this->activityOf($member));

        $this->getJson('/home/activity')->assertOk();
        $this->assertNotEquals($before, $this->activityOf($member), 'the same request without the header is real activity');
    }

    public function test_the_feeds_leave_a_pending_flash_message_for_the_page_that_shows_it(): void
    {
        $this->admin();
        $this->withSession(['notice' => 'Saved.', '_flash' => ['old' => ['notice'], 'new' => []]]);

        $this->getJson('/home/activity', ['X-Hub-Background-Refresh' => '1'])->assertOk();

        $store = $this->app['session']->driver();
        $this->assertSame('Saved.', $store->get('notice'));
        $this->assertContains('notice', $store->get('_flash.old'));
    }

    // ---- Importing history -----------------------------------------------------------------------

    public function test_the_import_sets_imported_views_without_touching_live_counts_and_is_safe_to_repeat(): void
    {
        DB::table(self::TABLE)->insert(['hour' => '2026-10-02 13:00:00', 'views' => 4, 'imported_views' => 0]);
        $file = $this->historyFile([
            ['hour' => '2026-10-02T13:00:00Z', 'views' => 12],
            ['hour' => '2026-10-02T14:00:00Z', 'views' => 8],
            ['hour' => '2026-10-02T15:00:00-04:00', 'views' => 0],
        ]);

        foreach ([1, 2] as $run) {
            $this->artisan('home-activity:import-history', ['path' => $file])
                ->expectsOutputToContain('Wrote 3 hour rows.')
                ->expectsOutputToContain('Earliest hour with views: Oct 2, 2026 9:00 AM EDT')
                ->assertSuccessful();
        }

        $this->assertSame(3, DB::table(self::TABLE)->count());
        $this->assertEquals(['views' => 4, 'imported_views' => 12], (array) DB::table(self::TABLE)->where('hour', '2026-10-02 13:00:00')->first(['views', 'imported_views']));
        $this->assertEquals(['views' => 0, 'imported_views' => 8], (array) DB::table(self::TABLE)->where('hour', '2026-10-02 14:00:00')->first(['views', 'imported_views']));
        $this->assertSame(0, (int) DB::table(self::TABLE)->where('hour', '2026-10-02 19:00:00')->value('imported_views'));

        // A corrected file replaces the earlier numbers instead of adding to them.
        $this->artisan('home-activity:import-history', ['path' => $this->historyFile([['hour' => '2026-10-02T13:00:00Z', 'views' => 10]])])->assertSuccessful();
        $this->assertSame(10, (int) DB::table(self::TABLE)->where('hour', '2026-10-02 13:00:00')->value('imported_views'));
        $this->assertSame(4, $this->viewsIn('2026-10-02 13:00:00'));
    }

    public function test_a_dry_run_writes_nothing(): void
    {
        $this->artisan('home-activity:import-history', ['path' => $this->historyFile([['hour' => '2026-10-02T13:00:00Z', 'views' => 12]]), '--dry-run' => true])
            ->expectsOutputToContain('Would write 1 hour rows.')
            ->assertSuccessful();

        $this->assertSame(0, DB::table(self::TABLE)->count());
    }

    /** @return array<string, array{string}> */
    public static function badHistory(): array
    {
        return [
            'not json' => ['{'],
            'not a list' => ['{"hour": "2026-10-02T13:00:00Z", "views": 1}'],
            'empty list' => ['[]'],
            'missing hour' => ['[{"views": 1}]'],
            'not a date' => ['[{"hour": "banana", "views": 1}]'],
            'off the hour' => ['[{"hour": "2026-10-02T13:30:00Z", "views": 1}]'],
            'negative views' => ['[{"hour": "2026-10-02T13:00:00Z", "views": -1}]'],
            'fractional views' => ['[{"hour": "2026-10-02T13:00:00Z", "views": 1.5}]'],
            'views as text' => ['[{"hour": "2026-10-02T13:00:00Z", "views": "12"}]'],
            'the same hour twice' => ['[{"hour": "2026-10-02T13:00:00Z", "views": 1}, {"hour": "2026-10-02T09:00:00-04:00", "views": 2}]'],
            'in the future' => ['[{"hour": "2026-11-02T00:00:00Z", "views": 1}]'],
            'one bad row among good ones' => ['[{"hour": "2026-10-02T13:00:00Z", "views": 1}, {"hour": "2026-10-02T14:00:00Z"}]'],
        ];
    }

    #[DataProvider('badHistory')]
    public function test_bad_input_is_refused_and_nothing_is_written(string $json): void
    {
        $file = tempnam(sys_get_temp_dir(), 'hist');
        file_put_contents($file, $json);

        $this->artisan('home-activity:import-history', ['path' => $file])->assertFailed();

        $this->assertSame(0, DB::table(self::TABLE)->count());
        @unlink($file);
    }

    public function test_a_missing_file_is_refused(): void
    {
        $this->artisan('home-activity:import-history', ['path' => sys_get_temp_dir().'/no-such-home-history.json'])->assertFailed();
    }

    // ---- Helpers -----------------------------------------------------------------------------------

    private function admin(): User
    {
        $user = $this->actingAsCanonicalFixture('E01-TEST-ADMIN', 'Chief Admin');
        $user->givePermissionTo(Permission::findOrCreate('admin.access', 'web'));

        return $user;
    }

    private function member(string $name, ?string $displayName, ?CarbonImmutable $lastLogin): User
    {
        return User::factory()->create([
            'name' => $name,
            'display_name' => $displayName,
            'account_status' => AccountStatus::Active,
            'last_login_at' => $lastLogin,
        ]);
    }

    private function viewsIn(string $hour): int
    {
        return (int) DB::table(self::TABLE)->where('hour', $hour)->value('views');
    }

    /** @return array{0: mixed, 1: mixed} */
    private function activityOf(User $user): array
    {
        $session = AuthenticationSession::query()->where('user_id', $user->id)->firstOrFail();

        return [$session->last_activity_at?->toIso8601String(), $session->idle_expires_at?->toIso8601String()];
    }

    /** @param list<array<string, mixed>> $rows */
    private function historyFile(array $rows): string
    {
        $file = tempnam(sys_get_temp_dir(), 'hist');
        file_put_contents($file, json_encode($rows, JSON_THROW_ON_ERROR));

        return $file;
    }
}
