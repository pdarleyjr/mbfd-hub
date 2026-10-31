<?php

declare(strict_types=1);

namespace Tests\Feature\DailyRoster\Pipeline;

use App\Models\DailyRoster\RosterActiveVersion;
use App\Models\DailyRoster\RosterAssignment;
use App\Models\DailyRoster\RosterEvent;
use App\Models\DailyRoster\RosterImport;
use App\Models\DailyRoster\RosterSection;
use App\Models\DailyRoster\RosterSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Feature\DailyRoster\Pipeline\Support\InteractsWithRosterPipeline;
use Tests\Feature\DailyRoster\Pipeline\Support\RosterPipelineFixtures;
use Tests\Support\DailyRosterSnapshotFactory;
use Tests\TestCase;

/** What keeps the ledger from growing without limit once exports arrive every few minutes. */
final class RosterRetentionTest extends TestCase
{
    use InteractsWithRosterPipeline;
    use RefreshDatabase;

    private const DATE = '2026-10-30';

    public function test_it_is_scheduled_daily_on_one_server_without_overlap(): void
    {
        $event = collect(app(Schedule::class)->events())->first(static fn ($event): bool => str_contains((string) $event->command, 'daily-roster:prune'));

        self::assertNotNull($event);
        self::assertTrue($event->withoutOverlapping);
        self::assertTrue($event->onOneServer);
    }

    public function test_old_exports_that_changed_nothing_are_removed_with_their_history(): void
    {
        $this->preparePipeline();
        $old = $this->cleanedExport(RosterImport::OUTCOME_CONFIRMED, days: 31);
        $recent = $this->cleanedExport(RosterImport::OUTCOME_CONFIRMED, days: 5);

        Artisan::call('daily-roster:prune');

        self::assertNull(RosterImport::query()->find($old->getKey()));
        self::assertSame(0, RosterEvent::query()->where('roster_import_id', $old->getKey())->count());
        self::assertSame(0, RosterEvent::query()->whereNull('roster_import_id')->where('action', 'test_event')->count());
        self::assertNotNull(RosterImport::query()->find($recent->getKey()));
        self::assertSame(1, RosterEvent::query()->where('roster_import_id', $recent->getKey())->count());
    }

    public function test_only_finished_rows_are_removed(): void
    {
        $this->preparePipeline();
        $kept = [];
        foreach ([
            RosterImport::STATE_NEEDS_REVIEW, RosterImport::STATE_REJECTED, RosterImport::STATE_PERMANENT_FAILED,
            RosterImport::STATE_DUPLICATE, RosterImport::STATE_VERIFIED, RosterImport::STATE_SHADOWED,
        ] as $state) {
            $kept[] = $this->cleanedExport(RosterImport::OUTCOME_CONFIRMED, days: 400, state: $state);
        }

        Artisan::call('daily-roster:prune');

        foreach ($kept as $row) {
            self::assertNotNull(RosterImport::query()->find($row->getKey()), $row->state.' must stay');
        }
    }

    public function test_a_row_that_published_a_roster_stays_as_the_record_of_a_change(): void
    {
        $this->preparePipeline();
        $published = $this->cleanedExport(RosterImport::OUTCOME_PUBLISHED, days: 400);

        Artisan::call('daily-roster:prune');

        self::assertNotNull(RosterImport::query()->find($published->getKey()));
    }

