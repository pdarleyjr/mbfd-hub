<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboards;

use App\Filament\Resources\StationInspectionResource\Pages\ViewStationInspection;
use App\Models\StationInspection;
use App\Models\User;
use App\Services\Dashboards\Sources\Station\StationInspectionSource;
use App\Support\Dashboards\DashboardQuery;
use App\Support\OperationalDisplayWindow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

final class StationInspectionSourceTest extends TestCase
{
    use RefreshDatabase;
    use StationDashboardFixtures;

    private function source(): StationInspectionSource
    {
        return app(StationInspectionSource::class);
    }

    private function inspection(string $overall = 'fail', ?User $inspector = null, int $station = 2): StationInspection
    {
        return StationInspection::query()->create([
            'station_id' => $this->station($station)->id,
            'inspector_id' => ($inspector ?? User::factory()->create(['name' => 'Ivy Inspector']))->id,
            'inspection_date' => now()->toDateString(),
            'inspection_type' => 'monthly',
            'form_data' => ['items' => []],
            'overall_status' => $overall,
            'notes' => 'Extinguisher tag expired.',
        ])->refresh();
    }

    public function test_pending_reviews_are_rows_with_station_image_and_severity(): void
    {
        $viewer = $this->adminWith(['admin.stations.view']);
        $failed = $this->inspection('fail');
        $this->inspection('pass', station: 7);

        $rows = collect($this->source()->rows($viewer, DashboardQuery::now(), 50))->keyBy('id');

        $this->assertCount(2, $rows);
        $this->assertSame('high', $rows[$failed->id]->severity);
        $this->assertSame('station:2', $rows[$failed->id]->imageKey);
        $this->assertSame(['facilities'], $rows[$failed->id]->tags);
        $this->assertSame('awaiting_response', $rows[$failed->id]->stage);
        $this->assertSame('Ivy Inspector', $rows[$failed->id]->actorLabel);
        $this->assertNull($this->source()->rows($viewer, DashboardQuery::now(displayMode: true), 50)[0]->actorLabel);
        $this->assertSame([], $this->source()->rows($this->adminWith(['admin.fleet.view']), DashboardQuery::now(), 50));
    }

    public function test_review_goes_through_the_review_service_is_idempotent_and_stale_reviews_conflict(): void
    {
        $manager = $this->adminWith(['admin.stations.view', 'admin.stations.manage']);
        $second = $this->adminWith(['admin.stations.view', 'admin.stations.manage']);
        $inspection = $this->inspection();
        $detail = $this->source()->detail($manager, (string) $inspection->id);
        $this->assertSame(['review', 'needs_follow_up'], array_map(fn ($a) => $a->key, $detail->actions));

        $this->assertSame('invalid', $this->source()->perform($manager, (string) $inspection->id, 'needs_follow_up', [], $detail->issue->version)->outcome);
        $this->assertSame('done', $this->source()->perform($manager, (string) $inspection->id, 'review', ['review_note' => 'Tag replaced.'], $detail->issue->version)->outcome);
        $this->assertSame('done', $this->source()->perform($manager, (string) $inspection->id, 'review', ['review_note' => 'Tag replaced.'], $detail->issue->version)->outcome);
        $this->assertSame('conflict', $this->source()->perform($second, (string) $inspection->id, 'needs_follow_up', ['review_note' => 'Check again'], $detail->issue->version)->outcome);

        $fresh = $inspection->fresh();
        $this->assertSame('reviewed', $fresh->review_status);
        $this->assertSame($manager->id, (int) $fresh->reviewed_by);
        $this->assertSame('Tag replaced.', $fresh->review_note);
        $this->assertSame('This inspection has been reviewed. A review decision is final.', $this->source()->detail($manager, (string) $inspection->id)->readOnlyReason);

        $counts = $this->source()->counts($manager, DashboardQuery::now());
        $this->assertSame(0, $counts['open']);
        $this->assertSame(1, $counts['resolved_today']);
        $this->assertSame(1, $counts['facilities']);

        $this->actingAs($manager);
        Livewire::test(ViewStationInspection::class, ['record' => $inspection->id])->assertActionHidden('acknowledgeInspection');
    }

    public function test_a_viewer_cannot_review(): void
    {
        $viewer = $this->adminWith(['admin.stations.view']);
        $inspection = $this->inspection();
        $detail = $this->source()->detail($viewer, (string) $inspection->id);

        $this->assertSame([], $detail->actions);
        $this->assertSame('forbidden', $this->source()->perform($viewer, (string) $inspection->id, 'review', [], $detail->issue->version)->outcome);
        $this->assertSame('pending_review', $inspection->fresh()->review_status);
    }

    public function test_counts_match_rows_around_the_eight_oclock_boundary(): void
    {
        $viewer = $this->adminWith(['admin.stations.view']);
        $this->inspection('needs_attention');
        $reviewed = $this->inspection('pass');
        DB::table('station_inspections')->where('id', $reviewed->id)->update(['review_status' => 'reviewed', 'reviewed_at' => '2026-11-01 12:59:59']);
        // 2026-11-01: clocks fall back; 08:00 EST is 13:00 UTC.
        $query = new DashboardQuery(OperationalDisplayWindow::forNow('2026-11-01 10:00:00'));

        $this->assertCount(1, $this->source()->rows($viewer, $query, 50));
        $this->assertSame(['open' => 1, 'awaiting_response' => 1, 'high' => 0, 'resolved_today' => 0, 'facilities' => 1, 'supplies' => 0, 'access_it' => 0, 'other' => 0], $this->source()->counts($viewer, $query));

        DB::table('station_inspections')->where('id', $reviewed->id)->update(['reviewed_at' => '2026-11-01 13:00:00']);
        $this->assertCount(2, $this->source()->rows($viewer, $query, 50));
        $this->assertSame(1, $this->source()->counts($viewer, $query)['resolved_today']);
    }

    public function test_rows_use_a_fixed_number_of_queries_for_fifty_inspections(): void
    {
        $viewer = $this->adminWith(['admin.stations.view']);
        $inspector = User::factory()->create();
        $this->inspection(inspector: $inspector);
        $this->source()->rows($viewer, DashboardQuery::now(), 50);
        $small = $this->queriesFor(fn () => $this->source()->rows($viewer, DashboardQuery::now(), 50));
        foreach (range(1, 49) as $i) {
            $this->inspection('pass', User::factory()->create(), $i % 6 + 1);
        }

        $large = $this->queriesFor(fn () => $this->assertCount(50, $this->source()->rows($viewer, DashboardQuery::now(), 50)));

        $this->assertSame($small, $large);
        $this->assertLessThanOrEqual(5, $large, 'Two permission checks plus inspections, stations and inspectors.');
    }
}
