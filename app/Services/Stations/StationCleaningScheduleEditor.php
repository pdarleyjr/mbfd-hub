<?php

declare(strict_types=1);

namespace App\Services\Stations;

use App\Models\Station;
use App\Models\StationCleaningTask;
use App\Models\StationCleaningTaskRevision;
use App\Models\User;
use App\Support\Stations\StationCleaningAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The admin side of the station cleaning schedule, opened from a station profile. It reads the editable rows into
 * one form state and writes changes back, one revision per changed row, inside a single transaction.
 *
 * Form state:
 *   weekly[1..7]  the department focus for each weekday (all stations)
 *   baseline      the department every-day duties (all stations)
 *   tasks[]       this station's own tasks, plus additional tasks and date exceptions for all stations
 *
 * Rows are never deleted; an admin turns one off instead. Every write is authorized here, whatever screen sent it.
 */
final class StationCleaningScheduleEditor
{
    public const APPLIES_STATION = 'station';

    public const APPLIES_ALL = 'all';

    public const WHEN_WEEKDAY = 'weekday';

    public const WHEN_DATE = 'date';

    /** @return array{weekly: array<int, array<string, mixed>>, baseline: array<string, mixed>, tasks: list<array<string, mixed>>} */
    public function formState(Station $station): array
    {
        $department = StationCleaningTask::query()->whereNull('station_number')->whereNull('on_date')->orderBy('sort_order')->orderBy('id')->get();
        $weekly = [];
        foreach (array_keys(StationCleaningTask::WEEKDAYS) as $weekday) {
            $row = $department->first(fn (StationCleaningTask $task): bool => $task->kind === StationCleaningTask::KIND_FOCUS && $task->weekday === $weekday);
            $weekly[$weekday] = $this->fixedState($row);
        }
        $baseline = $department->first(fn (StationCleaningTask $task): bool => $task->kind === StationCleaningTask::KIND_BASELINE && $task->weekday === null);

        return [
            'weekly' => $weekly,
            'baseline' => $this->fixedState($baseline),
            'tasks' => $this->listedTasks($station)->map(fn (StationCleaningTask $task): array => $this->taskState($task))->values()->all(),
        ];
    }

    /**
     * Apply the submitted form state. Returns how many rows were created or changed.
     *
     * @param  array<string, mixed>  $state
     *
     * @throws AuthorizationException when the actor may not manage the schedule
     * @throws ValidationException when the state is incomplete or names a row this screen does not own
     */
    public function save(User $actor, Station $station, array $state): int
    {
        if (! StationCleaningAccess::canManage($actor)) {
            throw new AuthorizationException('You do not have permission to change the station cleaning schedule.');
        }
        $stationNumber = StationCleaningTask::normalizeStationNumber($station->station_number);
        $rows = $this->desiredRows($state, $stationNumber);

        return DB::transaction(function () use ($actor, $station, $rows): int {
            $owned = $this->editableTasks($station)->keyBy('id');
            $changes = 0;
            foreach ($rows as $row) {
                $changes += $this->apply($actor, $row, $owned) ? 1 : 0;
            }

            return $changes;
        });
    }

