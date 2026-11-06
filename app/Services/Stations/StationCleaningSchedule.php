<?php

declare(strict_types=1);

namespace App\Services\Stations;

use App\Models\Station;
use App\Models\StationCleaningTask;
use App\Support\HubTimezone;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * What a station cleans on a given day, derived from the schedule configuration at read time. Nothing is
 * generated or written per day, so there is no job to miss and an admin edit shows on the next read.
 *
 * Rules (also documented on the migration):
 *   - Focus: the day's rotating assignment. A date-specific focus replaces the weekday focus for that date.
 *     A station's own row wins over the department row at the same level. At most one focus is shown.
 *   - Extras: every enabled additional task for the weekday or the date, department-wide and this station's.
 *   - Baseline: the every-day duties; a station's own baseline replaces the department's.
 *
 * Not cached: the table holds a few dozen rows and is read with one indexed query, so there is nothing to
 * invalidate when an admin saves.
 */
final class StationCleaningSchedule
{
    public const SOURCE_NOTE = 'Station Clean Up Schedule (department document pages 85–86)';

    /**
     * @return array{
     *     date: string,
     *     weekday: string,
     *     date_label: string,
     *     focus: array{title: string, instructions: list<string>, date_exception: bool, station_specific: bool}|null,
     *     note: string|null,
     *     extras: list<array{title: string, instructions: list<string>, date_exception: bool, station_specific: bool}>,
     *     baseline: array{title: string, instructions: list<string>}|null,
     *     source: string
     * }
     */
    public function forStation(Station $station, ?CarbonImmutable $date = null): array
    {
        $day = $this->calendarDay($date);
        $weekday = $day->dayOfWeekIso;
        $weekdayName = StationCleaningTask::WEEKDAYS[$weekday];
        $tasks = $this->tasksFor($station);
        $dateKey = $day->toDateString();

        $onDate = $tasks->filter(fn (StationCleaningTask $task): bool => $task->on_date?->format('Y-m-d') === $dateKey);
        $onWeekday = $tasks->filter(fn (StationCleaningTask $task): bool => $task->on_date === null && $task->weekday === $weekday);
        $everyDay = $tasks->filter(fn (StationCleaningTask $task): bool => $task->on_date === null && $task->weekday === null);

        $focus = $this->preferred($onDate, StationCleaningTask::KIND_FOCUS) ?? $this->preferred($onWeekday, StationCleaningTask::KIND_FOCUS);
        $baseline = $this->preferred($everyDay, StationCleaningTask::KIND_BASELINE);
        $extras = $onDate->merge($onWeekday)
            ->filter(fn (StationCleaningTask $task): bool => $task->kind === StationCleaningTask::KIND_EXTRA)
            ->sortBy([['sort_order', 'asc'], ['id', 'asc']])
            ->values();

        return [
            'date' => $dateKey,
            'weekday' => $weekdayName,
            'date_label' => $day->format('l, F j'),
            'focus' => $focus === null ? null : $this->entry($focus),
            'note' => $focus === null ? "No rotating assignment on {$weekdayName} in the department schedule." : null,
            'extras' => $extras->map(fn (StationCleaningTask $task): array => $this->entry($task))->all(),
            'baseline' => $baseline === null ? null : ['title' => $baseline->title, 'instructions' => $baseline->instructionLines()],
            'source' => self::SOURCE_NOTE,
        ];
    }

    /** The Eastern calendar date: today by default, otherwise the written date of the given value. */
    public function calendarDay(?CarbonImmutable $date = null): CarbonImmutable
    {
        return $date === null
            ? HubTimezone::today()
            : CarbonImmutable::parse($date->format('Y-m-d'), 'UTC')->startOfDay();
    }

    /** @return Collection<int, StationCleaningTask> enabled rows for every station plus this station's own */
    private function tasksFor(Station $station): Collection
    {
        $number = StationCleaningTask::normalizeStationNumber($station->station_number);

        return StationCleaningTask::query()
            ->where('is_enabled', true)
            ->where(fn ($query) => $number === null
                ? $query->whereNull('station_number')
                : $query->whereNull('station_number')->orWhere('station_number', $number))
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    /**
     * The station's own row of this kind if there is one, otherwise the department's.
     *
     * @param  Collection<int, StationCleaningTask>  $tasks  already ordered by sort order
     */
    private function preferred(Collection $tasks, string $kind): ?StationCleaningTask
    {
        $ofKind = $tasks->filter(fn (StationCleaningTask $task): bool => $task->kind === $kind);

        return $ofKind->first(fn (StationCleaningTask $task): bool => $task->station_number !== null)
            ?? $ofKind->first();
    }

    /** @return array{title: string, instructions: list<string>, date_exception: bool, station_specific: bool} */
    private function entry(StationCleaningTask $task): array
    {
        return [
            'title' => $task->title,
            'instructions' => $task->instructionLines(),
            'date_exception' => $task->on_date !== null,
            'station_specific' => $task->station_number !== null,
        ];
    }
}
