<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Filament\Support\PersonnelTableSchema;
use App\Models\Employee;
use App\Models\PersonnelTable;
use App\Models\PersonnelTableEntry;
use App\Models\User;
use App\Services\Exports\ExportDataset;
use App\Services\Exports\ExportFailedException;
use App\Services\Exports\TableExportService;
use App\Services\Personnel\PersonnelSource;
use App\Services\Personnel\PersonnelTableService;
use Filament\Actions\Action;
use Filament\Forms\Components as Forms;
use Filament\Notifications\Notification;
use Filament\Tables;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;
use Symfony\Component\HttpFoundation\StreamedResponse;

trait InteractsWithPersonnelTracking
{
    #[Locked]
    public int $personnelMetadataVersion = 0;

    #[Locked]
    public array $personnelEntryVersions = [];

    /** @var Collection<int, PersonnelTableEntry>|null */
    private ?Collection $personnelEntries = null;

    private ?bool $personnelTrackingWritable = null;

    protected function initializePersonnelTracking(PersonnelTable $table): void
    {
        $this->personnelMetadataVersion = (int) $table->version;
        $this->personnelTrackingWritable = null;
    }

    protected function trackingColumns(PersonnelTable $table): array
    {
        return collect($table->columns ?? [])->map(function (array $definition) use ($table) {
            $id = $definition['id'];
            $name = 'tracking_'.$id;
            $state = fn (Employee $record) => $this->trackingEntry($record)?->values[$id] ?? ($definition['type'] === 'checkbox' ? false : null);
            $save = fn (Employee $record, $state) => $this->saveTrackingCell($record, $id, $state);
            $editable = fn (): bool => ! $this->canEditPersonnelTracking();
            $column = match ($definition['type']) {
                'checkbox' => Tables\Columns\CheckboxColumn::make($name)->getStateUsing($state)
                    ->disabled($editable)->updateStateUsing($save),
                'text', 'number', 'date' => Tables\Columns\TextInputColumn::make($name)->getStateUsing($state)
                    ->type(match ($definition['type']) {
                        'number' => 'number', 'date' => 'date', default => 'text'
                    })
                    ->rules($definition['type'] === 'number' ? ['nullable', 'numeric'] : ($definition['type'] === 'date' ? ['nullable', 'date_format:Y-m-d'] : ['nullable', 'string', 'max:500']))
                    ->disabled($editable)->updateStateUsing($save),
                'select', 'status' => Tables\Columns\SelectColumn::make($name)->getStateUsing($state)
                    ->options(array_combine($definition['options'], $definition['options']) ?: [])
                    ->disabled($editable)->updateStateUsing($save),
                default => Tables\Columns\TextColumn::make($name)->getStateUsing($state)
                    ->formatStateUsing(fn ($state): string => is_array($state) ? implode(', ', $state) : (string) $state)
                    ->limit(80)->wrap(),
            };

            return $column->label($definition['label'])->toggleable(isToggledHiddenByDefault: $definition['hidden'])
                ->sortable(query: fn (Builder $query, string $direction): Builder => $this->orderTrackingColumn($query, $table, $id, $direction))
                ->extraCellAttributes(fn (Employee $record): array => $this->conditionalHighlightAttributes($definition, $state($record)));
        })->all();
    }

    protected function trackingEntry(Employee $employee): ?PersonnelTableEntry
    {
        if ($this->personnelEntries === null) {
            $this->personnelEntries = $this->personnelTable()->entries()->get()->keyBy('employee_id');
        }
        $entry = $this->personnelEntries->get($employee->id);
        $this->personnelEntryVersions[$employee->id] ??= (int) ($entry->version ?? 0);

        return $entry;
    }

    protected function saveTrackingCell(Employee $employee, string $columnId, mixed $value): mixed
    {
        $this->trackingEntry($employee);
        $entry = app(PersonnelTableService::class)->setCell(auth()->user(), $this->personnelTable(), $employee,
            $columnId, $value, $this->personnelEntryVersions[$employee->id]);
        $this->personnelEntryVersions[$employee->id] = (int) $entry->version;
        $this->personnelEntries = null;
        Notification::make()->title('Saved')->success()->duration(1500)->send();

        return $entry->values[$columnId] ?? null;
    }

