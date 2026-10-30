<?php

declare(strict_types=1);

namespace App\Services\DailyRoster;

use App\Models\DailyRoster\RosterActiveVersion;
use App\Models\DailyRoster\RosterAssignment;
use App\Models\DailyRoster\RosterImport;
use App\Models\DailyRoster\RosterSection;
use App\Models\DailyRoster\RosterSnapshot;
use App\Services\DailyRoster\Parsing\TeleStaffRosterEntry;
use App\Services\DailyRoster\Parsing\TeleStaffRosterReport;
use App\Support\Captain5\DutyCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Decides whether a parsed export is safe to publish without a person looking at it. Absolute checks
 * apply to every export including the first; relative checks compare with what was published before.
 * Vacancies, ordinary count changes, a single unknown code and a duplicate employee are normal staffing,
 * so they never hold. Returns reason codes only.
 */
final class RosterAnomalyGuard
{
    public const SHIFT_MISMATCH = 'shift_calendar_mismatch';

    public const MISSING_300 = 'section_missing_command_300';

    public const MISSING_CAPTAIN_5 = 'section_missing_captain_5';

    public const NO_ENTRIES = 'no_entries';

    public const GENERATED_MISSING = 'generated_missing';

    public const GENERATED_AFTER_RECEIVED = 'generated_after_received';

    public const DUTY_DATE_OUT_OF_RANGE = 'duty_date_out_of_range';

    public const SECTION_RETENTION_LOW = 'section_retention_low';

    public const ASSIGNMENTS_LOW = 'assignment_count_low';

    public const ASSIGNMENTS_HIGH = 'assignment_count_high';

    public const UNKNOWN_CODES_HIGH = 'unknown_codes_high';

    public const LAYOUT_CHANGED = 'layout_changed';

    public function __construct(private readonly DutyCalendar $calendar) {}

    /** @return list<string> Hold reasons, most fundamental first; empty when the export may publish */
    public function check(RosterImport $import, TeleStaffRosterReport $report): array
    {
        return [...$this->absoluteReasons($import, $report), ...$this->relativeReasons($import, $report)];
    }

    /** @return list<string> */
    private function absoluteReasons(RosterImport $import, TeleStaffRosterReport $report): array
    {
        $reasons = [];
        if ($this->calendar->shiftFor($report->dutyDate) !== $report->shift) {
            $reasons[] = self::SHIFT_MISMATCH;
        }

        $roles = array_map(static fn ($section): ?string => RosterPublisher::sectionRole($section->unit), $report->sections);
        if (! in_array(RosterSection::ROLE_COMMAND_300, $roles, true)) {
            $reasons[] = self::MISSING_300;
        }
        if (! in_array(RosterSection::ROLE_CAPTAIN_5, $roles, true)) {
            $reasons[] = self::MISSING_CAPTAIN_5;
        }
        if ($report->assignmentCount() < 1) {
            $reasons[] = self::NO_ENTRIES;
        }

        if ($report->generatedAt === null) {
            $reasons[] = self::GENERATED_MISSING;
        } elseif (CarbonImmutable::parse($report->generatedAt)->greaterThan($import->received_at->addMinutes(
            (int) config('daily_roster.publication.max_generated_after_received_minutes', 10),
        ))) {
            $reasons[] = self::GENERATED_AFTER_RECEIVED;
        }

        return $this->isDutyDateInRange($report->dutyDate) ? $reasons : [...$reasons, self::DUTY_DATE_OUT_OF_RANGE];
    }

    private function isDutyDateInRange(string $dutyDate): bool
    {
        // Both ends are UTC midnights, so a DST Sunday cannot make a day count fractional.
        $today = CarbonImmutable::createFromFormat('!Y-m-d', $this->calendar->dutyDate()->format('Y-m-d'), 'UTC');
        $target = CarbonImmutable::createFromFormat('!Y-m-d', $dutyDate, 'UTC');
        $days = (int) $today->diffInDays($target, false);

        return $days <= (int) config('daily_roster.publication.max_days_ahead', 14)
            && $days >= -(int) config('daily_roster.publication.max_days_back', 7);
    }

