<?php

declare(strict_types=1);

namespace App\Services\Personnel;

use App\Models\Employee;
use App\Models\PersonnelTable;
use App\Models\PersonnelTableEntry;
use App\Models\PersonnelTableEvent;
use App\Models\PersonnelTableView;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** @phpstan-import-type PersonnelTrackingColumn from PersonnelTable */
final class PersonnelTableService
{
    public const COLORS = ['gray', 'info', 'success', 'warning', 'danger'];

    public const TYPES = ['checkbox', 'text', 'notes', 'number', 'date', 'select', 'multiselect', 'status'];

    private const LEGACY_MANAGERS = ['admin.personnel.manage', 'admin.members.manage'];

    public function __construct(private readonly PersonnelSource $source) {}

    public function canViewDirectory(User $actor): bool
    {
        return $this->has($actor, ['personnel.directory.view', 'admin.personnel.view', 'admin.members.view', ...self::LEGACY_MANAGERS]);
    }

    public function canViewTables(User $actor): bool
    {
        return $this->has($actor, ['personnel.tables.view', 'admin.personnel.view', ...self::LEGACY_MANAGERS]);
    }

    public function canCreate(User $actor): bool
    {
        return $this->canViewTables($actor) && $this->has($actor, ['personnel.tables.create', ...self::LEGACY_MANAGERS]);
    }

    public function canArchive(User $actor, PersonnelTable $table): bool
    {
        $current = PersonnelTable::withTrashed()->find($table->getKey());

        return $current !== null && $current->directory_key === null && $this->canView($actor, $current)
            && $this->has($actor, ['personnel.tables.archive', ...self::LEGACY_MANAGERS]);
    }

    public function canShare(User $actor, PersonnelTable $table): bool
    {
        $current = PersonnelTable::withTrashed()->find($table->getKey());

        return $current !== null && $current->directory_key === null && $this->canView($actor, $current)
            && $this->has($actor, ['personnel.tables.share', ...self::LEGACY_MANAGERS]);
    }

    public function canExport(User $actor, PersonnelTable $table): bool
    {
        $current = PersonnelTable::withTrashed()->find($table->getKey());

        return $current !== null && ! $current->trashed() && $this->canView($actor, $current)
            && $this->has($actor, [$current->directory_key === 'master' ? 'personnel.directory.export' : 'personnel.tables.export', ...self::LEGACY_MANAGERS]);
    }

    public function canView(User $actor, PersonnelTable $table): bool
    {
        $current = PersonnelTable::withTrashed()->find($table->getKey());
        if ($current === null) {
            return false;
        }
        if ($current->directory_key === 'master') {
            return $this->canViewDirectory($actor);
        }

        return $this->canViewTables($actor) && $this->visibleTo($actor, $current);
    }

    public function canManage(User $actor, PersonnelTable $table): bool
    {
        $current = PersonnelTable::withTrashed()->find($table->getKey());
        if ($current === null || $current->trashed() || $current->archived_at !== null || ! $this->canView($actor, $current)) {
            return false;
        }

        return $this->has($actor, [$current->directory_key === 'master' ? 'personnel.directory.edit' : 'personnel.tables.edit', ...self::LEGACY_MANAGERS]);
    }

    public function authorize(User $actor, PersonnelTable $table, string $ability = 'view'): void
    {
        $table = PersonnelTable::withTrashed()->findOrFail($table->getKey());
        $allowed = $this->canView($actor, $table);
        if ($ability === 'manage' || $ability === 'edit') {
            $allowed = $this->canManage($actor, $table);
        } elseif ($ability === 'export') {
            $allowed = $this->canExport($actor, $table);
        } elseif ($ability === 'archive') {
            $allowed = $this->canArchive($actor, $table);
        } elseif ($ability === 'share') {
            $allowed = $this->canShare($actor, $table);
        } elseif ($ability === 'create') {
            $allowed = $this->canCreate($actor);
        } elseif ($ability !== 'view') {
            $allowed = false;
        }
        if (! $allowed) {
            throw new AuthorizationException;
        }
    }

    /** @return Builder<PersonnelTable> */
    public function accessibleQuery(User $actor): Builder
    {
        $query = PersonnelTable::query();
        if (! $this->canViewTables($actor)) {
            return $query->whereRaw('1 = 0');
        }
        $query->whereNull('directory_key');
        if (! $this->isSuperAdmin($actor)) {
            $query->where(fn (Builder $visible) => $visible->where('visibility', 'shared')->orWhere('created_by', $actor->getKey()));
        }

        return $query;
    }

