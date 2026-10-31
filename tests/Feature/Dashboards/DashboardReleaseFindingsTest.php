<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboards;

use App\Enums\AccountStatus;
use App\Livewire\Dashboards\DashboardBoard;
use App\Models\Apparatus;
use App\Models\ApparatusServiceTicket;
use App\Models\AuthenticationSession;
use App\Models\Employee;
use App\Models\Station;
use App\Models\User;
use App\Notifications\OperationalAlertNotification;
use App\Services\ApparatusServiceTicketWorkflowService;
use App\Services\Dashboards\Sources\Station\StationRequestSource;
use App\Services\Dashboards\Support\ApparatusClass;
use App\Support\Dashboards\DashboardQuery;
use App\Support\Dashboards\IssueSourceRegistry;
use App\Support\OperationalDisplayWindow;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Notifications\Concerns\BuildsNotificationPersonas;
use Tests\TestCase;

/** What an independent review of the first dashboards and alerts build found. Each test states the correct behaviour. */
final class DashboardReleaseFindingsTest extends TestCase
{
    use BuildsNotificationPersonas;
    use RefreshDatabase;
    use StationDashboardFixtures;
    use VehicleDashboardFixtures;

    /** Exactly what resources/js/pwa/foreground-alerts.ts sends. */
    private const POLL = ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest', 'X-Hub-Background-Refresh' => '1', 'Referer' => 'http://localhost/'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function activeMember(): User
    {
        return User::factory()->create(['account_status' => AccountStatus::Active, 'must_change_password' => false]);
    }

    public function test_the_alert_poller_declares_itself_a_background_refresh(): void
    {
        $source = file_get_contents(resource_path('js/pwa/foreground-alerts.ts'));

        $this->assertIsString($source);
        $this->assertStringContainsString("'X-Hub-Background-Refresh': '1'", $source);
    }

    public function test_an_alert_poll_leaves_the_idle_clock_where_it_is(): void
    {
        $this->actingAsCanonicalUser($this->activeMember());
        $before = AuthenticationSession::query()->sole()->idle_expires_at->toIso8601String();

        $this->travel(5)->minutes();
        $this->get('/api/me/alerts', self::POLL)->assertOk();

        $this->assertSame($before, AuthenticationSession::query()->sole()->idle_expires_at->toIso8601String());
    }

    public function test_an_unattended_page_polling_for_alerts_still_reaches_the_idle_sign_out(): void
    {
        $this->actingAsCanonicalUser($this->activeMember());
        $idleEnds = AuthenticationSession::query()->sole()->idle_expires_at;

        $this->travelTo($idleEnds->copy()->addMinute());

        $this->assertSame(401, $this->get('/api/me/alerts', self::POLL)->getStatusCode());
    }

    public function test_an_alert_poll_does_not_use_up_a_flash_message(): void
    {
        $this->actingAsCanonicalUser($this->activeMember());
        session()->put('status', 'Your reply was sent.');
        session()->put('_flash.old', ['status']);
        session()->put('_flash.new', []);

        $this->get('/api/me/alerts', self::POLL)->assertOk();

        $this->assertSame('Your reply was sent.', session()->get('status'));
    }

    public function test_an_operational_alert_is_in_app_only_even_where_its_category_defaults_push_and_email_on(): void
    {
        $member = $this->rosterMember('Firefighter', 'member@miamibeachfl.gov');
        $this->addPushDevice($member);

        $alert = new OperationalAlertNotification(category: 'member_request_updates', title: 'SCBA expiring', body: 'Soon', url: '/employee');

        $this->assertSame(['database'], $alert->via($member));

        $member->notify($alert);
        $this->assertSame(1, $member->notifications()->count());
    }

