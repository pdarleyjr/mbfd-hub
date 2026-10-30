<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboards;

use App\Models\Apparatus;
use App\Models\ApparatusDefect;
use App\Models\ApparatusInspection;
use App\Models\ApparatusInspectionException;
use App\Models\ApparatusInspectionReviewEvent;
use App\Models\User;
use App\Services\Dashboards\Sources\Vehicle\InspectionExceptionSource;
use App\Support\Dashboards\IssueSourceRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class VehicleInspectionExceptionSourceTest extends TestCase
{
    use RefreshDatabase;
    use VehicleDashboardFixtures;

    private InspectionExceptionSource $source;

    private Apparatus $boat;

    private User $crew;

    private ApparatusInspection $inspection;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Queue::fake();
        config()->set('google_sheets.apparatus_sync_enabled', false);
        $this->setUpVehicleDashboard();
        $this->source = app(IssueSourceRegistry::class)->get('inspection_exception');
        $this->crew = User::factory()->create(['name' => 'Jordan Crew']);
        $this->boat = Apparatus::query()->create([
            'unit_id' => 'FB6', 'designation' => 'Fire Boat 6', 'name' => 'Fire Boat 6', 'type' => 'Marine', 'vehicle_number' => 'V-FB6',
            'status' => 'In Service', 'current_engine_hours' => 400, 'current_miles' => 1000, 'daily_checkout_template' => 'fireboat6',
        ]);
        $this->inspection = $this->inspection();
    }

    private function inspection(?string $at = null): ApparatusInspection
    {
        return ApparatusInspection::query()->create([
            'apparatus_id' => $this->boat->id, 'processed_apparatus_id' => $this->boat->id, 'actor_user_id' => $this->crew->id,
            'operator_name' => 'Jordan Crew', 'rank' => 'Firefighter', 'shift' => 'A', 'engine_hours' => 300, 'miles' => 900,
            'results' => [], 'review_status' => 'approved', 'processing_status' => 'accepted_with_exception', 'completed_at' => $at ?? now(),
        ]);
    }

    private function hold(string $field = 'engine_hours', string $status = 'open', array $attributes = []): ApparatusInspectionException
    {
        return ApparatusInspectionException::query()->create([
            'apparatus_inspection_id' => $this->inspection->id, 'apparatus_id' => $this->boat->id, 'field' => $field,
            'reason' => 'rollback', 'submitted_value' => 300, 'baseline_value' => 400, 'authoritative_value' => 400,
            'status' => $status, 'blocks_acceptance' => false, ...$attributes,
        ]);
    }

    private function versionOf(ApparatusInspectionException $hold): string
    {
        return $this->source->detail($this->personas()['super admin'], (string) $hold->id)->issue->version;
    }

    private function decisions(ApparatusInspectionException $hold): int
    {
        return ApparatusInspectionReviewEvent::query()->get()->filter(fn ($event): bool => (int) ($event->metadata['exception_id'] ?? 0) === $hold->id)->count();
    }

    public function test_reviewers_see_holds_display_mode_hides_the_crew_and_members_see_nothing(): void
    {
        $hold = $this->hold();

        foreach ($this->personas() as $persona => $user) {
            $rows = $this->source->rows($user, $this->boardQuery(now()->setTimezone('America/New_York')->format('Y-m-d H:i:s')), 10);
            $this->assertCount(1, $rows, $persona);
            $this->assertSame(['marine'], $rows[0]->tags);
            $this->assertSame('fireboat', $rows[0]->imageKey);
            $this->assertSame('Jordan Crew', $rows[0]->actorLabel);
            $this->assertSame('Engine hours reading needs review', $rows[0]->title);
        }
        $wall = $this->source->rows($this->personas()['admin'], $this->boardQuery(display: true), 10)[0];
        $this->assertNull($wall->actorLabel);

        $member = $this->plainMember();
        $this->assertSame([], $this->source->rows($member, $this->boardQuery(), 10));
        $this->assertNull($this->source->detail($member, (string) $hold->id));
        $this->assertSame('forbidden', $this->source->perform($member, (string) $hold->id, 'dismiss', ['reason' => 'Typo.'], $this->versionOf($hold))->outcome);
        $this->assertSame('forbidden', $this->source->perform($this->fleetViewer(), (string) $hold->id, 'dismiss', ['reason' => 'Typo.'], $this->versionOf($hold))->outcome);
        $this->assertSame('open', $hold->fresh()->status);
        $this->assertSame(0, $this->decisions($hold));
    }

    public function test_accepting_a_lower_reading_needs_the_meter_reset_confirmation_and_updates_the_fleet_record(): void
    {
        $hold = $this->hold();
        $admin = $this->personas()['logistics admin'];
        $accept = collect($this->source->detail($admin, (string) $hold->id)->actions)->firstWhere('key', 'accept');
        $this->assertSame('confirm_meter_reset', $accept->fields[0]['name']);
        $this->assertTrue($accept->fields[0]['required']);

        $refused = $this->source->perform($admin, (string) $hold->id, 'accept', ['reason' => 'Meter replaced.'], $this->versionOf($hold));
        $this->assertSame('invalid', $refused->outcome);
        $this->assertSame('400.0', (string) $this->boat->fresh()->current_engine_hours);

        $result = $this->source->perform($admin, (string) $hold->id, 'accept', ['reason' => 'Meter replaced.', 'confirm_meter_reset' => 'yes'], $this->versionOf($hold));
        $this->assertSame('done', $result->outcome, $result->message);
        $this->assertSame('resolved', $hold->fresh()->status);
        $this->assertSame('300.0', (string) $this->boat->fresh()->current_engine_hours);
        $this->assertSame(1, $this->decisions($hold));
        $this->assertSame('accepted', $this->inspection->fresh()->processing_status);
    }

    public function test_asking_the_member_twice_is_one_request_and_a_stale_meter_is_a_conflict(): void
    {
        $hold = $this->hold('miles');
        $admin = $this->personas()['admin'];
        $version = $this->versionOf($hold);

        $this->assertSame('done', $this->source->perform($admin, (string) $hold->id, 'ask_member', ['reason' => 'Please recheck the odometer.'], $version)->outcome);
        $this->assertSame('done', $this->source->perform($admin, (string) $hold->id, 'ask_member', ['reason' => 'Please recheck the odometer.'], $version)->outcome);
        $this->assertSame('revision_requested', $hold->fresh()->status);
        $this->assertSame(1, $this->decisions($hold));

        $other = $this->hold('engine_hours');
        $stale = $this->versionOf($other);
        $this->boat->forceFill(['current_engine_hours' => 410])->save();
        $this->assertSame('conflict', $this->source->perform($admin, (string) $other->id, 'dismiss', ['reason' => 'Typo.'], $stale)->outcome);
        $this->assertSame('open', $other->fresh()->status);
        $this->assertSame(0, $this->decisions($other));
    }

    public function test_an_equipment_finding_hold_is_classified_and_inspection_level_holds_are_read_only(): void
    {
        $defect = ApparatusDefect::query()->create([
            'apparatus_id' => $this->boat->id, 'apparatus_inspection_id' => $this->inspection->id, 'compartment' => 'Deck',
            'item' => 'Throw bag', 'status' => 'open', 'issue_type' => 'missing', 'reported_date' => '2026-10-30',
        ]);
        $hold = $this->hold('defect:'.$defect->id, attributes: ['submitted_value' => null, 'baseline_value' => null, 'authoritative_value' => null, 'reason' => 'equipment_finding', 'metadata' => ['defect_id' => $defect->id]]);
        $flag = $this->hold('member_review_flag', attributes: ['submitted_value' => null, 'reason' => 'member_flagged', 'blocks_acceptance' => true]);
        $admin = $this->personas()['admin'];

        $result = $this->source->perform($admin, (string) $hold->id, 'accept', ['operational_impact' => 'needs_repair', 'reason' => 'Order a new bag.'], $this->versionOf($hold));
        $this->assertSame('done', $result->outcome, $result->message);
        $this->assertSame('needs_repair', $defect->fresh()->operational_impact);
        $this->assertSame('resolved', $hold->fresh()->status);

        $detail = $this->source->detail($admin, (string) $flag->id);
        $this->assertSame([], $detail->actions);
        $this->assertStringContainsString('approving or rejecting the inspection', (string) $detail->readOnlyReason);
        $this->assertSame('invalid', $this->source->perform($admin, (string) $flag->id, 'dismiss', ['reason' => 'Fine.'], $detail->issue->version)->outcome);
        $this->assertSame('open', $flag->fresh()->status);
    }

    public function test_counts_match_rows_and_decisions_follow_the_operational_day_across_dst(): void
    {
        $open = $this->hold();
        $significant = $this->hold('significant_damage', attributes: ['submitted_value' => null, 'blocks_acceptance' => true]);
        $before = $this->hold('miles', 'dismissed');
        $this->inspection = $this->inspection();
        $at = $this->hold('miles', 'resolved');
        ApparatusInspectionException::query()->whereKey($before->id)->toBase()->update(['updated_at' => CarbonImmutable::parse('2026-11-01 07:59:59', 'America/New_York')->utc()]);
        ApparatusInspectionException::query()->whereKey($at->id)->toBase()->update(['updated_at' => CarbonImmutable::parse('2026-11-01 08:00:00', 'America/New_York')->utc()]);
        $test = $this->inspection();
        $test->forceFill(['test_excluded_at' => now()])->save();
        ApparatusInspectionException::query()->create([...$open->only(['apparatus_id', 'field', 'reason', 'status']), 'apparatus_inspection_id' => $test->id]);
        $admin = $this->personas()['admin'];
        $tags = ['rescue', 'engine_puc', 'ladder', 'marine', 'special'];

        $query = $this->boardQuery('2026-11-01 08:00:00');
        $rows = $this->allRows($this->source, $admin, $query);
        $this->assertSame([(string) $significant->id, (string) $open->id, (string) $at->id], array_map(static fn ($row): string => $row->id, $rows));
        $this->assertSame($this->countsFromRows($rows, $tags), array_intersect_key($this->source->counts($admin, $query), array_flip(['open', 'awaiting_response', 'in_progress', 'high', 'resolved_today', ...$tags])));
        $this->assertSame(['open' => 2, 'awaiting_response' => 2, 'high' => 1, 'resolved_today' => 1], array_intersect_key($this->source->counts($admin, $query), array_flip(['open', 'awaiting_response', 'high', 'resolved_today'])));
        $this->assertContains((string) $before->id, array_map(static fn ($row): string => $row->id, $this->allRows($this->source, $admin, $this->boardQuery('2026-11-01 07:59:00'))));
    }

    public function test_fifty_holds_load_in_a_bounded_number_of_queries(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $this->inspection = $this->inspection();
            $this->hold($i % 2 === 0 ? 'engine_hours' : 'miles');
        }
        $admin = $this->personas()['admin'];
        $this->source->rows($admin, $this->boardQuery(), 1);

        $queries = $this->queriesDuring(function () use ($admin): void {
            $this->assertCount(50, $this->source->rows($admin, $this->boardQuery(tags: ['marine']), 50));
            $this->source->counts($admin, $this->boardQuery(tags: ['marine']));
        });
        $this->assertLessThanOrEqual(12, $queries);
    }
}