    public function master(User $actor): PersonnelTable
    {
        if (! $this->canViewDirectory($actor)) {
            throw new AuthorizationException;
        }

        return PersonnelTable::query()->firstOrCreate(['directory_key' => 'master'], [
            'name' => 'Master Personnel Directory', 'created_by' => $actor->getKey(), 'visibility' => 'shared',
            'membership_mode' => 'dynamic', 'criteria' => ['active_only' => false],
            'source_columns' => ['name', 'employee_id', 'city_email', 'rank', 'shift', 'a_day', 'station', 'division', 'unit', 'bid_position', 'email_match_status'],
            'columns' => [], 'retired_columns' => [], 'version' => 1,
        ]);
    }

    /** @return Builder<Employee> */
    public function members(User $actor, PersonnelTable $table): Builder
    {
        $current = PersonnelTable::withTrashed()->findOrFail($table->getKey());
        $this->authorize($actor, $current);
        $query = $this->source->query();
        if ($current->directory_key === 'master') {
            return $query;
        }
        if ($current->membership_mode === 'dynamic') {
            return $this->source->filter($query, $current->criteria);
        }

        return $query->whereIn('employees.id', $current->entries()->whereNull('removed_at')->select('employee_id'));
    }

    public function create(User $actor, array $attributes): PersonnelTable
    {
        if (! $this->canCreate($actor)) {
            throw new AuthorizationException;
        }
        $data = $this->definition($attributes);
        if ($data['visibility'] === 'shared' && ! $this->has($actor, ['personnel.tables.share', ...self::LEGACY_MANAGERS])) {
            throw new AuthorizationException;
        }

        return DB::transaction(function () use ($actor, $data): PersonnelTable {
            if (! $this->canCreate($actor)) {
                throw new AuthorizationException;
            }
            $employees = $data['employee_ids'];
            unset($data['employee_ids']);
            $table = PersonnelTable::query()->create([...$data, 'created_by' => $actor->getKey(), 'retired_columns' => [], 'version' => 1]);
            if ($table->membership_mode === 'fixed') {
                $this->replaceMembers($table, $employees);
            }
            $this->event($actor, $table, 'created', ['fields' => ['definition', 'membership'], 'member_count' => count($employees)]);

            return $table->fresh();
        }, 3);
    }

    public function update(User $actor, PersonnelTable $table, array $attributes, int $expectedVersion): PersonnelTable
    {
        return DB::transaction(function () use ($actor, $table, $attributes, $expectedVersion): PersonnelTable {
            $current = $this->locked($table);
            $this->authorize($actor, $current, 'manage');
            $this->assertVersion($current->version, $expectedVersion);
            $data = $this->definition($attributes, $current);
            $previousColumns = collect([...$current->retired_columns, ...$current->columns])->keyBy('id');
            foreach ($data['columns'] as $column) {
                $previous = $previousColumns->get($column['id']);
                if ($previous !== null && $previous['type'] !== $column['type']
                    && $current->entries()->get(['values'])->contains(fn (PersonnelTableEntry $entry): bool => array_key_exists($column['id'], $entry->values))) {
                    throw ValidationException::withMessages(['columns' => 'A column with stored values must keep its type. Add a new column to use another type.']);
                }
            }
            if ($current->directory_key === 'master') {
                if (array_intersect(array_keys($attributes), ['name', 'visibility', 'membership_mode', 'criteria', 'employee_ids']) !== []) {
                    throw ValidationException::withMessages(['table' => 'The master directory always links the complete authoritative personnel roster.']);
                }
            } elseif ($data['visibility'] !== $current->visibility) {
                $this->authorize($actor, $current, 'share');
            }
            $retired = collect($current->retired_columns)->keyBy('id');
            $activeIds = array_column($data['columns'], 'id');
            foreach ($current->columns as $column) {
                if (! in_array($column['id'], $activeIds, true)) {
                    $retired->put($column['id'], $column);
                }
            }
            $retired = $retired->except($activeIds)->values()->all();
            if (count($retired) > 400) {
                throw ValidationException::withMessages(['columns' => 'This table has reached its retained-column limit. Create another table to keep tracking history recoverable.']);
            }
            $employees = $data['employee_ids'];
            unset($data['employee_ids']);
            $current->fill([...$data, 'retired_columns' => $retired, 'version' => $current->version + 1])->save();
            if (array_key_exists('employee_ids', $attributes)) {
                if ($current->membership_mode !== 'fixed') {
                    throw ValidationException::withMessages(['membership_mode' => 'Dynamic tables select members through their saved filters.']);
                }
                $this->replaceMembers($current, $employees);
            }
            $this->event($actor, $current, 'updated', ['fields' => array_keys($attributes)]);

            return $current->fresh();
        }, 3);
    }