    protected function trackingRowActions(PersonnelTable $table): array
    {
        return [Tables\Actions\Action::make('editTracking')->label('Tracking & notes')->icon('heroicon-o-pencil-square')
            ->visible(fn (): bool => $this->canEditPersonnelTracking())
            ->fillForm(function (Employee $record): array {
                $entry = $this->trackingEntry($record);

                return ['values' => $entry->values ?? [], 'highlight' => $entry->highlight ?? '',
                    'entry_version' => (int) ($entry->version ?? 0)];
            })
            ->form(fn (): array => [
                Forms\Hidden::make('entry_version')->required()->rules(['integer']),
                ...collect($this->personnelTable()->columns ?? [])->map(fn (array $column) => PersonnelTableSchema::trackingInput($column))->all(),
                Forms\Select::make('highlight')->label('Row highlight')->options(PersonnelTableSchema::colors()),
            ])
            ->action(function (Employee $record, array $data): void {
                DB::transaction(function () use ($record, $data): void {
                    $service = app(PersonnelTableService::class);
                    $table = $this->personnelTable();
                    $version = (int) $data['entry_version'];
                    foreach ($table->columns ?? [] as $column) {
                        $entry = $service->setCell(auth()->user(), $table, $record, $column['id'],
                            $data['values'][$column['id']] ?? ($column['type'] === 'checkbox' ? false : null), $version);
                        $version = (int) $entry->version;
                    }
                    $entry = $service->setHighlight(auth()->user(), $table, $record, $data['highlight'] ?? '', $version);
                    $this->personnelEntryVersions[$record->id] = (int) $entry->version;
                });
                $this->personnelEntries = null;
                Notification::make()->title('Tracking saved')->success()->send();
            })];
    }

    protected function trackingFilters(PersonnelTable $table): array
    {
        return collect($table->columns ?? [])->map(function (array $column) use ($table) {
            $id = $column['id'];
            $type = $column['type'];
            $input = match ($type) {
                'checkbox' => Forms\Select::make('value')->options(['checked' => 'Checked', 'unchecked' => 'Unchecked']),
                'select', 'status', 'multiselect' => Forms\Select::make('value')
                    ->options(array_combine($column['options'], $column['options']) ?: []),
                'date' => Forms\Select::make('value')->options(['filled' => 'Date entered', 'blank' => 'Date blank']),
                default => Forms\TextInput::make('value')->label('Contains')->maxLength(100),
            };

            return Tables\Filters\Filter::make('tracking_'.$id)->label($column['label'])->form([$input->label($column['label'])])
                ->query(function (Builder $query, array $data) use ($table, $id, $type): Builder {
                    $value = $data['value'] ?? null;
                    if ($value === null || $value === '') {
                        return $query;
                    }
                    $matching = function ($entry) use ($table, $id, $type, $value): void {
                        $entry->selectRaw('1')->from('personnel_table_entries')
                            ->whereColumn('personnel_table_entries.employee_id', 'employees.id')->where('table_id', $table->id);
                        match ($type) {
                            'checkbox' => $entry->where('values->'.$id, true),
                            'date' => $entry->whereNotNull('values->'.$id)->where('values->'.$id, '!=', ''),
                            'multiselect' => $entry->whereJsonContains('values->'.$id, $value),
                            'select', 'status' => $entry->where('values->'.$id, $value),
                            default => $entry->whereLike('values->'.$id, '%'.$value.'%'),
                        };
                    };

                    return (($type === 'checkbox' && $value === 'unchecked') || ($type === 'date' && $value === 'blank'))
                        ? $query->whereNotExists($matching) : $query->whereExists($matching);
                });
        })->all();
    }

    public function canExportPersonnelHistory(): bool
    {
        $actor = auth()->user();
        if (! $actor instanceof User) {
            return false;
        }

        try {
            return app(PersonnelTableService::class)->canExport($actor, $this->personnelTable());
        } catch (AuthorizationException) {
            return false;
        }
    }

