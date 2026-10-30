<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboards;

use App\Filament\Resources\InventoryCorrectionResource\Pages\ViewInventoryCorrection;
use App\Models\ApparatusInventoryChangeSet;
use App\Models\ApparatusInventoryCorrection;
use App\Models\ApparatusInventoryCorrectionEvent;
use App\Services\Dashboards\Sources\Vehicle\InventoryCorrectionSource;
use App\Support\Dashboards\IssueSourceRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Feature\Inventory\Concerns\BuildsInventoryCorrectionFixtures;
use Tests\TestCase;

final class VehicleInventoryCorrectionSourceTest extends TestCase
{
    use BuildsInventoryCorrectionFixtures;
    use RefreshDatabase;
    use VehicleDashboardFixtures;

    private InventoryCorrectionSource $source;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpInventoryFleet();
        $this->setUpVehicleDashboard();
        $this->source = app(IssueSourceRegistry::class)->get('inventory_correction');
    }

    private function move(string $unit = 'E1', string $note = 'Now behind the officer seat.'): ApparatusInventoryCorrection
    {
        $passports = $this->item($unit, 'Passports');

        return $this->reported($unit, [
            'reason' => 'moved', 'compartment_id' => $passports['compartment_id'], 'item_id' => $passports['item_id'],
            'proposal' => ['to_compartment_id' => 'comp_a1'], 'note' => $note,
        ]);
    }

    private function passportsCompartment(string $unit): string
    {
        return $this->item($unit, 'Passports')['compartment_id'];
    }

    public function test_reviewers_see_requests_off_the_wall_display_and_members_never_do(): void
    {
        $correction = $this->move();

        foreach ([...$this->personas(), 'fleet-only reviewer' => $this->fleetOnlyReviewer()] as $persona => $user) {
            $rows = $this->source->rows($user, $this->boardQuery(), 10);
            $this->assertCount(1, $rows, $persona);
            $this->assertSame(['engine_puc', 'inventory'], $rows[0]->tags);
            $this->assertSame('engine', $rows[0]->imageKey);
            $this->assertSame('Inventory Reporter', $rows[0]->actorLabel);
            $this->assertSame([], $this->source->rows($user, $this->boardQuery(display: true), 10), $persona.' on the wall display');
            $this->assertFalse($this->source->visibleTo($user, true));
        }

        $this->assertSame([], $this->source->rows($this->member, $this->boardQuery(), 10));
        $this->assertNull($this->source->detail($this->member, (string) $correction->id));
        $this->assertSame('forbidden', $this->source->perform($this->member, (string) $correction->id, 'reject', [], '0:1')->outcome);
        $this->assertSame(ApparatusInventoryCorrection::STATUS_SUBMITTED, $correction->fresh()->status);
    }

    public function test_approve_as_requested_changes_only_the_reporting_apparatus_through_the_service(): void
    {
        Notification::fake();
        $correction = $this->move();
        $reviewer = $this->logistics();
        $e3Before = $this->passportsCompartment('E3');
        $detail = $this->source->detail($reviewer, (string) $correction->id);
        $approve = collect($detail->actions)->firstWhere('key', 'approve');
        $fingerprint = (string) array_key_first($approve->fields[0]['options']);

        $result = $this->source->perform($reviewer, (string) $correction->id, 'approve', ['apply_to' => $fingerprint, 'member_message' => 'Thanks!'], $detail->issue->version);

        $this->assertSame('done', $result->outcome, $result->message);
        $this->assertSame(ApparatusInventoryCorrection::STATUS_APPLIED, $correction->fresh()->status);
        $this->assertSame('comp_a1', $this->passportsCompartment('E1'));
        $this->assertSame($e3Before, $this->passportsCompartment('E3'), 'only the reporting apparatus changes');
        $this->assertSame(1, ApparatusInventoryChangeSet::query()->count());

        $this->actingAs($reviewer);
        Livewire::test(ViewInventoryCorrection::class, ['record' => $correction->id])->assertSee('Approved');

        $repeat = $this->source->perform($reviewer, (string) $correction->id, 'approve', ['apply_to' => $fingerprint], $detail->issue->version);
        $this->assertSame('done', $repeat->outcome);
        $this->assertSame(1, ApparatusInventoryChangeSet::query()->count());
    }

    public function test_a_stale_impact_or_stale_version_is_a_conflict_and_nothing_changes(): void
    {
        $correction = $this->move();
        $reviewer = $this->logistics();
        $detail = $this->source->detail($reviewer, (string) $correction->id);

        $this->assertSame('conflict', $this->source->perform($reviewer, (string) $correction->id, 'approve', ['apply_to' => 'not-the-reviewed-impact'], $detail->issue->version)->outcome);
        $this->source->perform($reviewer, (string) $correction->id, 'reply', ['visibility' => 'internal', 'body' => 'Checking with the officer.'], $detail->issue->version);
        $this->assertSame('conflict', $this->source->perform($reviewer, (string) $correction->id, 'reject', [], $detail->issue->version)->outcome);

        $this->assertSame(ApparatusInventoryCorrection::STATUS_SUBMITTED, $correction->fresh()->status);
        $this->assertSame(0, ApparatusInventoryChangeSet::query()->count());
        $this->assertNotSame('comp_a1', $this->passportsCompartment('E1'));
    }

    public function test_ask_reply_and_reject_are_recorded_and_a_repeated_reject_is_a_no_op(): void
    {
        $correction = $this->move();
        $reviewer = $this->fleetOnlyReviewer();
        $version = fn (): string => $this->source->detail($reviewer, (string) $correction->id)->issue->version;

        $this->assertSame('done', $this->source->perform($reviewer, (string) $correction->id, 'ask_member', ['message' => 'Which side of the cab?'], $version())->outcome);
        $this->assertSame(ApparatusInventoryCorrection::STATUS_CLARIFICATION, $correction->fresh()->status);
        $this->assertSame('in_progress', $this->source->rows($reviewer, $this->boardQuery(), 10)[0]->stage);

        $this->assertSame('invalid', $this->source->perform($reviewer, (string) $correction->id, 'reply', ['visibility' => 'member', 'body' => ''], $version())->outcome);
        $this->assertSame('done', $this->source->perform($reviewer, (string) $correction->id, 'reply', ['visibility' => 'internal', 'body' => 'Officer confirms.'], $version())->outcome);
        $this->assertDatabaseHas('apparatus_inventory_correction_events', ['apparatus_inventory_correction_id' => $correction->id, 'event_type' => 'internal_note', 'body' => 'Officer confirms.']);

        $before = $version();
        $this->assertSame('done', $this->source->perform($reviewer, (string) $correction->id, 'reject', ['reason' => 'Stays in the front cab.'], $before)->outcome);
        $this->assertSame('done', $this->source->perform($reviewer, (string) $correction->id, 'reject', ['reason' => 'Stays in the front cab.'], $before)->outcome);
        $this->assertSame(1, ApparatusInventoryCorrectionEvent::query()->where('apparatus_inventory_correction_id', $correction->id)->where('event_type', 'rejected')->count());
        $this->assertSame(['reply'], array_map(static fn ($action): string => $action->key, $this->source->detail($reviewer, (string) $correction->id)->actions));
    }

    public function test_counts_match_rows_and_decisions_count_for_the_operational_day_across_dst(): void
    {
        $open = $this->move('E1');
        $decidedBefore = $this->move('E3');
        $decidedAt = $this->reported('L1', ['reason' => 'no_longer_carried', 'compartment_id' => $this->item('L1', 'Passports')['compartment_id'], 'item_id' => $this->item('L1', 'Passports')['item_id']]);
        $decidedBefore->forceFill(['status' => 'rejected', 'decided_at' => CarbonImmutable::parse('2026-11-01 07:59:59', 'America/New_York')->utc()])->save();
        $decidedAt->forceFill(['status' => 'applied', 'decided_at' => CarbonImmutable::parse('2026-11-01 08:00:00', 'America/New_York')->utc()])->save();
        $reviewer = $this->logistics();
        $tags = ['rescue', 'engine_puc', 'ladder', 'marine', 'special', 'inventory'];

        $query = $this->boardQuery('2026-11-01 08:00:00');
        $rows = $this->allRows($this->source, $reviewer, $query);
        $this->assertSame([(string) $open->id, (string) $decidedAt->id], array_map(static fn ($row): string => $row->id, $rows));
        $this->assertSame($this->countsFromRows($rows, $tags), array_intersect_key($this->source->counts($reviewer, $query), array_flip(['open', 'awaiting_response', 'high', 'resolved_today', ...$tags])));
        $this->assertSame(1, $this->source->counts($reviewer, $query)['resolved_today']);

        $earlier = array_map(static fn ($row): string => $row->id, $this->allRows($this->source, $reviewer, $this->boardQuery('2026-11-01 07:59:00')));
        $this->assertSame([(string) $open->id, (string) $decidedBefore->id], $earlier);
        $this->assertSame(['ladder-1'], array_map(static fn ($row): ?string => $row->imageKey, $this->allRows($this->source, $reviewer, $this->boardQuery('2026-11-01 08:00:00', tags: ['ladder']))));
    }

    public function test_member_text_is_returned_raw_and_fifty_requests_load_in_bounded_queries(): void
    {
        $correction = $this->move('E1', '<script>alert(1)</script>');
        $reviewer = $this->logistics();
        $this->assertSame('<script>alert(1)</script>', $this->source->detail($reviewer, (string) $correction->id)->body);

        // Reporting limits cap one member's reports, so the rest are copies of the stored report.
        foreach ($this->units as $unit) {
            for ($i = 0; $i < 8; $i++) {
                $copy = $correction->replicate();
                $copy->forceFill(['apparatus_id' => $unit->id, 'client_request_id' => (string) Str::uuid(), 'reporter_user_id' => $this->persona(null, [])->id])->save();
            }
        }
        $this->source->rows($reviewer, $this->boardQuery(), 1);
        $queries = $this->queriesDuring(function () use ($reviewer): void {
            $this->assertGreaterThanOrEqual(50, count($this->source->rows($reviewer, $this->boardQuery(), 100)));
            $this->source->counts($reviewer, $this->boardQuery());
        });
        $this->assertLessThanOrEqual(12, $queries);
    }
}