    public function duplicate(User $actor, PersonnelTable $table, bool $includeData = false): PersonnelTable
    {
        return DB::transaction(function () use ($actor, $table, $includeData): PersonnelTable {
            $original = $this->locked($table);
            $this->authorize($actor, $original);
            if ($original->trashed()) {
                throw ValidationException::withMessages(['table' => 'Restore this table before duplicating it.']);
            }
            if ($includeData) {
                $this->authorize($actor, $original, 'export');
            }
            $map = [];
            $columns = [];
            foreach ($original->columns as $column) {
                $map[$column['id']] = (string) Str::uuid();
                $columns[] = [...$column, 'id' => $map[$column['id']]];
            }
            $ids = $this->members($actor, $original)->pluck('employees.id')->all();
            $copy = $this->create($actor, [
                'name' => mb_substr($original->name, 0, 140).' (copy)', 'description' => $original->description,
                'visibility' => 'private', 'membership_mode' => $original->membership_mode,
                'criteria' => $original->criteria, 'source_columns' => $original->source_columns,
                'columns' => $columns, 'employee_ids' => $original->membership_mode === 'fixed' ? $ids : [],
            ]);
            if ($includeData) {
                foreach ($original->entries()->whereIn('employee_id', $ids)->get() as $entry) {
                    $values = [];
                    foreach ($entry->values as $id => $value) {
                        if (isset($map[$id])) {
                            $values[$map[$id]] = $value;
                        }
                    }
                    $copy->entries()->updateOrCreate(['employee_id' => $entry->employee_id], ['values' => $values, 'highlight' => $entry->highlight, 'version' => 0]);
                }
            }
            $this->event($actor, $copy, 'duplicated', ['source_table_id' => $original->id, 'included_data' => $includeData]);

            return $copy->fresh();
        }, 3);
    }

    public function archive(User $actor, PersonnelTable $table, int $expectedVersion): PersonnelTable
    {
        return $this->lifecycle($actor, $table, $expectedVersion, 'archived');
    }

    public function restore(User $actor, PersonnelTable $table, int $expectedVersion): PersonnelTable
    {
        return $this->lifecycle($actor, $table, $expectedVersion, 'restored');
    }

    public function delete(User $actor, PersonnelTable $table, int $expectedVersion): PersonnelTable
    {
        return $this->lifecycle($actor, $table, $expectedVersion, 'deleted');
    }

    public function syncMembers(User $actor, PersonnelTable $table, array $employeeIds, int $expectedVersion): PersonnelTable
    {
        return $this->update($actor, $table, ['employee_ids' => $employeeIds], $expectedVersion);
    }

    public function setCell(User $actor, PersonnelTable $table, Employee $employee, string $columnId, mixed $value, int $expectedEntryVersion): PersonnelTableEntry
    {
        return $this->changeEntry($actor, $table, $employee, $expectedEntryVersion, function (PersonnelTable $current, PersonnelTableEntry $entry) use ($columnId, $value): array {
            $column = collect($current->columns)->firstWhere('id', $columnId);
            if (! is_array($column)) {
                throw ValidationException::withMessages(['column' => 'This column is no longer available. Refresh the table.']);
            }
            $values = $entry->values;
            $values[$columnId] = $this->cellValue($column, $value);
            $entry->values = $values;

            return ['column_id' => $columnId];
        }, 'cell_updated');
    }

    public function setHighlight(User $actor, PersonnelTable $table, Employee $employee, ?string $color, int $expectedEntryVersion): PersonnelTableEntry
    {
        Validator::make(['highlight' => $color], ['highlight' => ['nullable', Rule::in(['', ...self::COLORS])]])->validate();

        return $this->changeEntry($actor, $table, $employee, $expectedEntryVersion, function (PersonnelTable $current, PersonnelTableEntry $entry) use ($color): array {
            $entry->highlight = $color ?: null;

            return ['fields' => ['highlight']];
        }, 'highlight_updated');
    }

