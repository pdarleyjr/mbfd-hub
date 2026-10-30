<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboards;

use App\Enums\HubSupportTicketStatus;
use App\Filament\Resources\HubSupportTicketResource\Pages\ViewHubSupportTicket;
use App\Models\HubSupportTicket;
use App\Models\HubSupportTicketUpdate;
use App\Services\Dashboards\Sources\Station\HubSupportTicketSource;
use App\Support\Dashboards\DashboardQuery;
use App\Support\OperationalDisplayWindow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

final class StationHubSupportSourceTest extends TestCase
{
    use RefreshDatabase;
    use StationDashboardFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    private function source(): HubSupportTicketSource
    {
        return app(HubSupportTicketSource::class);
    }

    public function test_visibility_follows_the_policy_and_never_reaches_the_wall(): void
    {
        $ticket = HubSupportTicket::factory()->create(['impact' => 'feature_unavailable', 'reporter_name_snapshot' => 'Pat Member']);
        $outsider = $this->adminWith(['admin.stations.view']);
        $viewer = $this->adminWith(['admin.support.view']);

        $this->assertSame([], $this->source()->rows($outsider, DashboardQuery::now(), 50));
        $this->assertNull($this->source()->detail($outsider, (string) $ticket->id));
        $this->assertSame([], $this->source()->rows($viewer, DashboardQuery::now(displayMode: true), 50));

        $rows = $this->source()->rows($viewer, DashboardQuery::now(), 50);
        $this->assertCount(1, $rows);
        $this->assertSame(['access_it'], $rows[0]->tags);
        $this->assertSame('high', $rows[0]->severity);
        $this->assertSame('Pat Member', $rows[0]->actorLabel);

        $detail = $this->source()->detail($viewer, (string) $ticket->id);
        $this->assertSame([], $detail->actions);
        $this->assertSame('forbidden', $this->source()->perform($viewer, (string) $ticket->id, 'acknowledge', [], $detail->issue->version)->outcome);
    }

    public function test_counts_match_rows(): void
    {
        $viewer = $this->adminWith(['admin.support.view']);
        HubSupportTicket::factory()->create(['impact' => 'feature_unavailable']);
        HubSupportTicket::factory()->create(['status' => 'in_progress', 'impact' => 'task_blocking']);
        HubSupportTicket::factory()->create(['status' => 'resolved', 'resolved_at' => now()]);
        HubSupportTicket::factory()->create(['status' => 'closed', 'closed_at' => now()->subDays(2)]);

        $rows = $this->source()->rows($viewer, DashboardQuery::now(), 50);
        $counts = $this->source()->counts($viewer, DashboardQuery::now());

        $this->assertCount(3, $rows);
        $this->assertSame(['open' => 2, 'awaiting_response' => 1, 'in_progress' => 1, 'high' => 1, 'resolved_today' => 1, 'facilities' => 0, 'supplies' => 0, 'access_it' => 3, 'other' => 0], $counts);
        $this->assertSame([], $this->source()->rows($viewer, new DashboardQuery(OperationalDisplayWindow::forNow(), tags: ['facilities']), 50));
    }

    public function test_acknowledge_uses_the_workflow_and_the_resource_sees_it(): void
    {
        $manager = $this->adminWith(['admin.support.view', 'admin.support.manage']);
        $ticket = HubSupportTicket::factory()->create();
        $detail = $this->source()->detail($manager, (string) $ticket->id);
        $this->assertSame(['acknowledge', 'start', 'reply', 'assign'], array_map(fn ($a) => $a->key, $detail->actions));

        $result = $this->source()->perform($manager, (string) $ticket->id, 'acknowledge', ['public_response' => 'Looking now.'], $detail->issue->version);
        $again = $this->source()->perform($manager, (string) $ticket->id, 'acknowledge', ['public_response' => 'Looking now.'], $detail->issue->version);

        $this->assertSame(['done', 'done'], [$result->outcome, $again->outcome]);
        $this->assertSame(HubSupportTicketStatus::Acknowledged, $ticket->fresh()->status);
        $this->assertNotNull($ticket->fresh()->acknowledged_at);
        $update = HubSupportTicketUpdate::query()->where('hub_support_ticket_id', $ticket->id)->sole();
        $this->assertSame('Looking now.', $update->public_response);
        $this->assertSame($manager->id, (int) $update->changed_by_user_id);

        $this->actingAs($manager);
        Livewire::test(ViewHubSupportTicket::class, ['record' => $ticket->id])->assertSee('Looking now.');
    }