    /**
     * Every row this station's screen may edit: all department rows and this station's own. Another station's rows
     * are never reachable from here, whatever ids a request carries.
     *
     * @return Collection<int, StationCleaningTask>
     */
    public function editableTasks(Station $station): Collection
    {
        $number = StationCleaningTask::normalizeStationNumber($station->station_number);

        return StationCleaningTask::query()
            ->where(fn ($query) => $query
                ->whereNull('station_number')
                ->when($number !== null, fn ($inner) => $inner->orWhere('station_number', $number)))
            ->orderByRaw('CASE WHEN on_date IS NULL THEN 0 ELSE 1 END')
            ->orderBy('weekday')
            ->orderBy('on_date')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    /**
     * The rows listed under "Station tasks and date exceptions": this station's own rows, plus department-wide
     * additional tasks and date exceptions. The department weekday focus and baseline have their own fields.
     *
     * @return Collection<int, StationCleaningTask>
     */
    public function listedTasks(Station $station): Collection
    {
        return $this->editableTasks($station)
            ->reject(fn (StationCleaningTask $task): bool => $task->station_number === null
                && $task->on_date === null
                && $task->kind !== StationCleaningTask::KIND_EXTRA)
            ->values();
    }

    /** @return array<string, mixed> */
    private function fixedState(?StationCleaningTask $task): array
    {
        return [
            'id' => $task?->id,
            'title' => $task->title ?? '',
            'instructions' => $task->instructions ?? '',
            'is_enabled' => $task->is_enabled ?? true,
        ];
    }

    /** @return array<string, mixed> */
    private function taskState(StationCleaningTask $task): array
    {
        return [
            'id' => $task->id,
            'applies_to' => $task->station_number === null ? self::APPLIES_ALL : self::APPLIES_STATION,
            'kind' => $task->kind,
            'when' => $task->on_date === null ? self::WHEN_WEEKDAY : self::WHEN_DATE,
            'weekday' => $task->weekday,
            'on_date' => $task->on_date?->format('Y-m-d'),
            'title' => $task->title,
            'instructions' => $task->instructions ?? '',
            'is_enabled' => $task->is_enabled,
        ];
    }

    /**
     * Validate the state and turn it into the attributes each row should have.
     *
     * @param  array<string, mixed>  $state
     * @return list<array{id: int|null, attributes: array<string, mixed>}>
     */
    private function desiredRows(array $state, ?string $stationNumber): array
    {
        $state['tasks'] = array_values((array) ($state['tasks'] ?? []));
        $validator = Validator::make($state, [
            'weekly' => ['required', 'array'],
            'weekly.*.id' => ['nullable', 'integer'],
            'weekly.*.title' => ['nullable', 'string', 'max:'.StationCleaningTask::TITLE_MAX],
            'weekly.*.instructions' => ['nullable', 'string', 'max:'.StationCleaningTask::INSTRUCTIONS_MAX],
            'weekly.*.is_enabled' => ['boolean'],
            'baseline' => ['required', 'array'],
            'baseline.id' => ['nullable', 'integer'],
            'baseline.title' => ['nullable', 'string', 'max:'.StationCleaningTask::TITLE_MAX],
            'baseline.instructions' => ['nullable', 'string', 'max:'.StationCleaningTask::INSTRUCTIONS_MAX],
            'baseline.is_enabled' => ['boolean'],
            'tasks.*.id' => ['nullable', 'integer'],
            'tasks.*.applies_to' => ['required', Rule::in([self::APPLIES_STATION, self::APPLIES_ALL])],
            'tasks.*.kind' => ['required', Rule::in([StationCleaningTask::KIND_FOCUS, StationCleaningTask::KIND_EXTRA])],
            'tasks.*.when' => ['required', Rule::in([self::WHEN_WEEKDAY, self::WHEN_DATE])],
            'tasks.*.weekday' => ['nullable', 'required_if:tasks.*.when,'.self::WHEN_WEEKDAY, 'integer', 'between:1,7'],
            'tasks.*.on_date' => ['nullable', 'required_if:tasks.*.when,'.self::WHEN_DATE, 'date_format:Y-m-d'],
            'tasks.*.title' => ['required', 'string', 'max:'.StationCleaningTask::TITLE_MAX],
            'tasks.*.instructions' => ['nullable', 'string', 'max:'.StationCleaningTask::INSTRUCTIONS_MAX],
            'tasks.*.is_enabled' => ['boolean'],
        ], [
            'tasks.*.title.required' => 'Every task needs a title.',
            'tasks.*.weekday.required_if' => 'Choose the weekday for each weekly task.',
            'tasks.*.on_date.required_if' => 'Choose the date for each one-date task.',
        ]);
        $validator->after(function ($validator) use ($state, $stationNumber): void {
            foreach ($state['tasks'] as $index => $task) {
                if (($task['applies_to'] ?? null) === self::APPLIES_STATION && $stationNumber === null) {
                    $validator->errors()->add("tasks.{$index}.applies_to", 'This station has no station number, so it cannot have its own tasks.');
                }
                if (($task['applies_to'] ?? null) === self::APPLIES_ALL && ($task['kind'] ?? null) === StationCleaningTask::KIND_FOCUS && ($task['when'] ?? null) === self::WHEN_WEEKDAY) {
                    $validator->errors()->add("tasks.{$index}.kind", 'Change the weekly focus for all stations in the Weekly schedule section.');
                }
            }
            foreach ((array) $state['weekly'] as $weekday => $day) {
                if (filled($day['id'] ?? null) && blank($day['title'] ?? null)) {
                    $validator->errors()->add("weekly.{$weekday}.title", 'A saved weekday needs a title. Turn it off instead of clearing it.');
                }
            }
            if (filled($state['baseline']['id'] ?? null) && blank($state['baseline']['title'] ?? null)) {
                $validator->errors()->add('baseline.title', 'The every-day duties need a title. Turn them off instead of clearing them.');
            }
        });
        $valid = $validator->validate();

        $rows = [];
        foreach (array_keys(StationCleaningTask::WEEKDAYS) as $weekday) {
            $day = (array) ($valid['weekly'][$weekday] ?? []);
            if (blank($day['id'] ?? null) && blank($day['title'] ?? null)) {
                continue;
            }
            $rows[] = $this->row($day, ['station_number' => null, 'kind' => StationCleaningTask::KIND_FOCUS, 'weekday' => $weekday, 'on_date' => null]);
        }
        $baseline = (array) $valid['baseline'];
        if (filled($baseline['id'] ?? null) || filled($baseline['title'] ?? null)) {
            $rows[] = $this->row($baseline, ['station_number' => null, 'kind' => StationCleaningTask::KIND_BASELINE, 'weekday' => null, 'on_date' => null]);
        }
        foreach ((array) ($valid['tasks'] ?? []) as $task) {
            $byDate = $task['when'] === self::WHEN_DATE;
            $rows[] = $this->row($task, [
                'station_number' => $task['applies_to'] === self::APPLIES_STATION ? $stationNumber : null,
                'kind' => $task['kind'],
                'weekday' => $byDate ? null : (int) $task['weekday'],
                'on_date' => $byDate ? $task['on_date'] : null,
            ]);
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $scope
     * @return array{id: int|null, attributes: array<string, mixed>}
     */
    private function row(array $input, array $scope): array
    {
        $instructions = trim(str_replace("\r\n", "\n", (string) ($input['instructions'] ?? '')));

        return [
            'id' => filled($input['id'] ?? null) ? (int) $input['id'] : null,
            'attributes' => $scope + [
                'title' => trim((string) ($input['title'] ?? '')),
                'instructions' => $instructions === '' ? null : $instructions,
                'is_enabled' => (bool) ($input['is_enabled'] ?? true),
            ],
        ];
    }

    /**
     * @param  array{id: int|null, attributes: array<string, mixed>}  $row
     * @param  Collection<int, StationCleaningTask>  $owned
     */
    private function apply(User $actor, array $row, Collection $owned): bool
    {
        if ($row['id'] === null) {
            $task = new StationCleaningTask($row['attributes'] + ['sort_order' => 0, 'source' => null]);
            $task->updated_by_user_id = $actor->getKey();
            $task->save();
            $this->record($actor, $task, StationCleaningTaskRevision::ACTION_CREATED, null);

            return true;
        }

        $task = $owned->get($row['id']);
        if (! $task instanceof StationCleaningTask) {
            throw ValidationException::withMessages(['tasks' => 'One of these tasks belongs to another station or no longer exists. Reload the page and try again.']);
        }
        $before = $task->auditSnapshot();
        $task->fill($row['attributes']);
        if ($task->auditSnapshot() === $before) {
            return false;
        }
        $task->updated_by_user_id = $actor->getKey();
        $task->save();
        $this->record($actor, $task, StationCleaningTaskRevision::ACTION_UPDATED, $before);

        return true;
    }

    /** @param  array<string, mixed>|null  $before */
    private function record(User $actor, StationCleaningTask $task, string $action, ?array $before): void
    {
        StationCleaningTaskRevision::query()->create([
            'station_cleaning_task_id' => $task->id,
            'action' => $action,
            'before' => $before,
            'after' => $task->auditSnapshot(),
            'changed_by_user_id' => $actor->getKey(),
            'changed_by_name' => mb_substr((string) ($actor->display_name ?: $actor->name), 0, 160),
        ]);
    }
}
