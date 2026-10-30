<?php

declare(strict_types=1);

namespace Tests\Feature\DailyRoster\Pipeline;

use App\Models\DailyRoster\RosterActiveVersion;
use App\Models\DailyRoster\RosterAssignment;
use App\Models\DailyRoster\RosterEvent;
use App\Models\DailyRoster\RosterImport;
use App\Models\DailyRoster\RosterSection;
use App\Models\DailyRoster\RosterSnapshot;
use App\Services\DailyRoster\CurrentRosterReader;
use App\Services\DailyRoster\Parsing\TeleStaffRosterReport;
use App\Services\DailyRoster\RosterFreshness;
use App\Services\DailyRoster\RosterFreshnessResolver;
use App\Services\DailyRoster\RosterPublisher;
use App\Services\DailyRoster\RosterStateRecorder;
use App\Support\Captain5\DutyCalendar;
use App\Support\HubTimezone;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Feature\DailyRoster\Pipeline\Support\InteractsWithRosterPipeline;
use Tests\Feature\DailyRoster\Pipeline\Support\RosterPipelineFixtures;
use Tests\TestCase;

final class RosterPublisherTest extends TestCase
{
    use InteractsWithRosterPipeline, RefreshDatabase;

    private const DATE = '2026-10-30';

    protected function setUp(): void
    {
        parent::setUp();
        $this->preparePipeline();
    }

    public function test_it_writes_the_whole_snapshot_with_seats_filled_down(): void
    {
        $import = $this->publishFresh($report = RosterPipelineFixtures::report(self::DATE));
        $snapshot = RosterSnapshot::query()->sole();

        self::assertSame((int) $import->id, (int) $snapshot->roster_import_id);
        self::assertSame(1, (int) $snapshot->version);
        self::assertSame($report->shift, $snapshot->shift_letter);
        self::assertSame($report->normalizedSha256(), $snapshot->normalized_sha256);
        self::assertSame(4, (int) $snapshot->section_count);
        self::assertSame(7, (int) $snapshot->assignment_count);
        self::assertSame(6, (int) $snapshot->unique_employee_count);
        [$start, $end] = app(DutyCalendar::class)->window(self::DATE);
        self::assertTrue($snapshot->starts_at->equalTo($start));
        self::assertTrue($snapshot->ends_at->equalTo($end));

        $sections = RosterSection::query()->orderBy('position')->get();
        self::assertSame([0, 1, 2, 3], $sections->pluck('position')->map(static fn ($value): int => (int) $value)->all());
        self::assertSame(RosterSection::ROLE_COMMAND_300, $sections[0]->section_role);
        self::assertSame(RosterSection::ROLE_CAPTAIN_5, $sections[1]->section_role);
        self::assertNull($sections[2]->section_role);
        self::assertSame(4, (int) $sections[2]->entry_count);

        $engine = RosterAssignment::query()->where('roster_section_id', $sections[2]->id)->orderBy('position')->get();
        self::assertSame([0, 1, 2, 3], $engine->pluck('position')->map(static fn ($value): int => (int) $value)->all());
        self::assertSame([0, 0, 1, 2], $engine->pluck('seat_position')->map(static fn ($value): int => (int) $value)->all());
        self::assertSame(['Lieutenant', 'Lieutenant', 'Firefighter', 'Firefighter'], $engine->pluck('seat_rank')->all());
        self::assertSame(['Lieutenant', null, 'Firefighter', 'Firefighter'], $engine->pluck('rank_label')->all());
        self::assertTrue($engine[1]->is_continuation);
        self::assertSame(RosterAssignment::KIND_VACANCY_SUPPRESSED, $engine[3]->kind);
        self::assertNull($engine[3]->employee_id);
        self::assertSame(12.5, $engine[3]->reported_hours);
        self::assertSame(['CERT1', 'CERT2'], RosterAssignment::query()->where('employee_id', '90006')->sole()->certifications);

        $pointer = $this->pointerFor(self::DATE);
        self::assertSame((int) $snapshot->id, (int) $pointer->roster_snapshot_id);
        self::assertTrue($pointer->effective_generated_at->equalTo($report->generatedAt));
        self::assertTrue($pointer->last_confirmed_at->equalTo($report->generatedAt));

        self::assertSame(1, RosterEvent::query()->where('action', RosterStateRecorder::ACTION_POINTER_MOVED)->count());
        self::assertSame(RosterImport::STATE_PUBLISHED, $import->state);
    }