    public function test_stale_versions_conflict_and_domain_validation_comes_back_as_invalid(): void
    {
        $manager = $this->adminWith(['admin.support.view', 'admin.support.manage']);
        $ticket = HubSupportTicket::factory()->create(['status' => 'acknowledged']);
        $version = $this->source()->detail($manager, (string) $ticket->id)->issue->version;

        $missing = $this->source()->perform($manager, (string) $ticket->id, 'request_info', [], $version);
        $this->assertSame('invalid', $missing->outcome);
        $this->assertArrayHasKey('public_response', $missing->errors);
        $unresolved = $this->source()->perform($manager, (string) $ticket->id, 'resolve', [], $version);
        $this->assertArrayHasKey('resolution_summary', $unresolved->errors);

        $this->assertSame('done', $this->source()->perform($manager, (string) $ticket->id, 'reply', ['public_response' => 'Can you send a screenshot?'], $version)->outcome);
        $stale = $this->source()->perform($manager, (string) $ticket->id, 'resolve', ['resolution_summary' => 'Fixed.'], $version);
        $this->assertSame('conflict', $stale->outcome);
        $this->assertSame(HubSupportTicketStatus::Acknowledged, $ticket->fresh()->status);
        $this->assertSame(1, HubSupportTicketUpdate::query()->count());

        $fresh = $this->source()->detail($manager, (string) $ticket->id)->issue->version;
        $this->assertSame('done', $this->source()->perform($manager, (string) $ticket->id, 'resolve', ['resolution_summary' => 'Fixed.'], $fresh)->outcome);
        $this->assertSame('Fixed.', $ticket->fresh()->resolution_summary);
    }

    public function test_assign_offers_only_report_managers(): void
    {
        $manager = $this->adminWith(['admin.support.view', 'admin.support.manage']);
        $viewer = $this->adminWith(['admin.support.view']);
        $ticket = HubSupportTicket::factory()->create();
        $detail = $this->source()->detail($manager, (string) $ticket->id);
        $options = collect($detail->actions)->firstWhere('key', 'assign')->fields[0]['options'];

        $this->assertArrayHasKey($manager->id, $options);
        $this->assertArrayNotHasKey($viewer->id, $options);
        $this->assertSame('invalid', $this->source()->perform($manager, (string) $ticket->id, 'assign', ['assigned_to_user_id' => $viewer->id], $detail->issue->version)->outcome);
        $this->assertSame('done', $this->source()->perform($manager, (string) $ticket->id, 'assign', ['assigned_to_user_id' => $manager->id], $detail->issue->version)->outcome);
        $this->assertSame($manager->id, (int) $ticket->fresh()->assigned_to_user_id);
    }

    public function test_rows_use_a_fixed_number_of_queries_for_fifty_reports(): void
    {
        $viewer = $this->adminWith(['admin.support.view']);
        HubSupportTicket::factory()->create();
        $this->source()->rows($viewer, DashboardQuery::now(), 50);
        $small = $this->queriesFor(fn () => $this->source()->rows($viewer, DashboardQuery::now(), 50));
        HubSupportTicket::factory()->count(49)->create();

        $large = $this->queriesFor(fn () => $this->assertCount(50, $this->source()->rows($viewer, DashboardQuery::now(), 50)));

        $this->assertSame($small, $large);
    }
}
