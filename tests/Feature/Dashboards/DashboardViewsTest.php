<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboards;

use App\Filament\Pages\Dashboard;
use App\Filament\Pages\LogisticsBoard;
use App\Filament\Pages\StationOperationsDashboard;
use App\Filament\Pages\VehicleCheckoutDashboard;
use App\Filament\Widgets\EmployeeMessagesWidget;
use App\Filament\Widgets\EquipmentChangeRequestsWidget;
use App\Filament\Widgets\StationOperationsHubWidget;
use App\Livewire\Dashboards\DashboardBoard;
use App\Models\User;
use App\Services\Security\ApplicationAccessService;
use App\Support\Dashboards\DashboardView;
use App\Support\Dashboards\IssueSourceRegistry;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Dashboards\FakeIssueSource;
use Tests\Support\Dashboards\FixtureMessageSource;
use Tests\Support\Dashboards\FixtureStationSource;
use Tests\Support\Dashboards\FixtureVehicleSource;
use Tests\TestCase;

final class DashboardViewsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-30 10:42:18', 'America/New_York'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->admin = User::factory()->create(['account_status' => 'active', 'name' => 'Pat Example']);
        $this->admin->assignRole(Role::findOrCreate('super_admin', 'web'));
        $this->actingAsCanonicalUser($this->admin);
        $this->withoutVite();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function useFixtures(): void
    {
        $this->app->instance(IssueSourceRegistry::class, new IssueSourceRegistry([
            new FixtureVehicleSource, new FixtureStationSource, new FixtureMessageSource,
        ]));
    }

    public function test_the_three_dashboards_have_stable_direct_urls_and_routes(): void
    {
        $this->assertSame('/admin', parse_url(Dashboard::getUrl(panel: 'admin'), PHP_URL_PATH));
        $this->assertSame('/admin/dashboards/vehicle-checkout', parse_url(VehicleCheckoutDashboard::getUrl(panel: 'admin'), PHP_URL_PATH));
        $this->assertSame('/admin/dashboards/station-operations', parse_url(StationOperationsDashboard::getUrl(panel: 'admin'), PHP_URL_PATH));
        $this->assertSame('/admin/logistics-board', parse_url(LogisticsBoard::getUrl(panel: 'admin'), PHP_URL_PATH));
        $this->assertSame(Dashboard::class, app('router')->getRoutes()->getByName('filament.admin.pages.dashboard')->getActionName());

        foreach (['/admin', '/admin/dashboards/vehicle-checkout', '/admin/dashboards/station-operations', '/admin/logistics-board'] as $path) {
            $this->get($path)->assertOk();
        }
    }

    public function test_pager_links_and_position_labels_on_each_view(): void
    {
        $overview = $this->get('/admin')->assertOk()->getContent();
        $this->assertStringContainsString('Admin Overview</span>', $overview);
        $this->assertStringContainsString('1 of 3', $overview);
        $this->assertMatchesRegularExpression('#<a role="link" aria-disabled="true"[^>]*aria-label="No previous dashboard"#', $overview);
        $this->assertMatchesRegularExpression('#href="[^"]*/admin/dashboards/vehicle-checkout"[^>]*rel="next"[^>]*aria-label="Next dashboard: Vehicle Checkout \(2 of 3\)"#', $overview);

        $vehicle = $this->get('/admin/dashboards/vehicle-checkout')->assertOk()->getContent();
        $this->assertStringContainsString('2 of 3', $vehicle);
        $this->assertMatchesRegularExpression('#href="[^"]*/admin"[^>]*rel="prev"[^>]*aria-label="Previous dashboard: Admin Overview \(1 of 3\)"#', $vehicle);
        $this->assertMatchesRegularExpression('#href="[^"]*/admin/dashboards/station-operations"[^>]*rel="next"#', $vehicle);

        $station = $this->get('/admin/dashboards/station-operations')->assertOk()->getContent();
        $this->assertStringContainsString('3 of 3', $station);
        $this->assertMatchesRegularExpression('#href="[^"]*/admin/dashboards/vehicle-checkout"[^>]*rel="prev"#', $station);
        $this->assertMatchesRegularExpression('#<a role="link" aria-disabled="true"[^>]*aria-label="No next dashboard"#', $station);
        $this->assertStringContainsString('aria-label="Dashboards"', $station);
    }

    public function test_display_mode_still_renders_only_the_station_operations_board_and_no_dashboard_ui(): void
    {
        $html = $this->get('/admin?display=1')->assertOk()->getContent();

        $this->assertStringContainsString('mbfd-command-display', $html);
        $this->assertStringContainsString('Exit Display Mode', $html);
        $this->assertStringNotContainsString('emsg-widget-title', $html);
        $this->assertStringNotContainsString('data-issue-panel', $html);
        $this->assertStringNotContainsString('data-dashboard-pager', $html);
        $this->assertStringNotContainsString('mbfd-dash-fit', $html);
        $this->assertStringNotContainsString('dashboards.issue-action-panel', $html);

        $this->app['request']->merge(['display' => '1']);
        $this->assertSame([StationOperationsHubWidget::class], (new Dashboard)->getWidgets());
    }

    public function test_display_mode_never_lists_fixture_rows_even_when_sources_exist(): void
    {
        $this->useFixtures();

        $html = $this->get('/admin?display=1')->assertOk()->getContent();
        $this->assertStringNotContainsString('Suction unit not holding a charge', $html);
        $this->assertStringNotContainsString('data-issue-ref="', $html);
    }

    public function test_logistics_board_renders_the_three_widgets_the_old_dashboard_had_with_display_mode_action(): void
    {
        $this->assertSame([StationOperationsHubWidget::class, EmployeeMessagesWidget::class, EquipmentChangeRequestsWidget::class], (new LogisticsBoard)->getWidgets());

        $html = $this->get('/admin/logistics-board')->assertOk()->getContent();
        $this->assertStringContainsString('Logistics Board', $html);
        $this->assertStringContainsString('Today’s Truck Checkouts', $html);
        $this->assertStringContainsString('Display Mode', $html);
        $this->assertStringContainsString('/admin?display=1', html_entity_decode($html));
    }

    public function test_overview_normal_mode_is_the_admin_overview_with_fit_layout_class(): void
    {
        $html = $this->get('/admin')->assertOk()->getContent();

        $this->assertStringContainsString('mbfd-dash-fit', $html);
        $this->assertStringContainsString('<h1 class="mbfd-dash-header__title">Admin Overview</h1>', $html);
        $this->assertStringContainsString('Friday, October 30, 2026', $html);
        $this->assertStringContainsString('Good morning, Pat', $html);
        $this->assertStringContainsString('data-issue-panel', $html);
        $this->assertStringNotContainsString('Today’s Truck Checkouts', $html);
    }

    public function test_operational_day_banner_uses_the_eight_am_window(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-31 07:59:00', 'America/New_York'));
        $this->actingAsCanonicalUser($this->admin);
        $html = $this->get('/admin')->assertOk()->getContent();
        $this->assertStringContainsString('Fri, Oct 30 · 8:00 AM', $html);
        $this->assertStringContainsString('Sat, Oct 31 · 8:00 AM', $html);
        $this->assertStringContainsString('America/New_York · records at 08:00 begin the new display day', $html);

        // DST ends 2026-11-01 at 02:00: the window still runs 08:00 to 08:00 local.
        Carbon::setTestNow(Carbon::parse('2026-11-01 09:00:00', 'America/New_York'));
        $this->actingAsCanonicalUser($this->admin);
        $html = $this->get('/admin')->assertOk()->getContent();
        $this->assertStringContainsString('datetime="2026-11-01T08:00:00-05:00"', $html);
        $this->assertStringContainsString('datetime="2026-11-02T08:00:00-05:00"', $html);
    }

    public function test_empty_registry_renders_honest_empty_states_and_zero_counts(): void
    {
        $this->app->instance(IssueSourceRegistry::class, new IssueSourceRegistry([]));

        $this->get('/admin')->assertOk()
            ->assertSee('No open vehicle issues for this operational day.')
            ->assertSee('No open station requests for this operational day.')
            ->assertSee('No open Support Services messages for this operational day.')
            ->assertDontSee('data-issue-ref="', false);

        $this->get('/admin/dashboards/vehicle-checkout')->assertOk()->assertSee('No open vehicle issues for this operational day.');
        $this->get('/admin/dashboards/station-operations')->assertOk()->assertSee('No open station requests for this operational day.');
    }

    public function test_fixture_rows_counts_and_chips_render_on_each_view(): void
    {
        $this->useFixtures();

        $overview = Livewire::test(DashboardBoard::class, ['dashboard' => 'overview'])->html();
        $this->assertStringContainsString('Suction unit not holding a charge', $overview);
        $this->assertStringContainsString('Bay door opener not closing fully', $overview);
        $this->assertStringContainsString('Boot size exchange', $overview);
        // Open items: vehicle 7 + station 5 + messages 2 (everything not completed); exact, no trend arrows.
        $this->assertMatchesRegularExpression('#data-stat="open".*?mbfd-dash-stat__value">14<#s', $overview);
        $this->assertMatchesRegularExpression('#data-stat="vehicle_open".*?mbfd-dash-stat__value">7<#s', $overview);
        $this->assertMatchesRegularExpression('#data-stat="station_open".*?mbfd-dash-stat__value">5<#s', $overview);
        $this->assertMatchesRegularExpression('#data-stat="messages_awaiting".*?mbfd-dash-stat__value">1<#s', $overview);
        $this->assertStringNotContainsString('↑', $overview);

        $vehicle = Livewire::test(DashboardBoard::class, ['dashboard' => 'vehicle-checkout']);
        $html = $vehicle->html();
        $this->assertMatchesRegularExpression('#aria-pressed="true".*?All</span>\s*<span class="mbfd-dash-chip__count">· 7 open#s', $html);
        $this->assertStringContainsString('Rescue</span>', $html);
        $this->assertStringContainsString('Ladder</span>', $html);
        $this->assertStringContainsString('Special units</span>', $html);
        $this->assertStringContainsString('Marine &amp; specialty units', $html);

        $vehicle->call('setFilter', 'marine')
            ->assertSee('Bilge alarm sounding intermittently')
            ->assertDontSee('Suction unit not holding a charge')
            ->assertSet('filter', 'marine');

        $vehicle->call('setFilter', '<script>')->assertSet('filter', 'all');
    }

    public function test_optional_chips_are_hidden_when_their_count_is_zero(): void
    {
        $this->app->instance(IssueSourceRegistry::class, new IssueSourceRegistry([
            new FakeIssueSource('fake_vehicle', [DashboardView::VehicleCheckout], [FakeIssueSource::issue('fake_vehicle', '1', ['tags' => ['rescue']])]),
        ]));

        $html = Livewire::test(DashboardBoard::class, ['dashboard' => 'vehicle-checkout'])->html();
        $this->assertStringContainsString('Rescue</span>', $html);
        $this->assertStringNotContainsString('Ladder</span>', $html);
        $this->assertStringNotContainsString('Special units</span>', $html);
    }

    public function test_station_view_shows_station_chips_and_work_order_status(): void
    {
        $this->useFixtures();

        $html = Livewire::test(DashboardBoard::class, ['dashboard' => 'station-operations'])->html();
        foreach (['Facilities</span>', 'Supplies</span>', 'Access / IT</span>', 'High priority</span>', 'Awaiting response</span>'] as $chip) {
            $this->assertStringContainsString($chip, $html);
        }
        $this->assertStringContainsString('Facilities &amp; work orders', $html);
        $this->assertStringContainsString('Needs review', $html);
        $this->assertStringContainsString('Completed today', $html);
        $this->assertStringContainsString('Station messages &amp; notes', $html);
    }

    public function test_row_time_labels_use_new_york_today_yesterday_and_dates(): void
    {
        $this->useFixtures();
        $html = Livewire::test(DashboardBoard::class, ['dashboard' => 'vehicle-checkout'])->html();

        $this->assertStringContainsString('>10:17 AM</time>', $html);
        $this->assertStringContainsString('>Yesterday, 9:42 AM</time>', $html);
        $this->assertStringContainsString('>Oct 27, 4:02 PM</time>', $html);
    }

    public function test_titles_and_summaries_are_escaped(): void
    {
        $this->app->instance(IssueSourceRegistry::class, new IssueSourceRegistry([
            new FakeIssueSource('fake_vehicle', [DashboardView::Overview, DashboardView::VehicleCheckout], [
                FakeIssueSource::issue('fake_vehicle', '1', ['title' => '<script>alert(1)</script>', 'summary' => '<img src=x onerror=alert(2)>', 'subject' => '<b>R1</b>', 'actorLabel' => '"><script>alert(3)</script>']),
            ]),
        ]));

        $html = $this->get('/admin/dashboards/vehicle-checkout')->assertOk()->getContent();
        $this->assertStringNotContainsString('<script>alert(', $html);
        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    }

    public function test_non_admins_and_uniform_only_managers_cannot_open_the_boards(): void
    {
        $member = User::factory()->create(['account_status' => 'active']);
        $this->actingAs($member);
        foreach (['/admin', '/admin/dashboards/vehicle-checkout', '/admin/dashboards/station-operations', '/admin/logistics-board'] as $path) {
            $this->get($path)->assertForbidden();
        }

        $uniform = User::factory()->create(['account_status' => 'active']);
        $uniform->givePermissionTo(ApplicationAccessService::UNIFORM_MANAGER_PERMISSIONS);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs($uniform->refresh());
        $this->assertFalse(VehicleCheckoutDashboard::canAccess());
        $this->assertFalse(StationOperationsDashboard::canAccess());
        $this->assertFalse(LogisticsBoard::canAccess());
        $this->get('/admin/dashboards/vehicle-checkout')->assertForbidden();
        $this->get('/admin/logistics-board')->assertForbidden();
        // The overview stays their home page, with the boards they cannot open shown as unavailable.
        $html = $this->get('/admin')->assertOk()->getContent();
        $this->assertStringContainsString('Next dashboard: Vehicle Checkout (not available)', $html);

        Livewire::test(DashboardBoard::class, ['dashboard' => 'vehicle-checkout'])->assertForbidden();
    }

    public function test_unknown_dashboard_key_is_rejected(): void
    {
        Livewire::test(DashboardBoard::class, ['dashboard' => '../../etc'])->assertNotFound();
    }

    public function test_dashboard_navigation_sits_in_operations(): void
    {
        foreach ([Dashboard::class => 10, VehicleCheckoutDashboard::class => 11, StationOperationsDashboard::class => 12, LogisticsBoard::class => 13] as $page => $sort) {
            $this->assertSame('Operations', $page::getNavigationGroup());
            $this->assertSame($sort, $page::getNavigationSort());
        }
        $this->assertSame('Admin Overview', Dashboard::getNavigationLabel());
        $this->assertSame('Logistics Board', LogisticsBoard::getNavigationLabel());
    }
}
