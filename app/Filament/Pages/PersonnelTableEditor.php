<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Concerns\EnterpriseTable;
use App\Filament\Concerns\InteractsWithPersonnelTracking;
use App\Filament\Support\PersonnelTableSchema;
use App\Models\Employee;
use App\Models\PersonnelTable;
use App\Models\User;
use App\Services\Personnel\PersonnelSource;
use App\Services\Personnel\PersonnelTableExporter;
use App\Services\Personnel\PersonnelTableService;
use App\Services\Personnel\PersonnelWorkbookReconciler;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components as Forms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class PersonnelTableEditor extends Page implements HasTable
{
    use EnterpriseTable, InteractsWithPersonnelTracking;
    use InteractsWithTable {
        getTableFiltersSessionKey as protected baseTableFiltersSessionKey;
        getTableSearchSessionKey as protected baseTableSearchSessionKey;
        getTableSortSessionKey as protected baseTableSortSessionKey;
    }

    protected static bool $shouldRegisterNavigation = false;

    protected static string $view = 'filament.pages.personnel-table-editor';

    #[Locked]
    public int $personnelTableId;

    public array $personnelSortRules = [['field' => 'name', 'direction' => 'asc']];

    private ?array $personnelEmailStatuses = null;

    public static function getRoutePath(): string
    {
        return '/custom-personnel-tables/{table}';
    }

    public static function canAccess(): bool
    {
        return auth()->user() instanceof User && app(PersonnelTableService::class)->canViewTables(auth()->user());
    }

    public function mount(int $table): void
    {
        abort_unless(self::canAccess(), 403);
        $this->personnelTableId = $table;
        $this->initializePersonnelTracking($this->personnelTable());
    }

    public function personnelTable(): PersonnelTable
    {
        $table = PersonnelTable::withTrashed()->findOrFail($this->personnelTableId);
        abort_unless($table->directory_key === null, 404);
        app(PersonnelTableService::class)->authorize(auth()->user(), $table);

        return $table;
    }

    public function getTitle(): string
    {
        return isset($this->personnelTableId) ? $this->personnelTable()->name : 'Personnel Table';
    }

    public function getSubheading(): string
    {
        $table = $this->personnelTable();
        $state = $table->trashed() ? 'Deleted · restore to edit' : ($table->archived_at !== null ? 'Archived · restore to edit' : ($table->membership_mode === 'dynamic' ? 'Dynamic membership' : 'Fixed membership'));

        return $state.($table->description ? ' · '.$table->description : '');
    }

    public function getBreadcrumbs(): array
    {
        return [CustomPersonnelTables::getUrl() => 'Custom Personnel Tables', $this->personnelTable()->name];
    }

    public function table(Table $table): Table
    {
        $definition = $this->personnelTable();
        $source = app(PersonnelSource::class);
        $columns = [];
        foreach ($definition->source_columns ?? [] as $key) {
            if (! array_key_exists($key, $source->fields())) {
                continue;
            }
            $column = Tables\Columns\TextColumn::make($key)->label($source->fields()[$key])
                ->state(fn (Employee $record) => $key === 'email_match_status' ? ($this->emailStatuses()[$record->id] ?? null) : $source->value($record, $key))->placeholder('Not recorded')->wrap()->toggleable();
            if ($columns === []) {
                $column->description(fn (Employee $record): string => $record->name.' · '.$record->employee_id.' · '.($record->rank ?? ''));
            } else {
                $column->visibleFrom('md');
            }
            if ($key !== 'email_match_status') {
                $column->sortable(query: fn (Builder $query, string $direction): Builder => $this->orderSource($query, $key, $direction));
            }
            $columns[] = $column;
        }

        return self::applyEnterpriseDefaults($table)->deferLoading(false)->searchable()
            ->query(fn (): Builder => app(PersonnelTableService::class)->members(auth()->user(), $this->personnelTable()))
            ->columns([...$columns, ...$this->trackingColumns($definition)])
            ->recordClasses(fn (Employee $record): string => $this->trackingRecordClasses($record))
            ->defaultSort(fn (Builder $query): Builder => $this->applyPersonnelSort($query))
            ->filters([
                ...collect(['shift', 'rank', 'station', 'division', 'unit', 'bid_position', 'roster_status'])
                    ->map(fn (string $field) => Tables\Filters\SelectFilter::make($field)->label($source->fields()[$field])
                        ->multiple()->searchable()->options(fn (): array => PersonnelTableSchema::sourceOptions($field))
                        ->query(fn (Builder $query, array $data): Builder => empty($data['values']) ? $query : $source->filter($query, [$field => $data['values']])))->all(),
                ...$this->trackingFilters($definition),
            ])->actions([
                Tables\Actions\Action::make('details')->label('Details')->icon('heroicon-o-eye')
                    ->modalHeading(fn (Employee $record): string => $record->name)
                    ->modalContent(fn (Employee $record) => view('filament.pages.partials.personnel-table-member-details', [
                        'fields' => array_intersect_key($source->fields(), array_flip($this->personnelTable()->source_columns ?? [])),
                        'record' => $record, 'source' => $source,
                        'emailStatus' => $this->emailStatuses()[$record->id] ?? null,
                        'trackingColumns' => $this->personnelTable()->columns ?? [],
                        'trackingValues' => $this->trackingEntry($record)->values ?? [],
                    ]))->modalSubmitAction(false)->modalCancelActionLabel('Close'),
                ...$this->trackingRowActions($definition),
            ])
            ->bulkActions([
                Tables\Actions\BulkAction::make('exportSelected')->label('Export selected members')->icon('heroicon-o-arrow-down-tray')
                    ->visible(fn (): bool => app(PersonnelTableService::class)->canExport(auth()->user(), $this->personnelTable()))
                    ->form([$this->exportFormatInput()])
                    ->action(fn (Collection $records, array $data): StreamedResponse => app(PersonnelTableExporter::class)
                        ->export(auth()->user(), $this->personnelTable(), $records, $this->exportColumns(), $data['format'], 'selected')),
                Tables\Actions\BulkAction::make('removeMembers')->label('Remove selected members')->color('danger')->requiresConfirmation()
                    ->modalDescription('This removes membership in this table. Personnel profiles, accounts, history and saved tracking values are preserved.')
                    ->visible(fn (): bool => $this->canEditTable() && $this->personnelTable()->membership_mode === 'fixed')
                    ->action(function (Collection $records): void {
                        $table = $this->personnelTable();
                        $remaining = $table->entries()->whereNull('removed_at')->whereNotIn('employee_id', $records->modelKeys())->pluck('employee_id')->all();
                        $updated = app(PersonnelTableService::class)->syncMembers(auth()->user(), $table, $remaining, $this->personnelMetadataVersion);
                        $this->initializePersonnelTracking($updated);
                        $this->deselectAllTableRecords();
                        $this->personnelEntries = null;
                        Notification::make()->title('Members removed from this table')->success()->send();
                    }),
            ])->searchPlaceholder('Search name, employee ID, rank, station, unit or shift')
            ->emptyStateHeading('No members match this table')
            ->emptyStateDescription('Clear filters or use Members to update the selection.');
    }

    protected function applySearchToTableQuery(Builder $query): Builder
    {
        return app(PersonnelSource::class)->filter($query, ['search' => (string) $this->getTableSearch()]);
    }

    protected function getHeaderActions(): array
    {
        $table = $this->personnelTable();

        return $this->groupEditorActions([
            Action::make('editTable')->label('Table settings')->icon('heroicon-o-pencil-square')->visible(fn (): bool => $this->canEditTable())
                ->fillForm(fn (): array => $this->personnelTable()->only(['name', 'description', 'visibility']))
                ->form([
                    Forms\TextInput::make('name')->label('Table name')->required()->maxLength(100),
                    Forms\Textarea::make('description')->rows(2)->maxLength(2000),
                    Forms\Select::make('visibility')->options(['private' => 'Private', 'shared' => 'Shared with authorized administrators'])->required(),
                ])->action(function (array $data): void {
                    $updated = app(PersonnelTableService::class)->update(auth()->user(), $this->personnelTable(), $data, $this->personnelMetadataVersion);
                    $this->initializePersonnelTracking($updated);
                    Notification::make()->title('Table settings saved')->success()->send();
                }),
            Action::make('members')->label('Members')->icon('heroicon-o-users')->visible(fn (): bool => $this->canEditTable())
                ->fillForm(fn (): array => ['employee_ids' => $this->personnelTable()->entries()->whereNull('removed_at')->pluck('employee_id')->all(), 'criteria' => $this->personnelTable()->criteria])
                ->form(fn (): array => $this->personnelTable()->membership_mode === 'dynamic'
                    ? PersonnelTableSchema::criteriaForm() : [PersonnelTableSchema::memberSelect()])
                ->action(function (array $data): void {
                    $service = app(PersonnelTableService::class);
                    $table = $this->personnelTable();
                    $updated = $table->membership_mode === 'dynamic'
                        ? $service->update(auth()->user(), $table, ['criteria' => $data['criteria'] ?? []], $this->personnelMetadataVersion)
                        : $service->syncMembers(auth()->user(), $table, $data['employee_ids'] ?? [], $this->personnelMetadataVersion);
                    $this->initializePersonnelTracking($updated);
                    $this->personnelEntries = null;
                    $this->resetTable();
                    Notification::make()->title('Members updated')->success()->send();
                }),
            Action::make('export')->label('Export')->icon('heroicon-o-arrow-down-tray')->color('gray')
                ->visible(fn (): bool => app(PersonnelTableService::class)->canExport(auth()->user(), $this->personnelTable()))
                ->form([$this->exportFormatInput(), Forms\Radio::make('scope')->label('Members to export')
                    ->options(['filtered' => 'Current filters and search', 'all' => 'All members in this table'])->default('filtered')->required()])
                ->action(function (array $data): StreamedResponse {
                    $query = $data['scope'] === 'all' ? app(PersonnelTableService::class)->members(auth()->user(), $this->personnelTable()) : $this->getFilteredSortedTableQuery();
                    $employees = $query->get()->map(static function (Model $record): Employee {
                        if (! $record instanceof Employee) {
                            throw new \LogicException('Personnel exports require employee records.');
                        }

                        return $record;
                    });

                    return app(PersonnelTableExporter::class)->export(auth()->user(), $this->personnelTable(), $employees, $this->exportColumns(), $data['format'], $data['scope']);
                }),
            Action::make('multiSort')->label('Sort columns')->color('gray')->icon('heroicon-o-bars-arrow-down')
                ->fillForm(fn (): array => ['sorts' => $this->personnelSortRules])
                ->form([Forms\Repeater::make('sorts')->minItems(1)->maxItems(5)->reorderableWithButtons()->schema([
                    Forms\Select::make('field')->options(fn (): array => $this->sortOptions())->required(),
                    Forms\Select::make('direction')->options(['asc' => 'Ascending', 'desc' => 'Descending'])->required(),
                ])->columns(2)])
                ->action(function (array $data): void {
                    $this->personnelTable();
                    $this->personnelSortRules = $this->validatedSort($data['sorts']);
                    $this->tableSortColumn = null;
                    $this->resetPage();
                }),
            ...$this->trackingHeaderActions($table),
            Action::make('duplicate')->label('Duplicate')->color('gray')->icon('heroicon-o-document-duplicate')
                ->visible(fn (): bool => ! $this->personnelTable()->trashed() && app(PersonnelTableService::class)->canCreate(auth()->user()))
                ->form([Forms\Checkbox::make('include_data')->label('Also copy tracking values and highlights')->default(false)])
                ->action(function (array $data): void {
                    $copy = app(PersonnelTableService::class)->duplicate(auth()->user(), $this->personnelTable(), (bool) ($data['include_data'] ?? false));
                    $this->redirect(static::getUrl(['table' => $copy->id]));
                }),
            Action::make('archive')->label('Archive')->color('gray')->requiresConfirmation()
                ->visible(fn (): bool => ! $this->personnelTable()->trashed() && $this->personnelTable()->archived_at === null && $this->canArchiveTable())
                ->action(function (): void {
                    app(PersonnelTableService::class)->archive(auth()->user(), $this->personnelTable(), $this->personnelMetadataVersion);
                    $this->redirect(CustomPersonnelTables::getUrl());
                }),
            Action::make('deleteTable')->label('Delete table')->color('danger')->requiresConfirmation()
                ->modalDescription('The table goes to Deleted and can be restored. Personnel records, accounts and history are preserved.')
                ->visible(fn (): bool => ! $this->personnelTable()->trashed() && $this->personnelTable()->archived_at !== null && $this->canArchiveTable())
                ->action(function (): void {
                    app(PersonnelTableService::class)->delete(auth()->user(), $this->personnelTable(), $this->personnelMetadataVersion);
                    $this->redirect(CustomPersonnelTables::getUrl());
                }),
            Action::make('restore')->label('Restore')->requiresConfirmation()
                ->visible(fn (): bool => ($this->personnelTable()->trashed() || $this->personnelTable()->archived_at !== null)
                    && $this->canArchiveTable())
                ->action(function (): void {
                    $updated = app(PersonnelTableService::class)->restore(auth()->user(), $this->personnelTable(), $this->personnelMetadataVersion);
                    $this->initializePersonnelTracking($updated);
                    Notification::make()->title('Table restored')->success()->send();
                }),
        ]);
    }

    private function groupEditorActions(array $actions): array
    {
        $actions = collect($actions)->keyBy(fn (Action $action): string => $action->getName());

        return [
            $actions->get('members'), $actions->get('configureColumns'), $actions->get('export'),
            ActionGroup::make($actions->only(['applyView', 'saveView', 'deleteView'])->values()->all())
                ->label('Views')->icon('heroicon-o-bookmark')->button()->color('gray'),
            ActionGroup::make($actions->except(['members', 'configureColumns', 'export', 'applyView', 'saveView', 'deleteView'])->values()->all())
                ->label('More')->icon('heroicon-o-ellipsis-horizontal')->button()->color('gray'),
        ];
    }

    private function canEditTable(): bool
    {
        $table = $this->personnelTable();

        return ! $table->trashed() && $table->archived_at === null && app(PersonnelTableService::class)->canManage(auth()->user(), $table);
    }

    private function canArchiveTable(): bool
    {
        return app(PersonnelTableService::class)->canArchive(auth()->user(), $this->personnelTable());
    }

    private function exportFormatInput(): Forms\Select
    {
        return Forms\Select::make('format')->options(['xlsx' => 'Excel (.xlsx)', 'csv' => 'CSV (.csv)', 'pdf' => 'PDF (.pdf)'])->default('xlsx')->required();
    }

    private function exportColumns(): array
    {
        return collect($this->getTable()->getColumns())->reject(fn ($column): bool => $column->isToggledHidden())
            ->map(fn ($column): string => preg_replace('/^tracking_/', '', $column->getName()))->values()->all();
    }

    private function validatedSort(array $sorts): array
    {
        return Validator::make(['sorts' => $sorts], ['sorts' => ['required', 'array', 'min:1', 'max:5'],
            'sorts.*.field' => ['required', Rule::in(array_keys($this->sortOptions()))],
            'sorts.*.direction' => ['required', Rule::in(['asc', 'desc'])],
        ])->validate()['sorts'];
    }

    private function applyPersonnelSort(Builder $query): Builder
    {
        $allowed = array_keys($this->sortOptions());
        $sorts = array_values(array_filter($this->personnelSortRules, fn ($sort): bool => is_array($sort)
            && in_array($sort['field'] ?? null, $allowed, true)));
        foreach ($this->validatedSort($sorts ?: [['field' => 'name', 'direction' => 'asc']]) as $sort) {
            $this->orderSource($query, $sort['field'], $sort['direction']);
        }

        return $query->orderBy('employees.id');
    }

    private function orderSource(Builder $query, string $field, string $direction): Builder
    {
        $this->validatedSort([['field' => $field, 'direction' => $direction]]);
        if (array_key_exists($field, $this->trackingSortOptions($this->personnelTable()))) {
            return $this->orderTrackingColumn($query, $this->personnelTable(), $field, $direction);
        }

        return $query->orderBy(in_array($field, ['shift', 'a_day', 'division', 'unit', 'bid_position', 'bid_station', 'bid_term', 'assignment_source'], true) ? 'source_'.$field : 'employees.'.$field, $direction);
    }

    private function sortOptions(): array
    {
        return [...array_diff_key(app(PersonnelSource::class)->fields(), ['email_match_status' => true]),
            ...$this->trackingSortOptions($this->personnelTable())];
    }

    private function emailStatuses(): array
    {
        return $this->personnelEmailStatuses ??= (PersonnelWorkbookReconciler::canView(auth()->user())
            ? app(PersonnelWorkbookReconciler::class)->latestEmailStatuses(auth()->user()) : []);
    }

    public function getTableFiltersSessionKey(): string
    {
        return $this->baseTableFiltersSessionKey().'.'.($this->personnelTableId ?? 'new');
    }

    public function getTableSearchSessionKey(): string
    {
        return $this->baseTableSearchSessionKey().'.'.($this->personnelTableId ?? 'new');
    }

    public function getTableSortSessionKey(): string
    {
        return $this->baseTableSortSessionKey().'.'.($this->personnelTableId ?? 'new');
    }

    public function getTableColumnToggleFormStateSessionKey(): string
    {
        return 'personnel.table.'.$this->personnelTableId.'.columns';
    }
}