    public function test_replaced_versions_of_a_roster_are_removed_after_their_window_and_the_showing_one_never_is(): void
    {
        $this->preparePipeline();
        $this->travelTo(CarbonImmutable::parse('2026-09-01 13:00:00', 'UTC'));
        $replaced = DailyRosterSnapshotFactory::publish('2026-09-01', ['Station 1 / Engine 1' => [['employee_id' => '50001', 'name' => 'REPLACED, PERSON']]]);
        $showing = DailyRosterSnapshotFactory::publish('2026-09-01', ['Station 1 / Engine 1' => [['employee_id' => '50002', 'name' => 'SHOWING, PERSON']]]);
        $importId = $replaced->roster_import_id;

        $this->travelTo(CarbonImmutable::parse('2026-10-30 13:00:00', 'UTC'));
        Artisan::call('daily-roster:prune');

        self::assertNull(RosterSnapshot::query()->find($replaced->getKey()));
        self::assertSame(0, RosterSection::query()->where('roster_snapshot_id', $replaced->getKey())->count());
        self::assertSame(0, RosterAssignment::query()->where('roster_snapshot_id', $replaced->getKey())->count());
        self::assertNotNull(RosterSnapshot::query()->find($showing->getKey()));
        self::assertSame(1, RosterAssignment::query()->where('roster_snapshot_id', $showing->getKey())->count());
        self::assertSame((int) $showing->getKey(), (int) RosterActiveVersion::query()->value('roster_snapshot_id'));

        $ledger = RosterImport::query()->find($importId);
        self::assertNotNull($ledger, 'The record that a change happened outlives the people in it.');
        self::assertNull($ledger->roster_snapshot_id);
    }

    public function test_a_recently_replaced_version_stays_so_it_can_still_be_rolled_back_to(): void
    {
        $this->preparePipeline();
        $replaced = DailyRosterSnapshotFactory::publish(self::DATE, ['Station 1 / Engine 1' => [['employee_id' => '50001', 'name' => 'REPLACED, PERSON']]]);
        DailyRosterSnapshotFactory::publish(self::DATE, ['Station 1 / Engine 1' => [['employee_id' => '50002', 'name' => 'SHOWING, PERSON']]]);

        $this->travel(20)->days();
        Artisan::call('daily-roster:prune');

        self::assertNotNull(RosterSnapshot::query()->find($replaced->getKey()));
    }

    public function test_dry_run_counts_and_changes_nothing(): void
    {
        $this->preparePipeline();
        $old = $this->cleanedExport(RosterImport::OUTCOME_CONFIRMED, days: 31);

        self::assertSame(0, Artisan::call('daily-roster:prune', ['--dry-run' => true]));
        self::assertStringContainsString('unchanged_exports=1', Artisan::output());
        self::assertNotNull(RosterImport::query()->find($old->getKey()));
    }

    public function test_a_large_backlog_is_worked_in_bounded_batches(): void
    {
        $this->preparePipeline();
        config(['daily_roster.retention.batch' => 10, 'daily_roster.retention.max_batches' => 2]);
        for ($row = 0; $row < 35; $row++) {
            $this->cleanedExport(RosterImport::OUTCOME_CONFIRMED, days: 31);
        }

        Artisan::call('daily-roster:prune');
        self::assertSame(15, RosterImport::query()->count());

        Artisan::call('daily-roster:prune');
        self::assertSame(0, RosterImport::query()->count());
    }

    public function test_output_holds_counts_only(): void
    {
        $this->preparePipeline();
        DailyRosterSnapshotFactory::publish(self::DATE, ['Station 1 / Engine 1' => [['employee_id' => '50001', 'name' => 'SECRET, PERSON']]]);

        Artisan::call('daily-roster:prune');

        self::assertStringNotContainsString('SECRET', Artisan::output());
        self::assertStringNotContainsString('50001', Artisan::output());
    }

    private function cleanedExport(string $outcome, int $days, string $state = RosterImport::STATE_CLEANED): RosterImport
    {
        $when = CarbonImmutable::now('UTC')->subDays($days);
        $import = RosterPipelineFixtures::import([
            'state' => $state,
            'state_changed_at' => $when,
            'received_at' => $when,
            'outcome' => $outcome,
            'cleanup_state' => $state === RosterImport::STATE_CLEANED ? RosterImport::CLEANUP_DONE : RosterImport::CLEANUP_NONE,
        ]);
        RosterEvent::query()->create(['roster_import_id' => $import->getKey(), 'action' => 'test_event', 'created_at' => $when]);

        return $import;
    }
}