    public function test_a_stale_schedule_is_refused_and_the_first_admins_details_stand(): void
    {
        Notification::fake();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $station = Station::query()->create(['station_number' => 3, 'name' => 'Station 3', 'address' => '1 X St', 'is_active' => true]);
        $unit = Apparatus::query()->create(['station_id' => $station->id, 'unit_id' => 'L3', 'name' => 'Ladder 3', 'type' => 'Ladder', 'designation' => 'L3', 'vehicle_number' => '3003', 'slug' => 'ladder-3', 'status' => 'In Service', 'daily_checkout_template' => 'ladder3']);
        $employee = Employee::query()->create(['employee_id' => '7001', 'name' => 'Casey', 'rank' => 'Firefighter', 'password' => 'unused']);
        $ticket = app(ApparatusServiceTicketWorkflowService::class)->submitFromEmployee($employee, $unit, ['client_submission_id' => (string) Str::uuid(), 'category' => 'repair_mechanical', 'title' => 'Outrigger', 'description' => 'Outrigger takes twice as long as normal', 'priority' => 'attention'])->ticket;
        $source = app(IssueSourceRegistry::class)->get('service_ticket');
        $first = $this->persona('super_admin', []);
        $second = $this->persona('super_admin', []);
        $stale = $source->detail($second, (string) $ticket->id)->issue->version;

        $this->assertSame('done', $source->perform($first, (string) $ticket->id, 'schedule', ['scheduled_for' => '2026-11-03', 'service_type' => 'Hydraulics', 'scheduled_location' => 'Vendor A', 'public_note' => 'A note'], $stale)->outcome);

        $result = $source->perform($second, (string) $ticket->id, 'schedule', ['scheduled_for' => '2026-11-09', 'service_type' => 'Transmission', 'scheduled_location' => 'Vendor B', 'public_note' => 'B note'], $stale);

        $this->assertSame('conflict', $result->outcome);
        $this->assertSame('Vendor A', ApparatusServiceTicket::query()->findOrFail($ticket->id)->scheduled_location);
    }

    public function test_a_station_chip_that_says_open_counts_only_open_items(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $admin = $this->persona('super_admin', []);
        $this->stationRequest(['status' => 'completed', 'completed_at' => now()]);

        $counts = app(StationRequestSource::class)->counts($admin, new DashboardQuery(OperationalDisplayWindow::forNow()));

        $this->assertSame(0, $counts['open']);
        $this->assertSame(0, $counts['facilities']);
    }

    public function test_show_more_reaches_every_open_item_on_the_board(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($this->persona('super_admin', []));
        foreach (range(1, 30) as $n) {
            $this->stationRequest(['title' => "Request {$n}", 'priority' => 'low']);
        }

        $board = Livewire::test(DashboardBoard::class, ['dashboard' => 'station-operations']);
        $listed = static function (string $html): int {
            preg_match_all('/data-issue-ref="(station_request:\d+)"/', $html, $refs);

            return count(array_unique($refs[1]));
        };

        $board->assertSee('Show more issues');
        $this->assertSame(25, $listed($board->html()));

        $board->call('showMore');
        $board->assertDontSee('Show more issues');
        $this->assertSame(30, $listed($board->html()));
    }

    public function test_only_fire_boat_6_uses_the_fire_boat_art(): void
    {
        $classes = app(ApparatusClass::class);
        $marine1 = Apparatus::query()->create(['unit_id' => 'M1', 'vehicle_number' => 'M1', 'name' => 'Marine 1', 'designation' => 'Marine 1', 'type' => 'Marine', 'class_description' => 'Fire Boats', 'status' => 'Active']);
        $fireBoat6 = Apparatus::query()->create(['unit_id' => 'FB6', 'vehicle_number' => 'FB6', 'name' => 'Fire Boat 6', 'designation' => 'Fire Boat 6', 'type' => 'Marine', 'class_description' => 'Fire Boats', 'status' => 'Active']);

        $this->assertSame(['tag' => ApparatusClass::MARINE, 'imageKey' => null], $classes->of($marine1));
        $this->assertSame(['tag' => ApparatusClass::MARINE, 'imageKey' => 'fireboat'], $classes->of($fireBoat6));
    }
}