    public function exportPersonnelHistory(string $format): ?StreamedResponse
    {
        try {
            return app(TableExportService::class)->downloadDataset(
                auth()->user(), static::class, $format,
                fn (): bool => $this->canExportPersonnelHistory(),
                function (): ExportDataset {
                    $table = $this->personnelTable();

                    return ExportDataset::fromRows(auth()->user(), 'Personnel Table History',
                        ['When (Eastern)', 'Administrator', 'Change', 'Fields'],
                        $table->events()->with('actor')->latest('id')->limit(100)->get()->map(static fn ($event): array => [
                            $event->created_at, data_get($event, 'actor.name', 'Former administrator'),
                            str($event->action)->replace('_', ' ')->headline()->toString(), implode(', ', $event->metadata['fields'] ?? []),
                        ])->all(), 'Latest 100 changes for '.$table->name,
                        ['Tracking values and personnel contact details are omitted. History is read-only.']);
                },
                'personnel table history',
            );
        } catch (ExportFailedException $exception) {
            Notification::make()->title('Export not created')->body($exception->getMessage())->danger()->send();

            return null;
        }
    }

    protected function trackingHeaderActions(PersonnelTable $table): array
    {
        return [
            Action::make('configureColumns')->label('Columns')->icon('heroicon-o-view-columns')
                ->visible(fn (): bool => $this->canEditPersonnelTracking())
                ->fillForm(fn (): array => ['source_columns' => $this->personnelTable()->source_columns, 'columns' => $this->personnelTable()->columns])
                ->form([PersonnelTableSchema::sourceColumnsForm(), PersonnelTableSchema::columnsForm()])
                ->action(function (array $data): void {
                    $updated = app(PersonnelTableService::class)->update(auth()->user(), $this->personnelTable(), $data, $this->personnelMetadataVersion);
                    $this->initializePersonnelTracking($updated);
                    $this->resetTable();
                    Notification::make()->title('Columns saved')->success()->send();
                }),
            Action::make('restoreColumn')->label('Restore removed column')->color('gray')->icon('heroicon-o-arrow-uturn-left')
                ->visible(fn (): bool => $this->canEditPersonnelTracking() && count($this->personnelTable()->retired_columns ?? []) > 0)
                ->form([Forms\Select::make('column_id')->label('Removed column')
                    ->options(fn (): array => collect($this->personnelTable()->retired_columns ?? [])->pluck('label', 'id')->all())->required()])
                ->action(function (array $data): void {
                    $table = $this->personnelTable();
                    $column = collect($table->retired_columns ?? [])->firstWhere('id', $data['column_id']);
                    abort_unless(is_array($column), 404);
                    $updated = app(PersonnelTableService::class)->update(auth()->user(), $table,
                        ['columns' => [...$table->columns, $column]], $this->personnelMetadataVersion);
                    $this->initializePersonnelTracking($updated);
                    $this->resetTable();
                    Notification::make()->title('Column and stored values restored')->success()->send();
                }),
            Action::make('history')->label('History')->color('gray')->icon('heroicon-o-clock')
                ->modalContent(fn () => view('filament.pages.partials.personnel-table-history', [
                    'events' => $this->personnelTable()->events()->with('actor')->latest('id')->limit(100)->get(),
                ]))->modalSubmitAction(false)->modalCancelActionLabel('Close'),
            Action::make('saveView')->label('Save view')->icon('heroicon-o-bookmark')
                ->visible(fn (): bool => ! $this->personnelTable()->trashed())
                ->form([Forms\TextInput::make('name')->required()->maxLength(80)])
                ->action(function (array $data): void {
                    $settings = ['tableFilters' => $this->tableFilters, 'tableSearch' => $this->tableSearch,
                        'tableSortColumn' => $this->tableSortColumn, 'tableSortDirection' => $this->tableSortDirection,
                        'toggledTableColumns' => $this->toggledTableColumns, 'personnelSortRules' => $this->personnelSortRules];
                    app(PersonnelTableService::class)->saveView(auth()->user(), $this->personnelTable(), $data['name'], $settings);
                    Notification::make()->title('View saved')->success()->send();
                }),
            Action::make('applyView')->label('Saved views')->icon('heroicon-o-bookmark-square')
                ->form([Forms\Select::make('view_id')->label('View')->options(fn (): array => $this->personnelTable()->views()
                    ->where('user_id', auth()->id())->orderBy('name')->pluck('name', 'id')->all())->required()])
                ->action(function (array $data): void {
                    $view = $this->personnelTable()->views()->where('user_id', auth()->id())->findOrFail((int) $data['view_id']);
                    $this->tableFilters = $view->settings['tableFilters'] ?? null;
                    $this->tableSearch = $view->settings['tableSearch'] ?? '';
                    $this->tableSortColumn = $view->settings['tableSortColumn'] ?? null;
                    $this->tableSortDirection = $view->settings['tableSortDirection'] ?? null;
                    $this->toggledTableColumns = $view->settings['toggledTableColumns'] ?? [];
                    $allowed = [...array_diff_key(app(PersonnelSource::class)->fields(), ['email_match_status' => true]),
                        ...$this->trackingSortOptions($this->personnelTable())];
                    $this->personnelSortRules = array_values(array_filter($view->settings['personnelSortRules'] ?? [],
                        fn (array $sort): bool => isset($allowed[$sort['field'] ?? '']) && in_array($sort['direction'] ?? null, ['asc', 'desc'], true)))
                        ?: [['field' => 'name', 'direction' => 'asc']];
                    $this->updatedTableFilters();
                    $this->updatedToggledTableColumns();
                    Notification::make()->title('View applied')->success()->send();
                }),
            Action::make('deleteView')->label('Delete saved view')->color('gray')->requiresConfirmation()
                ->form([Forms\Select::make('view_id')->label('View')->options(fn (): array => $this->personnelTable()->views()
                    ->where('user_id', auth()->id())->orderBy('name')->pluck('name', 'id')->all())->required()])
                ->action(function (array $data): void {
                    app(PersonnelTableService::class)->deleteView(auth()->user(), $this->personnelTable(), (int) $data['view_id']);
                    Notification::make()->title('View deleted')->success()->send();
                }),
        ];
    }

