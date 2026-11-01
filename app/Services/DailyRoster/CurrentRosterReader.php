<?php

declare(strict_types=1);

namespace App\Services\DailyRoster;

use App\Models\DailyRoster\RosterActiveVersion;
use App\Models\DailyRoster\RosterAssignment;
use App\Models\DailyRoster\RosterSection;
use App\Models\DailyRoster\RosterSnapshot;
use App\Support\Captain5\DutyCalendar;
use Carbon\CarbonInterface;

/**
 * The only way consumers read the published roster. A read follows one pointer to one immutable
 * snapshot, so it always sees a whole export, never a half-imported sheet.
 */
final class CurrentRosterReader
{
    public function __construct(private readonly DutyCalendar $calendar) {}

    /** The roster for the duty day containing $at, or null. Never yesterday's. */
    public function current(?CarbonInterface $at = null): ?RosterView
    {
        return $this->forDutyDate($this->calendar->dutyDate($at)->format('Y-m-d'));
    }

    public function forDutyDate(string $dutyDate): ?RosterView
    {
        $pointer = RosterActiveVersion::query()->whereDate('duty_date', $dutyDate)->first();
        if ($pointer === null || $pointer->roster_snapshot_id === null) {
            return null;
        }

        return $this->view($pointer);
    }

    /** The newest roster before the duty day containing $at. For stale display only, never for command cards. */
    public function latestBefore(CarbonInterface $at): ?RosterView
    {
        $pointer = RosterActiveVersion::query()
            ->whereNotNull('roster_snapshot_id')
            ->whereDate('duty_date', '<', $this->calendar->dutyDate($at)->format('Y-m-d'))
            ->orderByDesc('duty_date')
            ->first();

        return $pointer === null ? null : $this->view($pointer);
    }

    /**
     * Every section carrying this role on the duty day's roster, in sheet order. A role is meant to be
     * on one section, but the export is not ours, so consumers must see when it is on two.
     *
     * @return list<RosterSectionView>
     */
    public function sectionsForRole(?CarbonInterface $at, string $role): array
    {
        $pointer = RosterActiveVersion::query()
            ->whereDate('duty_date', $this->calendar->dutyDate($at)->format('Y-m-d'))
            ->first();
        if ($pointer === null || $pointer->roster_snapshot_id === null) {
            return [];
        }

        $sections = RosterSection::query()
            ->where('roster_snapshot_id', $pointer->roster_snapshot_id)
            ->where('section_role', $role)
            ->orderBy('position')
            ->get();
        if ($sections->isEmpty()) {
            return [];
        }

        $assignments = RosterAssignment::query()
            ->whereIn('roster_section_id', $sections->modelKeys())
            ->orderBy('position')
            ->get()
            ->groupBy('roster_section_id');

        return $sections
            ->map(static fn (RosterSection $section): RosterSectionView => new RosterSectionView($section, array_values($assignments->get($section->id)?->all() ?? [])))
            ->values()
            ->all();
    }

    /**
     * Employee IDs on working rows in this seat on one stored roster. Absences, annotations and codes the
     * Hub does not know never count.
     *
     * @return list<string>
     */
    public function workingEmployeeIds(int $snapshotId, string $role): array
    {
        return RosterAssignment::query()
            ->where('roster_snapshot_id', $snapshotId)
            ->where('kind', RosterAssignment::KIND_EMPLOYEE)
            ->where('availability', RosterAssignment::AVAILABILITY_WORKING)
            ->whereNotNull('employee_id')
            ->whereIn('roster_section_id', RosterSection::query()->select('id')->where('roster_snapshot_id', $snapshotId)->where('section_role', $role))
            ->pluck('employee_id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->unique()
            ->values()
            ->all();
    }

    /** @return list<string> Duty dates with a published roster, newest first */
    public function availableDutyDates(int $limit = 14): array
    {
        return RosterActiveVersion::query()
            ->whereNotNull('roster_snapshot_id')
            ->orderByDesc('duty_date')
            ->limit($limit)
            ->get(['duty_date'])
            ->map(static fn (RosterActiveVersion $pointer): string => substr((string) $pointer->duty_date, 0, 10))
            ->values()
            ->all();
    }

    /** One stored version, whether or not it is showing: what an open page has, to compare with what is current. */
    public function forSnapshot(RosterSnapshot $snapshot): RosterView
    {
        return new RosterView($snapshot, $this->sections($snapshot), null);
    }

    private function view(RosterActiveVersion $pointer): ?RosterView
    {
        $snapshot = RosterSnapshot::query()->find($pointer->roster_snapshot_id);
        if ($snapshot === null) {
            return null;
        }

        return new RosterView(
            $snapshot,
            $this->sections($snapshot),
            $pointer->last_confirmed_at,
            $pointer->updated_at?->toImmutable(),
            $pointer->content_changed_at,
        );
    }

    /** @return list<RosterSectionView> */
    private function sections(RosterSnapshot $snapshot): array
    {
        $assignments = RosterAssignment::query()
            ->where('roster_snapshot_id', $snapshot->id)
            ->orderBy('position')
            ->get()
            ->groupBy('roster_section_id');
        $sections = [];
        foreach (RosterSection::query()->where('roster_snapshot_id', $snapshot->id)->orderBy('position')->get() as $section) {
            $sections[] = new RosterSectionView($section, array_values($assignments->get($section->id)?->all() ?? []));
        }

        return $sections;
    }
}