    public function test_a_failure_part_way_through_the_insert_leaves_the_previous_roster_showing(): void
    {
        $first = $this->publishFresh(RosterPipelineFixtures::report(self::DATE));
        $before = $this->pointerFor(self::DATE)->toArray();

        // 250 rows make three insert chunks. The second chunk fails after the first has landed.
        $import = $this->validatedImport();
        $chunks = 0;
        DB::listen(static function ($query) use (&$chunks): void {
            if (str_contains($query->sql, 'insert into "roster_assignments"') && ++$chunks === 2) {
                throw new RuntimeException('connection lost');
            }
        });

        try {
            app(RosterPublisher::class)->publish($import, RosterPipelineFixtures::report(self::DATE, [
                'generated_at' => '2026-10-30 13:25:00', 'pad' => 250,
            ]));
            self::fail('The injected failure should have escaped publish.');
        } catch (RuntimeException $exception) {
            self::assertSame('connection lost', $exception->getMessage());
        }

        self::assertSame(2, $chunks);
        self::assertSame($before, $this->pointerFor(self::DATE)->toArray());
        self::assertSame(1, RosterSnapshot::query()->count());
        self::assertSame(7, RosterAssignment::query()->count());
        self::assertSame(RosterImport::STATE_VALIDATED, $import->fresh()->state);
        self::assertSame((int) $first->roster_snapshot_id, (int) app(CurrentRosterReader::class)->current()->snapshot->id);
    }

    public function test_replaying_a_publish_changes_nothing(): void
    {
        $import = $this->validatedImport();
        $report = RosterPipelineFixtures::report(self::DATE);
        $publisher = app(RosterPublisher::class);

        $publisher->publish($import, $report);
        $eventCount = RosterEvent::query()->count();
        $pointer = $this->pointerFor(self::DATE)->toArray();
        $publisher->publish($import, $report);
        $publisher->publish($import->fresh(), $report);

        self::assertSame(1, RosterSnapshot::query()->count());
        self::assertSame(7, RosterAssignment::query()->count());
        self::assertSame($eventCount, RosterEvent::query()->count());
        self::assertSame($pointer, $this->pointerFor(self::DATE)->toArray());
        self::assertSame(RosterImport::STATE_PUBLISHED, $import->fresh()->state);
    }

    public function test_the_same_roster_confirms_and_moves_time_forward_only(): void
    {
        $first = $this->publishFresh(RosterPipelineFixtures::report(self::DATE, ['generated_at' => '2026-10-30 13:10:00']));
        $again = $this->publishFresh(RosterPipelineFixtures::report(self::DATE, ['generated_at' => '2026-10-30 13:20:00']));

        self::assertSame(RosterImport::STATE_DUPLICATE, $again->state);
        self::assertSame(RosterImport::OUTCOME_CONFIRMED, $again->outcome);
        self::assertSame((int) $first->id, (int) $again->duplicate_of_import_id);
        self::assertSame((int) $first->roster_snapshot_id, (int) $again->roster_snapshot_id);
        self::assertSame(1, RosterSnapshot::query()->count());

        $pointer = $this->pointerFor(self::DATE);
        self::assertTrue($pointer->last_confirmed_at->equalTo('2026-10-30 13:20:00'));
        self::assertTrue($pointer->effective_generated_at->equalTo('2026-10-30 13:20:00'));

        $older = $this->publishFresh(RosterPipelineFixtures::report(self::DATE, ['generated_at' => '2026-10-30 13:05:00']));
        self::assertSame(RosterImport::OUTCOME_CONFIRMED, $older->outcome);
        self::assertTrue($this->pointerFor(self::DATE)->last_confirmed_at->equalTo('2026-10-30 13:20:00'));
    }

