<?php

declare(strict_types=1);

namespace App\Services\DailyRoster;

use App\Jobs\DailyRoster\CleanupRosterImport;
use App\Models\DailyRoster\RosterActiveVersion;
use App\Models\DailyRoster\RosterAssignment;
use App\Models\DailyRoster\RosterEvent;
use App\Models\DailyRoster\RosterImport;
use App\Models\DailyRoster\RosterSection;
use App\Models\DailyRoster\RosterSnapshot;
use App\Support\Captain5\DutyCalendar;
use Carbon\CarbonImmutable;

/**
 * Proves a publish by reading it back the way members do. Only a roster that reads back whole lets its
 * email be deleted; anything else stays for review with the raw file kept. Safe to run again.
 */
final class RosterPublicationVerifier
{
    public const POINTER_MISSING = 'verification_pointer_missing';

    public const POINTER_MISMATCH = 'verification_pointer_mismatch';

    public const SNAPSHOT_MISSING = 'verification_snapshot_missing';

    public const COUNT_MISMATCH = 'verification_count_mismatch';

    public const HASH_MISMATCH = 'verification_hash_mismatch';

    public const WINDOW_MISMATCH = 'verification_window_mismatch';

    public const EVENT_MISSING = 'verification_event_missing';

    public function __construct(
        private readonly CurrentRosterReader $reader,
        private readonly DutyCalendar $calendar,
        private readonly RosterStateRecorder $recorder,
    ) {}

    public function verify(RosterImport $import): bool
    {
        $current = RosterImport::query()->find($import->getKey());
        if ($current === null) {
            return false;
        }
        if ($current->state !== RosterImport::STATE_PUBLISHED) {
            return in_array($current->state, [RosterImport::STATE_VERIFIED, RosterImport::STATE_CLEANED], true);
        }

        $problem = $this->firstProblem($current);

        try {
            if ($problem !== null) {
                $this->recorder->transition($current, RosterImport::STATE_NEEDS_REVIEW, ['error_code' => $problem], 'verification_failed', ['reasons' => [$problem]]);
                report(new RosterPipelineException($problem));

                return false;
            }

            $this->recorder->transition($current, RosterImport::STATE_VERIFIED, ['verified_at' => CarbonImmutable::now('UTC')], 'read_back_matched');
        } catch (RosterPipelineException $exception) {
            if (! $exception->isConflict()) {
                throw $exception;
            }

            return RosterImport::query()->whereKey($current->getKey())->whereIn('state', [RosterImport::STATE_VERIFIED, RosterImport::STATE_CLEANED])->exists();
        }

        CleanupRosterImport::dispatchIfEnabled((int) $current->getKey());

        return true;
    }

    private function firstProblem(RosterImport $import): ?string
    {
        $dutyDate = $import->duty_date?->format('Y-m-d');
        $view = $dutyDate === null ? null : $this->reader->forDutyDate($dutyDate);
        if ($view === null) {
            return self::POINTER_MISSING;
        }

        $isOwn = (int) $view->snapshot->getKey() === (int) $import->roster_snapshot_id;
        if (! $isOwn && ! $this->newerExportWon($import, $dutyDate)) {
            return self::POINTER_MISMATCH;
        }

        $problem = $this->snapshotProblem($view->snapshot, $view->sections);
        if ($problem !== null) {
            return $problem;
        }

        if (! $isOwn) {
            // Its own snapshot still has to be whole, because it stays available for rollback.
            $own = RosterSnapshot::query()->find($import->roster_snapshot_id);
            $problem = $own === null ? self::SNAPSHOT_MISSING : $this->snapshotProblem($own, $this->sectionViews($own));
            if ($problem !== null) {
                return $problem;
            }
        }

        $hasEvent = RosterEvent::query()
            ->where('roster_import_id', $import->getKey())
            ->where('action', RosterStateRecorder::ACTION_POINTER_MOVED)
            ->exists();

        return $hasEvent ? null : self::EVENT_MISSING;
    }

    private function newerExportWon(RosterImport $import, string $dutyDate): bool
    {
        $effective = RosterActiveVersion::query()->whereDate('duty_date', $dutyDate)->first()?->effective_generated_at;

        return $effective !== null && $import->source_generated_at !== null
            && $effective->greaterThanOrEqualTo($import->source_generated_at);
    }

    /** @param list<RosterSectionView> $sections */
    private function snapshotProblem(RosterSnapshot $snapshot, array $sections): ?string
    {
        $assignmentCount = array_sum(array_map(static fn (RosterSectionView $section): int => count($section->assignments), $sections));
        if (count($sections) !== (int) $snapshot->section_count || $assignmentCount !== (int) $snapshot->assignment_count) {
            return self::COUNT_MISMATCH;
        }

        $dutyDate = $snapshot->duty_date->format('Y-m-d');
        [$start, $end] = $this->calendar->window($dutyDate);
        if ($snapshot->shift_letter !== $this->calendar->shiftFor($dutyDate)
            || ! $snapshot->starts_at->equalTo($start) || ! $snapshot->ends_at->equalTo($end)) {
            return self::WINDOW_MISMATCH;
        }

        return hash_equals($snapshot->normalized_sha256, self::normalizedHash($dutyDate, (string) $snapshot->shift_letter, $sections))
            ? null
            : self::HASH_MISMATCH;
    }

    /** @return list<RosterSectionView> */
    private function sectionViews(RosterSnapshot $snapshot): array
    {
        $assignments = RosterAssignment::query()->where('roster_snapshot_id', $snapshot->getKey())->orderBy('position')->get()->groupBy('roster_section_id');
        $views = [];
        foreach (RosterSection::query()->where('roster_snapshot_id', $snapshot->getKey())->orderBy('position')->get() as $section) {
            $views[] = new RosterSectionView($section, array_values($assignments->get($section->getKey())?->all() ?? []));
        }

        return $views;
    }

    /**
     * The same canonical structure as TeleStaffRosterReport::normalizedSha256(), rebuilt from stored rows.
     *
     * @param  list<RosterSectionView>  $sections
     */
    public static function normalizedHash(string $dutyDate, string $shift, array $sections): string
    {
        $canonical = [];
        foreach ($sections as $view) {
            $seats = [];
            $seatPosition = null;
            foreach ($view->assignments as $row) {
                if ((int) $row->seat_position !== $seatPosition) {
                    $seatPosition = (int) $row->seat_position;
                    $seats[] = [$row->seat_rank, []];
                }
                $seats[array_key_last($seats)][1][] = [
                    $row->kind, $row->employee_id, $row->raw_name, $row->raw_code,
                    $row->raw_from, $row->raw_through, (float) $row->reported_hours,
                ];
            }
            $canonical[] = [$view->section->raw_title, $seats];
        }

        return hash('sha256', json_encode([$dutyDate, $shift, $canonical], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }
}
