<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboards;

use App\Filament\Resources\ApparatusServiceTicketResource\Pages\ViewApparatusServiceTicket;
use App\Models\Apparatus;
use App\Models\ApparatusServiceTicket;
use App\Models\ApparatusServiceTicketUpdate;
use App\Models\Employee;
use App\Models\Station;
use App\Services\ApparatusServiceTicketWorkflowService;
use App\Services\Dashboards\Sources\Vehicle\ApparatusServiceTicketSource;
use App\Support\Dashboards\IssueSourceRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

final class VehicleServiceTicketSourceTest extends TestCase
{
    use RefreshDatabase;
    use VehicleDashboardFixtures;

    private ApparatusServiceTicketSource $source;

    private Apparatus $ladder;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->setUpVehicleDashboard();
        $this->source = app(IssueSourceRegistry::class)->get('service_ticket');
        $station = Station::query()->create(['station_number' => 3, 'name' => 'Station 3', 'address' => '5303 Collins Avenue', 'is_active' => true]);
        $this->ladder = Apparatus::query()->create([
            'station_id' => $station->id, 'unit_id' => 'L3', 'name' => 'Ladder 3', 'type' => 'Ladder', 'designation' => 'L3',
            'vehicle_number' => '3003', 'slug' => 'ladder-3', 'status' => 'In Service', 'daily_checkout_template' => 'ladder3',
            'current_engine_hours' => 100, 'current_miles' => 2000,
        ]);
        $this->employee = Employee::query()->create(['employee_id' => '7001', 'name' => 'Casey Requester', 'rank' => 'Firefighter', 'password' => 'unused']);
    }

    private function ticket(array $overrides = []): ApparatusServiceTicket
    {
        $result = app(ApparatusServiceTicketWorkflowService::class)->submitFromEmployee($this->employee, $this->ladder, [
            'client_submission_id' => (string) Str::uuid(),
            'category' => 'repair_mechanical',
            'title' => $overrides['title'] ?? 'Aerial outrigger slow to deploy',
            'description' => $overrides['description'] ?? 'Driver-side outrigger takes twice as long as normal.',
            'priority' => $overrides['priority'] ?? 'attention',
        ]);
        $ticket = $result->ticket;
        unset($overrides['title'], $overrides['description'], $overrides['priority']);
        if ($overrides !== []) {
            $ticket->forceFill($overrides)->saveQuietly();
        }

        return $ticket->fresh();
    }

    private function versionOf(ApparatusServiceTicket $ticket): string
    {
        return $this->source->detail($this->personas()['super admin'], (string) $ticket->id)->issue->version;
    }

    public function test_ticket_managers_see_tickets_and_members_see_nothing(): void
    {
        $ticket = $this->ticket();

        foreach ($this->personas() as $persona => $user) {
            $rows = $this->source->rows($user, $this->boardQuery(), 10);
            $this->assertCount(1, $rows, $persona);
            $this->assertSame(['ladder', 'repair'], $rows[0]->tags);
            $this->assertSame('ladder-3', $rows[0]->imageKey);
            $this->assertSame('medium', $rows[0]->severity);
            $this->assertSame('awaiting_response', $rows[0]->stage);
            $this->assertSame('Casey Requester', $rows[0]->actorLabel);
            $this->assertStringEndsWith('/apparatus-service-tickets/'.$ticket->id, (string) $rows[0]->sourceUrl);
        }

        $member = $this->plainMember();
        $this->assertSame([], $this->source->rows($member, $this->boardQuery(), 10));
        $this->assertNull($this->source->detail($member, (string) $ticket->id));
        $this->assertSame(0, array_sum($this->source->counts($member, $this->boardQuery())));
    }

    public function test_display_mode_hides_the_requester_and_description(): void
    {
        $this->ticket();
        $row = $this->source->rows($this->personas()['admin'], $this->boardQuery(display: true), 10)[0];

        $this->assertNull($row->actorLabel);
        $this->assertNull($row->summary);
        $this->assertSame('Aerial outrigger slow to deploy', $row->title);
    }

    public function test_counts_match_rows_and_logged_maintenance_archived_and_old_tickets_stay_off_the_board(): void
    {
        $urgent = $this->ticket(['priority' => 'urgent', 'title' => 'Brake air leak']);
        $this->ticket(['status' => 'scheduled']);
        $this->ticket(['priority' => 'routine', 'status' => 'completed', 'completed_at' => '2026-10-30 13:00:00']);
        $this->ticket(['status' => 'cancelled', 'cancelled_at' => '2026-10-30 13:00:00']);
        $oldDone = $this->ticket(['status' => 'completed', 'completed_at' => '2026-10-28 13:00:00']);
        $archived = $this->ticket(['archived_at' => now()]);
        $pm = $this->ticket(['origin' => 'pm', 'status' => 'completed', 'completed_at' => '2026-10-30 13:00:00']);

        $admin = $this->personas()['admin'];
        $query = $this->boardQuery();
        $rows = $this->allRows($this->source, $admin, $query);
        $ids = array_map(static fn ($row): int => (int) $row->id, $rows);

        $this->assertSame($urgent->id, $ids[0]);
        $this->assertCount(4, $rows);
        foreach ([$oldDone, $archived, $pm] as $hidden) {
            $this->assertNotContains($hidden->id, $ids);
        }
        $tags = ['rescue', 'engine_puc', 'ladder', 'marine', 'special', 'repair'];
        $counts = $this->source->counts($admin, $query);
        $this->assertSame($this->countsFromRows($rows, $tags), array_intersect_key($counts, array_flip(['open', 'awaiting_response', 'in_progress', 'high', 'resolved_today', ...$tags])));
        $this->assertSame(['open' => 2, 'awaiting_response' => 1, 'high' => 1, 'resolved_today' => 1], array_intersect_key($counts, array_flip(['open', 'awaiting_response', 'high', 'resolved_today'])));
    }

    public function test_completion_counts_toward_the_operational_day_that_contains_it_across_dst(): void
    {
        $admin = $this->personas()['admin'];
        $before = $this->ticket(['status' => 'completed', 'completed_at' => CarbonImmutable::parse('2026-11-01 07:59:59', 'America/New_York')->utc()]);
        $at = $this->ticket(['status' => 'completed', 'completed_at' => CarbonImmutable::parse('2026-11-01 08:00:00', 'America/New_York')->utc()]);

        $this->assertSame(1, $this->source->counts($admin, $this->boardQuery('2026-11-01 08:00:00'))['resolved_today']);
        $this->assertSame([(string) $at->id], array_map(static fn ($row): string => $row->id, $this->allRows($this->source, $admin, $this->boardQuery('2026-11-01 08:00:00'))));
        $this->assertSame([(string) $before->id], array_map(static fn ($row): string => $row->id, $this->allRows($this->source, $admin, $this->boardQuery('2026-11-01 07:59:00'))));
    }

    public function test_acknowledge_uses_the_workflow_service_notifies_the_requester_and_the_ticket_page_shows_it(): void
    {
        $ticket = $this->ticket();
        $admin = $this->personas()['logistics admin'];
        $detail = $this->source->detail($admin, (string) $ticket->id);
        $this->assertSame(['acknowledge', 'schedule', 'cancel', 'add_note'], array_map(static fn ($action): string => $action->key, $detail->actions));

        $result = $this->source->perform($admin, (string) $ticket->id, 'acknowledge', ['public_note' => 'Fleet has it.', 'assigned_to_user_id' => (string) $admin->id], $detail->issue->version);

        $this->assertSame('done', $result->outcome, $result->message);
        $fresh = $ticket->fresh();
        $this->assertSame('acknowledged', $fresh->status);
        $this->assertNotNull($fresh->acknowledged_at);
        $this->assertSame($admin->id, $fresh->assigned_to_user_id);
        $this->assertSame('Fleet has it.', $fresh->current_public_response);
        $this->assertSame(1, ApparatusServiceTicketUpdate::query()->where('apparatus_service_ticket_id', $ticket->id)->where('status', 'acknowledged')->count());

        $this->actingAs($admin);
        Livewire::test(ViewApparatusServiceTicket::class, ['record' => $ticket->id])->assertSee('Acknowledged')->assertSee('Fleet has it.');
    }

    public function test_schedule_converts_eastern_input_and_complete_requires_a_summary(): void
    {
        $ticket = $this->ticket();
        $admin = $this->personas()['admin'];

        $this->assertSame('invalid', $this->source->perform($admin, (string) $ticket->id, 'schedule', ['scheduled_for' => '2026-11-03 09:00', 'service_type' => 'Hydraulics', 'scheduled_location' => 'Fleet shop'], $this->versionOf($ticket))->outcome);
        $result = $this->source->perform($admin, (string) $ticket->id, 'schedule', [
            'scheduled_for' => '2026-11-03 09:00', 'service_type' => 'Hydraulics', 'scheduled_location' => 'Fleet shop', 'public_note' => 'Booked for Tuesday.',
        ], $this->versionOf($ticket));
        $this->assertSame('done', $result->outcome, $result->message);
        $this->assertSame('2026-11-03 14:00:00', $ticket->fresh()->scheduled_for->utc()->format('Y-m-d H:i:s'));

        $this->source->perform($admin, (string) $ticket->id, 'start_work', [], $this->versionOf($ticket));
        $this->assertSame('invalid', $this->source->perform($admin, (string) $ticket->id, 'complete', [], $this->versionOf($ticket))->outcome);
        $done = $this->source->perform($admin, (string) $ticket->id, 'complete', ['resolution_summary' => 'Replaced the outrigger valve.'], $this->versionOf($ticket));
        $this->assertSame('done', $done->outcome, $done->message);
        $this->assertSame('completed', $ticket->fresh()->status);
    }

    public function test_stale_version_conflicts_and_unauthorized_users_are_forbidden_with_nothing_written(): void
    {
        $ticket = $this->ticket();
        $admin = $this->personas()['admin'];
        $stale = $this->versionOf($ticket);
        $this->source->perform($admin, (string) $ticket->id, 'add_note', ['internal_note' => 'Called the vendor.'], $stale);
        $updates = ApparatusServiceTicketUpdate::query()->count();

        $this->assertSame('conflict', $this->source->perform($admin, (string) $ticket->id, 'acknowledge', [], $stale)->outcome);
        $this->assertSame('forbidden', $this->source->perform($this->plainMember(), (string) $ticket->id, 'acknowledge', [], $this->versionOf($ticket))->outcome);
        $this->assertSame('forbidden', $this->source->perform($this->fleetViewer(), (string) $ticket->id, 'acknowledge', [], $this->versionOf($ticket))->outcome);
        $this->assertSame($updates, ApparatusServiceTicketUpdate::query()->count());
        $this->assertSame('submitted', $ticket->fresh()->status);
    }

    public function test_repeating_an_acknowledgement_is_done_without_a_second_update_or_notification(): void
    {
        $ticket = $this->ticket();
        $admin = $this->personas()['admin'];
        $version = $this->versionOf($ticket);

        $this->assertSame('done', $this->source->perform($admin, (string) $ticket->id, 'acknowledge', [], $version)->outcome);
        $notified = count(Notification::sentNotifications());
        $updates = ApparatusServiceTicketUpdate::query()->count();
        $this->assertSame('done', $this->source->perform($admin, (string) $ticket->id, 'acknowledge', [], $version)->outcome);

        $this->assertSame($updates, ApparatusServiceTicketUpdate::query()->count());
        $this->assertSame($notified, count(Notification::sentNotifications()));
    }

    public function test_html_is_returned_raw_for_the_view_to_escape(): void
    {
        $ticket = $this->ticket(['title' => '<b onmouseover=x>Pump</b>', 'description' => '<script>alert(1)</script> pump cavitates']);
        $row = $this->source->rows($this->personas()['admin'], $this->boardQuery(), 10)[0];

        $this->assertSame('<b onmouseover=x>Pump</b>', $row->title);
        $this->assertSame('<script>alert(1)</script> pump cavitates', $row->summary);
        $this->assertSame('<script>alert(1)</script> pump cavitates', $this->source->detail($this->personas()['admin'], (string) $ticket->id)->body);
    }

    public function test_fifty_tickets_load_in_a_bounded_number_of_queries(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $this->ticket(['title' => 'Ticket number '.$i]);
        }
        $admin = $this->personas()['admin'];
        $this->source->rows($admin, $this->boardQuery(), 1);

        $queries = $this->queriesDuring(function () use ($admin): void {
            $this->assertCount(50, $this->source->rows($admin, $this->boardQuery(tags: ['ladder']), 50));
            $this->source->counts($admin, $this->boardQuery(tags: ['ladder']));
        });
        $this->assertLessThanOrEqual(12, $queries);
    }
}