    public function test_an_export_older_than_a_later_confirmation_cannot_replace_it(): void
    {
        $this->publishFresh(RosterPipelineFixtures::report(self::DATE, ['generated_at' => '2026-10-30 13:10:00']));
        $this->publishFresh(RosterPipelineFixtures::report(self::DATE, ['generated_at' => '2026-10-30 13:20:00']));

        $between = $this->publishFresh(RosterPipelineFixtures::report(self::DATE, ['generated_at' => '2026-10-30 13:15:00', 'variant' => 'B']));

        self::assertSame(RosterImport::STATE_SUPERSEDED, $between->state);
    }

    public function test_content_seen_before_re_points_without_a_new_version_when_newer(): void
    {
        $original = $this->publishFresh(RosterPipelineFixtures::report(self::DATE, ['generated_at' => '2026-10-30 13:05:00']));
        $this->publishFresh(RosterPipelineFixtures::report(self::DATE, ['generated_at' => '2026-10-30 13:10:00', 'variant' => 'B']));

        $back = $this->publishFresh(RosterPipelineFixtures::report(self::DATE, ['generated_at' => '2026-10-30 13:15:00']));

        self::assertSame(RosterImport::STATE_PUBLISHED, $back->state);
        self::assertSame((int) $original->roster_snapshot_id, (int) $back->roster_snapshot_id);
        self::assertSame(2, RosterSnapshot::query()->count());
        self::assertSame((int) $original->roster_snapshot_id, (int) $this->pointerFor(self::DATE)->roster_snapshot_id);
        self::assertTrue($this->pointerFor(self::DATE)->effective_generated_at->equalTo('2026-10-30 13:15:00'));
    }

    public function test_old_content_seen_before_stays_unpointed_without_a_new_version(): void
    {
        $original = $this->publishFresh(RosterPipelineFixtures::report(self::DATE, ['generated_at' => '2026-10-30 13:05:00']));
        $newer = $this->publishFresh(RosterPipelineFixtures::report(self::DATE, ['generated_at' => '2026-10-30 13:10:00', 'variant' => 'B']));

        $late = $this->publishFresh(RosterPipelineFixtures::report(self::DATE, ['generated_at' => '2026-10-30 13:02:00']));

        self::assertSame(RosterImport::STATE_SUPERSEDED, $late->state);
        self::assertSame((int) $original->roster_snapshot_id, (int) $late->roster_snapshot_id);
        self::assertSame(2, RosterSnapshot::query()->count());
        self::assertSame((int) $newer->roster_snapshot_id, (int) $this->pointerFor(self::DATE)->roster_snapshot_id);
    }

    public function test_a_missing_export_time_counts_as_lower_than_the_effective_time(): void
    {
        $first = $this->publishFresh(RosterPipelineFixtures::report(self::DATE));
        $undated = $this->publishFresh(RosterPipelineFixtures::report(self::DATE, ['generated_at' => null, 'variant' => 'B']));

        self::assertSame(RosterImport::STATE_SUPERSEDED, $undated->state);
        self::assertSame((int) $first->roster_snapshot_id, (int) $this->pointerFor(self::DATE)->roster_snapshot_id);
    }

    public function test_a_first_export_with_no_time_is_held_rather_than_shown(): void
    {
        $import = $this->publishFresh(RosterPipelineFixtures::report(self::DATE, ['generated_at' => null]));

        self::assertSame(RosterImport::STATE_NEEDS_REVIEW, $import->state);
        self::assertSame(RosterPublisher::HOLD_GENERATED_MISSING, $import->error_code);
        self::assertNull(app(CurrentRosterReader::class)->current());
    }