    public function saveView(User $actor, PersonnelTable $table, string $name, array $settings): PersonnelTableView
    {
        $this->authorize($actor, $table);
        $name = trim($name);
        $currentTable = PersonnelTable::withTrashed()->findOrFail($table->getKey());
        $sourceSorts = array_values(array_diff(array_keys($this->source->fields()), ['email_match_status']));
        $trackingSorts = array_column($currentTable->columns, 'id');
        $sortFields = [...$sourceSorts, ...$trackingSorts];
        $tableSortFields = [...$sourceSorts, ...array_map(fn (string $id): string => 'tracking_'.$id, $trackingSorts)];
        Validator::make(['name' => $name, 'settings' => $settings], [
            'name' => ['required', 'string', 'max:100'], 'settings' => ['array', 'max:12'],
            'settings.tableSearch' => ['sometimes', 'nullable', 'string', 'max:150'],
            'settings.tableSortColumn' => ['sometimes', 'nullable', 'string', Rule::in($tableSortFields)],
            'settings.tableSortDirection' => ['sometimes', 'nullable', Rule::in(['asc', 'desc'])],
            'settings.tableFilters' => ['sometimes', 'nullable', 'array', 'max:60'],
            'settings.tableFilters.*' => ['nullable', 'array', 'max:10'],
            'settings.toggledTableColumns' => ['sometimes', 'array', 'max:60'],
            'settings.toggledTableColumns.*' => ['boolean'],
            'settings.personnelSortRules' => ['sometimes', 'array', 'max:5'],
            'settings.personnelSortRules.*.field' => ['required', Rule::in($sortFields)],
            'settings.personnelSortRules.*.direction' => ['required', Rule::in(['asc', 'desc'])],
            'settings.search' => ['sometimes', 'nullable', 'string', 'max:150'],
            'settings.sort' => ['sometimes', 'nullable', 'string', 'max:100'],
            'settings.direction' => ['sometimes', 'nullable', Rule::in(['asc', 'desc'])],
            'settings.filters' => ['sometimes', 'nullable', 'array', 'max:60'],
            'settings.columns' => ['sometimes', 'array', 'max:60'],
        ])->validate();
        $allowed = ['tableFilters', 'tableSearch', 'tableSortColumn', 'tableSortDirection', 'toggledTableColumns', 'personnelSortRules', 'search', 'sort', 'direction', 'filters', 'columns'];
        if (array_diff(array_keys($settings), $allowed) !== [] || strlen(json_encode($settings, JSON_THROW_ON_ERROR)) > 20000) {
            throw ValidationException::withMessages(['settings' => 'Choose a supported view configuration within the allowed size.']);
        }

        return DB::transaction(function () use ($actor, $table, $name, $settings): PersonnelTableView {
            $current = $this->locked($table);
            $this->authorize($actor, $current);
            if ($current->trashed()) {
                throw ValidationException::withMessages(['table' => 'Restore this table before saving a view.']);
            }
            if ($current->views()->where('user_id', $actor->id)->where('name', '!=', $name)->count() >= 25) {
                throw ValidationException::withMessages(['name' => 'Keep up to 25 saved views per table.']);
            }

            return $current->views()->updateOrCreate(['user_id' => $actor->id, 'name' => $name], ['settings' => $settings]);
        }, 3);
    }

    public function deleteView(User $actor, PersonnelTable $table, int $viewId): void
    {
        $this->authorize($actor, $table);
        $table->views()->where('user_id', $actor->id)->findOrFail($viewId)->delete();
    }