    protected function trackingSortOptions(PersonnelTable $table): array
    {
        return collect($table->columns ?? [])->pluck('label', 'id')->all();
    }

    protected function orderTrackingColumn(Builder $query, PersonnelTable $table, string $id, string $direction): Builder
    {
        $definition = collect($table->columns ?? [])->firstWhere('id', $id);
        abort_unless(is_array($definition) && in_array($direction, ['asc', 'desc'], true), 422);
        $entry = PersonnelTableEntry::query()->whereColumn('employee_id', 'employees.id')->where('table_id', $table->id)->limit(1);
        $expression = $entry->getQuery()->getGrammar()->wrap('values->'.$id);
        $entry->selectRaw($definition['type'] === 'number' ? 'CAST('.$expression.' AS DECIMAL(20, 6))' : $expression);

        return $query->orderBy($entry, $direction);
    }

    protected function conditionalHighlightAttributes(array $definition, mixed $value): array
    {
        $matches = match ($definition['highlight_when'] ?? 'always') {
            'filled' => $value !== null && $value !== '' && $value !== [],
            'checked' => $value === true,
            'equals' => is_array($value) ? in_array($definition['highlight_value'] ?? null, $value, true)
                : (string) $value === (string) ($definition['highlight_value'] ?? ''),
            default => true,
        };

        return $matches ? $this->highlightAttributes($definition['color'] ?? null) : [];
    }

    private function canEditPersonnelTracking(): bool
    {
        return $this->personnelTrackingWritable ??= app(PersonnelTableService::class)->canManage(auth()->user(), $this->personnelTable());
    }

    protected function trackingRecordClasses(Employee $record): string
    {
        $color = $this->trackingEntry($record)?->highlight;

        return in_array($color, ['gray', 'info', 'success', 'warning', 'danger'], true)
            ? 'personnel-highlight-'.$color : '';
    }

    protected function highlightAttributes(?string $color): array
    {
        return in_array($color, ['gray', 'info', 'success', 'warning', 'danger'], true)
            ? ['class' => 'personnel-cell-highlight-'.$color] : [];
    }
}