    public function test_a_newer_passing_export_supersedes_older_held_ones_and_keeps_their_files(): void
    {
        $generated = '2026-10-30 13:10:00';
        $first = $this->publishFresh(RosterPipelineFixtures::report(self::DATE, ['generated_at' => $generated]));
        $held = $this->publishFresh(RosterPipelineFixtures::report(self::DATE, ['generated_at' => $generated, 'variant' => 'B']));
        self::assertSame(RosterImport::STATE_NEEDS_REVIEW, $held->state);

        // Also on the ledger: an older export whose publish happened and whose read-back failed. That one needs a person.
        $unverified = RosterPipelineFixtures::import([
            'state' => RosterImport::STATE_NEEDS_REVIEW,
            'duty_date' => self::DATE,
            'source_generated_at' => $generated,
            'published_at' => $generated,
            'roster_snapshot_id' => $first->roster_snapshot_id,
            'error_code' => 'verification_hash_mismatch',
        ]);

        $winner = $this->publishFresh(RosterPipelineFixtures::report(self::DATE, ['generated_at' => '2026-10-30 13:20:00', 'variant' => 'C']));

        self::assertSame(RosterImport::STATE_PUBLISHED, $winner->state);
        $held = $held->fresh();
        self::assertSame(RosterImport::STATE_SUPERSEDED, $held->state);
        self::assertSame(RosterImport::OUTCOME_SUPERSEDED, $held->outcome);
        self::assertSame((int) $winner->id, (int) $held->duplicate_of_import_id);
        self::assertTrue(Storage::disk('local')->exists($held->attachment_path));
        self::assertSame(RosterImport::STATE_NEEDS_REVIEW, $unverified->fresh()->state);
    }

    public function test_a_held_export_for_another_date_or_a_newer_time_is_left_alone(): void
    {
        $otherDate = RosterPipelineFixtures::import(['state' => RosterImport::STATE_NEEDS_REVIEW, 'duty_date' => '2026-10-29', 'source_generated_at' => '2026-10-29 13:00:00']);
        $newerHeld = RosterPipelineFixtures::import(['state' => RosterImport::STATE_NEEDS_REVIEW, 'duty_date' => self::DATE, 'source_generated_at' => '2026-10-30 13:29:00']);

        $this->publishFresh(RosterPipelineFixtures::report(self::DATE));

        self::assertSame(RosterImport::STATE_NEEDS_REVIEW, $otherDate->fresh()->state);
        self::assertSame(RosterImport::STATE_NEEDS_REVIEW, $newerHeld->fresh()->state);
    }

    public function test_a_future_date_gets_its_own_pointer_and_becomes_current_only_at_0800(): void
    {
        $today = $this->publishFresh(RosterPipelineFixtures::report(self::DATE));
        $tomorrow = $this->publishFresh(RosterPipelineFixtures::report('2026-10-31'));
        $reader = app(CurrentRosterReader::class);

        self::assertSame(RosterImport::STATE_PUBLISHED, $tomorrow->state);
        self::assertSame((int) $today->roster_snapshot_id, (int) $reader->current()->snapshot->id);
        self::assertSame((int) $tomorrow->roster_snapshot_id, (int) $reader->forDutyDate('2026-10-31')->snapshot->id);

        // 07:59 Eastern on the 31st is still the 30th's duty day; 08:00 is the 31st's.
        $this->travelTo(CarbonImmutable::parse('2026-10-31 07:59:59', HubTimezone::NAME));
        self::assertSame((int) $today->roster_snapshot_id, (int) $reader->current()->snapshot->id);
        $this->travelTo(CarbonImmutable::parse('2026-10-31 08:00:00', HubTimezone::NAME));
        self::assertSame((int) $tomorrow->roster_snapshot_id, (int) $reader->current()->snapshot->id);
    }

