<?php

declare(strict_types=1);

namespace Tests\Feature\Fleet\OperationalAlerts;

use App\Enums\ApparatusServiceTicketStatus;
use App\Models\ApparatusFleetEvent;
use App\Models\ApparatusOperationalAlert;
use App\Models\ApparatusServiceTicket;
use App\Services\ApparatusServiceTicketWorkflowService;
use App\Services\Display\DisplaySnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Fleet\OperationalAlerts\Concerns\BuildsOperationalAlertFixtures;
use Tests\TestCase;

final class ImportOperationalAlertsCommandTest extends TestCase
{
    use BuildsOperationalAlertFixtures;
    use RefreshDatabase;

    private const FILE = 'database/data/fleet-operational-alerts-2026-10-30.json';

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->setUpAlertFixtures();
    }

    public function test_the_file_creates_exactly_the_two_alerts_fleet_announced(): void
    {
        $this->artisan('fleet:import-operational-alerts', ['--file' => self::FILE])->assertSuccessful();

        $this->assertSame(2, ApparatusOperationalAlert::query()->count());
        $r44 = ApparatusOperationalAlert::query()->where('dedupe_key', 'fleet-email-2026-10-30-r44')->sole();
        $this->assertSame($this->r44->id, $r44->apparatus_id);
        $this->assertSame('r44', $r44->unit_slug);
        $this->assertSame('17503', $r44->vehicle_number);
        $this->assertSame($this->station4->id, $r44->station_id);
        $this->assertSame('upcoming_swap', $r44->category);
        $this->assertSame('Upcoming Apparatus Changeout: Rescue 44', $r44->title);
        $this->assertSame('R44 is scheduled to change vehicles Sunday afternoon, November 1, so Fleet can repair the current apparatus exhaust on Monday morning, November 2.', $r44->message);
        $this->assertSame('Exhaust repair', $r44->reason);
        $this->assertSame('Sunday, November 1, afternoon', $r44->scheduleLabel());
        $this->assertNull($r44->scheduled_at);
        $this->assertNull($r44->planned_replacement_apparatus_id, 'no replacement truck is invented');
        $this->assertSame('Maintenance', $r44->old_truck_status_after);
        $this->assertSame('Fleet email dated October 30, 2026', $r44->source);
        $this->assertFalse($r44->send_notification);
        $this->assertNull($r44->notified_at);

        $e1 = ApparatusOperationalAlert::query()->where('dedupe_key', 'fleet-email-2026-10-30-e1')->sole();
        $this->assertSame($this->e1->id, $e1->apparatus_id);
        $this->assertSame($this->station1->id, $e1->station_id);
        $this->assertSame('Upcoming Apparatus Changeout: Engine 1', $e1->title);
        $this->assertSame('E1 is scheduled to change vehicles Monday, November 2, to address a communications issue involving the shift pad.', $e1->message);
        $this->assertSame('Shift pad communications issue', $e1->reason);
        $this->assertSame('Monday, November 2', $e1->scheduleLabel());
        $this->assertNull($e1->scheduled_window);
    }

    public function test_station_4_shows_r44_and_not_e1_and_station_1_shows_e1(): void
    {
        $this->artisan('fleet:import-operational-alerts', ['--file' => self::FILE])->assertSuccessful();

        $station4 = $this->alerts()->forStation($this->station4->id, null);
        $station1 = $this->alerts()->forStation($this->station1->id, null);

        $this->assertSame(['Upcoming Apparatus Changeout: Rescue 44'], array_column($station4, 'title'));
        $this->assertSame(['Upcoming Apparatus Changeout: Engine 1'], array_column($station1, 'title'));
    }

    public function test_each_alert_links_an_open_ticket_from_fleet_with_no_acting_user_and_no_notification(): void
    {
        $this->artisan('fleet:import-operational-alerts', ['--file' => self::FILE])->assertSuccessful();

        Notification::assertNothingSent();
        $this->assertSame(2, ApparatusServiceTicket::query()->open()->count());

        $r44 = ApparatusOperationalAlert::query()->where('dedupe_key', 'fleet-email-2026-10-30-r44')->sole();
        $ticket = $r44->serviceTicket;
        $this->assertSame($this->r44->id, $ticket->apparatus_id);
        $this->assertSame('fleet', $ticket->origin);
        $this->assertNull($ticket->created_by_user_id);
        $this->assertSame('Fleet (email dated October 30, 2026)', $ticket->requester_name_snapshot);
        $this->assertSame(ApparatusServiceTicketStatus::Submitted->value, $ticket->status);
        $this->assertNull($ticket->scheduled_for);
        $this->assertSame('repair_mechanical', $ticket->category);
        $this->assertSame('Exhaust repair', $ticket->title);
        $this->assertSame('routine', $ticket->priority);
        $this->assertSame('Fleet advised on October 30, 2026 that the exhaust on this apparatus will be repaired first thing Monday morning, November 2, 2026.', $ticket->description);
        $this->assertMatchesRegularExpression('/^AST-\d{4}-\d{6}$/', (string) $ticket->ticket_number);
        $this->assertSame('68346820-fe62-5cc1-ba86-a1eac09b3823', $ticket->client_submission_id);

        $update = $ticket->updates()->sole();
        $this->assertNull($update->changed_by_user_id);
        $this->assertSame('fleet_notice', $update->metadata['source']);
        $this->assertStringContainsString('Fleet (email dated October 30, 2026)', (string) $update->internal_note);

        $e1 = ApparatusOperationalAlert::query()->where('dedupe_key', 'fleet-email-2026-10-30-e1')->sole()->serviceTicket;
        $this->assertSame('electrical', $e1->category);
        $this->assertSame('Shift pad communications issue', $e1->title);
        $this->assertSame('routine', $e1->priority);
    }

    public function test_recording_a_notice_clears_the_read_models_and_a_repeat_returns_the_same_ticket(): void
    {
        Cache::put(DisplaySnapshotService::SNAPSHOT_CACHE_KEY, 'stale');
        Cache::put("station.{$this->station4->id}.apparatus-service-tickets", 'stale');
        $service = app(ApparatusServiceTicketWorkflowService::class);
        $data = [
            'client_submission_id' => '68346820-fe62-5cc1-ba86-a1eac09b3823', 'category' => 'repair_mechanical', 'title' => 'Exhaust repair',
            'description' => 'Fleet advised the exhaust will be repaired.', 'priority' => 'routine', 'requester_name_snapshot' => 'Fleet (email)',
        ];

        $first = $service->recordFleetNotice($this->r44, $data);
        $second = $service->recordFleetNotice($this->r44, $data);

        $this->assertTrue($first->created);
        $this->assertFalse($second->created);
        $this->assertTrue($first->ticket->is($second->ticket));
        $this->assertSame(1, $first->ticket->updates()->count());
        $this->assertNull(Cache::get(DisplaySnapshotService::SNAPSHOT_CACHE_KEY));
        $this->assertNull(Cache::get("station.{$this->station4->id}.apparatus-service-tickets"));
        Notification::assertNothingSent();
    }

    public function test_running_twice_creates_one_of_each(): void
    {
        $this->artisan('fleet:import-operational-alerts', ['--file' => self::FILE])->assertSuccessful();
        $this->artisan('fleet:import-operational-alerts', ['--file' => self::FILE])
            ->expectsOutputToContain('already imported')
            ->assertSuccessful();

        $this->assertSame(2, ApparatusOperationalAlert::query()->count());
        $this->assertSame(2, ApparatusServiceTicket::query()->count());
        $this->assertSame(2, ApparatusFleetEvent::query()->where('event', 'alert_created')->count());
    }

    public function test_a_later_run_is_still_idempotent_after_the_unit_moved_away(): void
    {
        $this->artisan('fleet:import-operational-alerts', ['--file' => self::FILE])->assertSuccessful();
        $this->moveR44ToSpare();

        $this->artisan('fleet:import-operational-alerts', ['--file' => self::FILE])->assertSuccessful();
        $this->assertSame(2, ApparatusOperationalAlert::query()->count());
    }

    public function test_it_refuses_when_the_unit_no_longer_sits_on_that_number(): void
    {
        $this->moveR44ToSpare();

        $this->artisan('fleet:import-operational-alerts', ['--file' => self::FILE])
            ->expectsOutputToContain('R 44 is no longer on truck 17503; that truck now holds R-17503.')
            ->assertFailed();

        $this->assertSame(0, ApparatusOperationalAlert::query()->where('dedupe_key', 'fleet-email-2026-10-30-r44')->count());
        $this->assertSame(0, ApparatusServiceTicket::query()->where('apparatus_id', $this->r44->id)->count());
        $this->assertSame(1, ApparatusOperationalAlert::query()->where('dedupe_key', 'fleet-email-2026-10-30-e1')->count(), 'the other notice is independent');
    }

    public function test_it_refuses_when_no_truck_carries_the_number(): void
    {
        DB::table('apparatuses')->where('id', $this->e1->id)->update(['vehicle_number' => '020503']);

        $this->artisan('fleet:import-operational-alerts', ['--file' => self::FILE])
            ->expectsOutputToContain('No truck carries vehicle number 20503')
            ->assertFailed();

        $this->assertSame(0, ApparatusOperationalAlert::query()->where('dedupe_key', 'fleet-email-2026-10-30-e1')->count());
    }

    public function test_a_dry_run_reports_and_writes_nothing(): void
    {
        $this->artisan('fleet:import-operational-alerts', ['--file' => self::FILE, '--dry-run' => true])
            ->expectsOutputToContain('Dry run, nothing written')
            ->assertSuccessful();

        $this->assertSame(0, ApparatusOperationalAlert::query()->count());
        $this->assertSame(0, ApparatusServiceTicket::query()->count());
        $this->assertSame(0, ApparatusFleetEvent::query()->count());
    }

    public function test_a_missing_or_broken_file_is_a_clear_failure(): void
    {
        $this->artisan('fleet:import-operational-alerts')->expectsOutputToContain('Pass the alerts file')->assertFailed();
        $this->artisan('fleet:import-operational-alerts', ['--file' => 'database/data/no-such-file.json'])->expectsOutputToContain('was not found')->assertFailed();
    }

    public function test_importing_changes_no_field_on_any_apparatus(): void
    {
        $before = DB::table('apparatuses')->orderBy('id')->get()->all();

        $this->artisan('fleet:import-operational-alerts', ['--file' => self::FILE])->assertSuccessful();

        $this->assertEquals($before, DB::table('apparatuses')->orderBy('id')->get()->all());
    }
}