    private function definition(array $attributes, ?PersonnelTable $existing = null): array
    {
        $allowed = ['name', 'description', 'visibility', 'membership_mode', 'criteria', 'source_columns', 'columns', 'employee_ids'];
        if (array_diff(array_keys($attributes), $allowed) !== []) {
            throw ValidationException::withMessages(['table' => 'Only table configuration and linked members may be changed here.']);
        }
        $data = array_replace([
            'name' => 'New Personnel Table', 'description' => null, 'visibility' => 'private', 'membership_mode' => 'fixed',
            'criteria' => ['active_only' => true], 'source_columns' => ['name', 'employee_id', 'rank'], 'columns' => [], 'employee_ids' => [],
        ], $existing?->only(array_diff($allowed, ['employee_ids'])) ?? [], $attributes);
        if (is_string($data['name'])) {
            $data['name'] = trim($data['name']);
        }
        $validated = Validator::make($data, [
            'name' => ['required', 'string', 'max:150'], 'description' => ['nullable', 'string', 'max:2000'],
            'visibility' => ['required', Rule::in(['private', 'shared'])], 'membership_mode' => ['required', Rule::in(['fixed', 'dynamic'])],
            'criteria' => ['required', 'array', 'max:20'], 'source_columns' => ['required', 'array', 'min:1', 'max:25'],
            'source_columns.*' => ['required', 'string', 'distinct', Rule::in(array_keys($this->source->fields()))],
            'columns' => ['present', 'array', 'max:40'], 'columns.*' => ['array:id,label,type,options,hidden,color,highlight_when,highlight_value'],
            'columns.*.id' => ['sometimes', 'uuid', 'distinct'], 'columns.*.label' => ['required', 'string', 'max:80'],
            'columns.*.type' => ['required', Rule::in(self::TYPES)], 'columns.*.options' => ['sometimes', 'array', 'max:40'],
            'columns.*.options.*' => ['required', 'string', 'distinct', 'max:100'],
            'columns.*.hidden' => ['sometimes', 'boolean'], 'columns.*.color' => ['sometimes', 'nullable', Rule::in(['', ...self::COLORS])],
            'columns.*.highlight_when' => ['sometimes', Rule::in(['always', 'filled', 'checked', 'equals'])],
            'columns.*.highlight_value' => ['sometimes', 'nullable', 'string', 'max:100'],
            'employee_ids' => ['present', 'array', 'max:2000'], 'employee_ids.*' => ['required', 'integer', 'distinct', Rule::exists('employees', 'id')],
        ])->validate();
        $validated['criteria'] = $this->source->validateCriteria($validated['criteria']);
        $validated['columns'] = array_map(function (array $column): array {
            $column = array_replace(['id' => (string) Str::uuid(), 'options' => [], 'hidden' => false, 'color' => null, 'highlight_when' => 'always', 'highlight_value' => null], $column);
            $column['label'] = trim($column['label']);
            if ($column['label'] === '' || (in_array($column['type'], ['select', 'multiselect', 'status'], true) && $column['options'] === [])) {
                throw ValidationException::withMessages(['columns' => 'Name every column and provide choices for select and status columns.']);
            }
            if (($column['highlight_when'] === 'checked' && $column['type'] !== 'checkbox')
                || ($column['highlight_when'] === 'equals' && blank($column['highlight_value']))) {
                throw ValidationException::withMessages(['columns' => 'Choose a highlight condition supported by this column and enter its comparison value.']);
            }
            $column['color'] = $column['color'] ?: null;
            $column['hidden'] = (bool) $column['hidden'];

            return $column;
        }, $validated['columns']);

        return $validated;
    }

    private function replaceMembers(PersonnelTable $table, array $employeeIds): void
    {
        $table->entries()->whereNotIn('employee_id', $employeeIds)->whereNull('removed_at')->update(['removed_at' => now(), 'updated_at' => now()]);
        foreach ($employeeIds as $id) {
            $table->entries()->firstOrCreate(['employee_id' => $id], ['values' => [], 'version' => 0]);
        }
        $table->entries()->whereIn('employee_id', $employeeIds)->whereNotNull('removed_at')->update(['removed_at' => null, 'updated_at' => now()]);
    }

    private function changeEntry(User $actor, PersonnelTable $table, Employee $employee, int $expectedVersion, callable $change, string $action): PersonnelTableEntry
    {
        return DB::transaction(function () use ($actor, $table, $employee, $expectedVersion, $change, $action): PersonnelTableEntry {
            $current = $this->locked($table);
            $this->authorize($actor, $current, 'manage');
            if (! $this->members($actor, $current)->where('employees.id', $employee->getKey())->exists()) {
                throw ValidationException::withMessages(['employee' => 'This member is no longer included in the table. Refresh before editing.']);
            }
            $entry = $current->entries()->where('employee_id', $employee->getKey())->lockForUpdate()->first();
            if ($entry === null) {
                $entry = $current->entries()->create(['employee_id' => $employee->getKey(), 'values' => [], 'version' => 0]);
            }
            $this->assertVersion($entry->version, $expectedVersion);
            $metadata = $change($current, $entry);
            $entry->version++;
            $entry->save();
            $current->touch();
            $this->event($actor, $current, $action, ['entry_id' => $entry->id, ...$metadata]);

            return $entry->refresh();
        }, 3);
    }