    public function test_a_past_date_only_touches_its_own_pointer(): void
    {
        $today = $this->publishFresh(RosterPipelineFixtures::report(self::DATE));
        $pointer = $this->pointerFor(self::DATE)->toArray();

        $past = $this->publishFresh(RosterPipelineFixtures::report('2026-10-28', ['generated_at' => '2026-10-30 13:29:00']));

        self::assertSame(RosterImport::STATE_PUBLISHED, $past->state);
        self::assertSame($pointer, $this->pointerFor(self::DATE)->toArray());
        self::assertSame((int) $today->roster_snapshot_id, (int) app(CurrentRosterReader::class)->current()->snapshot->id);
        self::assertSame((int) $past->roster_snapshot_id, (int) $this->pointerFor('2026-10-28')->roster_snapshot_id);
    }

    public function test_the_25_hour_dst_duty_day_publishes_with_the_calendar_window(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-31 13:30:00', 'UTC'));
        $import = $this->publishFresh(RosterPipelineFixtures::report('2026-10-31'));
        $snapshot = $import->snapshot;

        self::assertSame(25.0, $snapshot->starts_at->diffInHours($snapshot->ends_at));
        self::assertSame('2026-10-31 12:00:00', $snapshot->starts_at->format('Y-m-d H:i:s'));
        self::assertSame('2026-11-01 13:00:00', $snapshot->ends_at->format('Y-m-d H:i:s'));

        $reader = app(CurrentRosterReader::class);
        $this->travelTo(CarbonImmutable::parse('2026-11-01 12:59:00', 'UTC'));
        self::assertSame((int) $snapshot->id, (int) $reader->current()?->snapshot->id, 'The extra hour still belongs to the 31st.');
        $this->travelTo(CarbonImmutable::parse('2026-11-01 13:00:00', 'UTC'));
        self::assertNull($reader->current());
    }

    public function test_a_pinned_date_holds_every_export_without_alerting(): void
    {
        $first = $this->publishFresh(RosterPipelineFixtures::report(self::DATE));
        RosterActiveVersion::query()->whereDate('duty_date', self::DATE)->update(['pinned_at' => now()]);

        $held = $this->publishFresh(RosterPipelineFixtures::report(self::DATE, ['generated_at' => '2026-10-30 13:25:00', 'variant' => 'B']));
        $sameContent = $this->publishFresh(RosterPipelineFixtures::report(self::DATE, ['generated_at' => '2026-10-30 13:26:00']));

        self::assertSame(RosterPublisher::HOLD_PINNED, $held->error_code);
        self::assertSame(RosterImport::STATE_NEEDS_REVIEW, $sameContent->state, 'The pin is checked before anything else.');
        self::assertSame((int) $first->roster_snapshot_id, (int) $this->pointerFor(self::DATE)->roster_snapshot_id);
        Exceptions::assertNothingReported();
    }

    public function test_a_read_through_the_reader_takes_at_most_four_queries(): void
    {
        $this->publishFresh(RosterPipelineFixtures::report(self::DATE));
        $reader = app(CurrentRosterReader::class);

        DB::enableQueryLog();
        DB::flushQueryLog();
        $view = $reader->forDutyDate(self::DATE);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        self::assertNotNull($view);
        self::assertSame(4, count($view->sections));
        self::assertLessThanOrEqual(4, $queries);
    }

    public function test_freshness_reads_the_published_roster_as_current(): void
    {
        $this->publishFresh(RosterPipelineFixtures::report(self::DATE));

        self::assertSame(RosterFreshness::CURRENT, app(RosterFreshnessResolver::class)->status()->state);
    }

    private function validatedImport(): RosterImport
    {
        return RosterPipelineFixtures::import([
            'state' => RosterImport::STATE_VALIDATED,
            'duty_date' => self::DATE,
            'lease_expires_at' => now()->addMinutes(5),
        ]);
    }

    private function publishFresh(TeleStaffRosterReport $report): RosterImport
    {
        $import = RosterPipelineFixtures::import([
            'state' => RosterImport::STATE_VALIDATED,
            'duty_date' => $report->dutyDate,
            'source_generated_at' => $report->generatedAt,
            'lease_expires_at' => now()->addMinutes(5),
        ]);
        app(RosterPublisher::class)->publish($import, $report);

        return $import->fresh();
    }
}