    /** @return list<string> */
    private function relativeReasons(RosterImport $import, TeleStaffRosterReport $report): array
    {
        $baseline = $this->baseline($report->dutyDate, (int) $import->getKey());
        if ($baseline->isEmpty()) {
            return [];
        }

        /** @var RosterSnapshot $previous */
        $previous = $baseline->first();
        $reasons = [];
        if ($this->sectionRetentionIsLow($previous, $report)) {
            $reasons[] = self::SECTION_RETENTION_LOW;
        }

        $count = $report->assignmentCount();
        $median = (float) $baseline->median('assignment_count');
        if ($median > 0) {
            $percent = $count * 100 / $median;
            if ($percent < (int) config('daily_roster.publication.min_assignment_percent_of_median', 50)) {
                $reasons[] = self::ASSIGNMENTS_LOW;
            } elseif ($percent > (int) config('daily_roster.publication.max_assignment_percent_of_median', 200)) {
                $reasons[] = self::ASSIGNMENTS_HIGH;
            }
        }

        if ($this->unknownCodesAreHigh($report, $count)) {
            $reasons[] = self::UNKNOWN_CODES_HIGH;
        }
        if ($previous->layout_fingerprint !== $report->layoutFingerprint && ! $this->isAttested($import)) {
            $reasons[] = self::LAYOUT_CHANGED;
        }

        return $reasons;
    }

    /** An administrator accepted this export, so a new layout becomes the baseline instead of holding it again. */
    private function isAttested(RosterImport $import): bool
    {
        return in_array(RosterImportOperations::ATTESTED, (array) ($import->classification['reasons'] ?? []), true);
    }

    /**
     * Published snapshots to compare with, newest first: the pointer of each duty date up to this one,
     * or the nearest later dates when this export is older than everything published. Stored but never
     * shown versions do not count, so a bad export cannot become the yardstick.
     *
     * @return Collection<int, RosterSnapshot>
     */
    private function baseline(string $dutyDate, int $importId): Collection
    {
        $window = max(1, (int) config('daily_roster.publication.median_window', 6));
        $pointers = RosterActiveVersion::query()->whereNotNull('roster_snapshot_id')->limit($window);

        $snapshotIds = (clone $pointers)->whereDate('duty_date', '<=', $dutyDate)->orderByDesc('duty_date')->pluck('roster_snapshot_id');
        if ($snapshotIds->isEmpty()) {
            $snapshotIds = (clone $pointers)->whereDate('duty_date', '>', $dutyDate)->orderBy('duty_date')->pluck('roster_snapshot_id');
        }

        return RosterSnapshot::query()
            ->whereIn('id', $snapshotIds)
            ->where('roster_import_id', '!=', $importId)
            ->orderByDesc('duty_date')
            ->orderByDesc('version')
            ->get();
    }

    private function sectionRetentionIsLow(RosterSnapshot $previous, TeleStaffRosterReport $report): bool
    {
        $previousUnits = RosterSection::query()->where('roster_snapshot_id', $previous->getKey())->pluck('unit_label')
            ->map(static fn (string $unit): string => trim($unit))->unique();
        if ($previousUnits->isEmpty()) {
            return false;
        }

        $units = array_map(static fn ($section): string => trim($section->unit), $report->sections);
        $retained = $previousUnits->filter(static fn (string $unit): bool => in_array($unit, $units, true))->count();

        return $retained * 100 / $previousUnits->count() < (int) config('daily_roster.publication.min_section_retention_percent', 75);
    }

    /** One unknown code displays verbatim and raises a row-level reason. It never holds the roster. */
    private function unknownCodesAreHigh(TeleStaffRosterReport $report, int $count): bool
    {
        $unknown = 0;
        foreach ($report->sections as $section) {
            foreach ($section->seats as $seat) {
                $unknown += count(array_filter(
                    $seat->entries,
                    static fn (TeleStaffRosterEntry $entry): bool => $entry->availability === RosterAssignment::AVAILABILITY_UNKNOWN,
                ));
            }
        }

        return $unknown > 1 && $unknown * 100 / max(1, $count) >= (int) config('daily_roster.publication.max_unknown_code_percent', 25);
    }
}