    /** @param PersonnelTrackingColumn $column */
    private function cellValue(array $column, mixed $value): mixed
    {
        $rules = match ($column['type']) {
            'checkbox' => ['required', 'boolean'],
            'text' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'number' => ['nullable', 'numeric', 'between:-999999999999,999999999999'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'select', 'status' => ['nullable', 'string', Rule::in($column['options'])],
            'multiselect' => ['nullable', 'array', 'max:40'],
            default => throw ValidationException::withMessages(['column' => 'This tracking column type is unsupported. Choose a supported column type.']),
        };
        $payload = Validator::make(['value' => $value], [
            'value' => $rules, ...($column['type'] === 'multiselect' ? ['value.*' => ['string', 'distinct', Rule::in($column['options'])]] : []),
        ])->validate();

        return match ($column['type']) {
            'checkbox' => (bool) $payload['value'],
            'number' => $payload['value'] === null || $payload['value'] === '' ? null : (float) $payload['value'],
            default => $payload['value'] ?? null,
        };
    }

    private function lifecycle(User $actor, PersonnelTable $table, int $expectedVersion, string $action): PersonnelTable
    {
        return DB::transaction(function () use ($actor, $table, $expectedVersion, $action): PersonnelTable {
            $current = $this->locked($table);
            $this->authorize($actor, $current, 'archive');
            $this->assertVersion($current->version, $expectedVersion);
            if ($current->directory_key !== null) {
                throw ValidationException::withMessages(['table' => 'The master personnel directory cannot be archived or deleted.']);
            }
            if ($current->trashed() && $action !== 'restored') {
                throw ValidationException::withMessages(['table' => 'This table is deleted. Restore it before making another change.']);
            }
            if ($action === 'restored') {
                if ($current->trashed()) {
                    $current->restore();
                }
                $current->archived_at = null;
            } elseif ($action === 'archived') {
                $current->archived_at = now();
            } elseif ($current->archived_at === null) {
                throw ValidationException::withMessages(['table' => 'Archive this table before deleting it. Its records will remain recoverable.']);
            }
            $current->version++;
            $current->save();
            if ($action === 'deleted') {
                $current->delete();
            }
            $this->event($actor, $current, $action);

            return PersonnelTable::withTrashed()->findOrFail($current->id);
        }, 3);
    }

    private function locked(PersonnelTable $table): PersonnelTable
    {
        return PersonnelTable::withTrashed()->lockForUpdate()->findOrFail($table->getKey());
    }

    private function assertVersion(int $actual, int $expected): void
    {
        if ($actual !== $expected) {
            throw ValidationException::withMessages(['conflict' => 'This table or row changed. Your changes were not saved. Refresh and try again.']);
        }
    }

    private function event(User $actor, PersonnelTable $table, string $action, array $metadata = []): void
    {
        PersonnelTableEvent::query()->create(['table_id' => $table->id, 'actor_user_id' => $actor->id, 'action' => $action, 'metadata' => $metadata]);
    }

    private function visibleTo(User $actor, PersonnelTable $table): bool
    {
        return $table->visibility === 'shared' || (int) $table->created_by === (int) $actor->getKey() || $this->isSuperAdmin($actor);
    }

    private function isSuperAdmin(User $actor): bool
    {
        $current = User::query()->find($actor->getKey());

        return $current !== null && $current->isAuthenticationAllowed() && $current->hasCurrentAdminPanelEntitlement() && $current->hasRole('super_admin');
    }

    private function has(User $actor, array $permissions): bool
    {
        $current = User::query()->find($actor->getKey());
        if ($current === null || ! $current->isAuthenticationAllowed() || ! $current->hasCurrentAdminPanelEntitlement()) {
            return false;
        }
        if ($current->hasRole('super_admin')) {
            return true;
        }
        foreach ($permissions as $permission) {
            if ($current->hasDirectWebPermission($permission)) {
                return true;
            }
        }

        return false;
    }
}
