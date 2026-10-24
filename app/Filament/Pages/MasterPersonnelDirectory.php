<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Concerns\EnterpriseTable;
use App\Filament\Concerns\InteractsWithPersonnelTracking;
use App\Filament\Resources\EmployeeResource;
use App\Models\Employee;
use App\Models\PersonnelTable;
use App\Models\PromotionalList;
use App\Models\User;
use App\Services\Identity\EmployeeProfileService;
use App\Services\Personnel\PersonnelSource;
use App\Services\Personnel\PersonnelTableExporter;
use App\Services\Personnel\PersonnelTableService;
use App\Services\Personnel\PersonnelWorkbookReconciler;
use App\Services\Personnel\Promotions\PromotionalDirectory;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Enums\MaxWidth;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class MasterPersonnelDirectory extends Page implements HasTable
{
    use EnterpriseTable;
    use InteractsWithPersonnelTracking;
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $navigationGroup = 'Personnel';

    protected static ?string $title = 'Master Personnel Directory';

    protected static ?int $navigationSort = 11;

    protected static string $view = 'filament.pages.master-personnel-directory';

    /** Columns whose values also appear beneath another column (main column => companions). */
    private const STACKED = ['shift' => ['a_day'], 'unit' => ['bid_position', 'division'], 'city_email' => ['email_match_status']];

    /** Companion columns that start hidden because their values show under a main column. */
    private const SECONDARY = ['a_day', 'bid_position', 'division', 'email_match_status'];

    #[Locked]
    public int $personnelTableId;

    public array $personnelSortRules = [['field' => 'name', 'direction' => 'asc']];

    private ?array $directoryOptions = null;

    private ?array $emailStatuses = null;

    private ?bool $employeeProfilesAccessible = null;

    private ?PersonnelSource $personnelSource = null;

    public static function canAccess(): bool
    {
        $actor = auth()->user();

        return $actor instanceof User && app(PersonnelTableService::class)->canViewDirectory($actor, refreshActor: false);
    }

    public function mount(): void
    {
        abort_unless(self::canAccess(), 403);
        $table = app(PersonnelTableService::class)->master($this->actor());
        $this->personnelTableId = (int) $table->getKey();
        $this->initializePersonnelTracking($table);
    }

    public function personnelTable(): PersonnelTable
    {
        $table = PersonnelTable::query()->findOrFail($this->personnelTableId);
        abort_unless($table->directory_key === 'master', 404);
        app(PersonnelTableService::class)->authorize($this->actor(), $table);

        return $table;
    }

    public function table(Table $table): Table
    {
        $definition = $this->personnelTable();
        $source = $this->personnelSource();
        $columns = [];
        $chosen = array_values(array_filter($definition->source_columns ?? [], fn (string $key): bool => array_key_exists($key, $source->fields())));
        foreach ($chosen as $key) {
            $columns[] = $this->sourceColumn($key, $source->fields()[$key], $key === ($definition->source_columns[0] ?? null));
        }
        // Promotional list fields are always in the column chooser, hidden until someone turns them on.
        foreach (array_diff(array_keys(PromotionalDirectory::FIELDS), $chosen) as $key) {
            $columns[] = $this->sourceColumn($key, $source->fields()[$key], false)->toggleable(isToggledHiddenByDefault: true);
        }

        return self::applyEnterpriseDefaults($table)
            ->deferLoading(false)
            ->searchable()
            ->query(fn (): Builder => app(PersonnelTableService::class)->members($this->actor(), $this->personnelTable()))
            ->columns([...$columns, ...$this->trackingColumns($definition)])
            ->recordClasses(fn (Employee $record): string => $this->trackingRecordClasses($record))
            ->defaultSort(fn (Builder $query): Builder => $this->applyPersonnelSort($query))
            ->filters([
                ...array_map(fn (string $key): Tables\Filters\SelectFilter => Tables\Filters\SelectFilter::make($key)
                    ->label($source->fields()[$key])
                    ->multiple()->searchable()
                    ->options(fn (): array => $this->optionsFor($key))
                    ->query(fn (Builder $query, array $data): Builder => empty($data['values']) ? $query : $source->filter($query, [$key => $data['values']])),
                    ['rank', 'shift', 'a_day', 'station', 'division', 'unit', 'bid_position', 'roster_status']),
                Tables\Filters\SelectFilter::make('promotional_list_type')->label('Promotional list')->multiple()
                    ->options(PromotionalList::types())
                    ->query(fn (Builder $query, array $data): Builder => empty($data['values']) ? $query : $source->filter($query, ['promotional_list_type' => $data['values']])),
                Tables\Filters\Filter::make('promotional_on_list')->label('On an active promotional list')->toggle()
                    ->query(fn (Builder $query): Builder => $source->filter($query, ['promotional_on_list' => true])),
                Tables\Filters\SelectFilter::make('email_match_status')->label('Email match status')
                    ->options(fn (): array => array_combine(array_values(array_unique($this->statuses())), array_values(array_unique($this->statuses()))))
                    ->query(function (Builder $query, array $data): Builder {
                        return blank($data['value'] ?? null) ? $query : $query->whereIn('employees.id', array_keys(array_filter($this->statuses(), fn ($status): bool => $status === $data['value'])));
                    }),
                ...$this->trackingFilters($definition),
            ])
            ->actions([
                Tables\Actions\Action::make('details')->label('Details')->icon('heroicon-o-eye')->iconButton()->tooltip('Details: every field for this member')
                    ->modalHeading(fn (Employee $record): string => $record->name)
                    ->modalContent(fn (Employee $record) => view('filament.pages.partials.personnel-table-member-details', [
                        'fields' => $source->fields(), 'record' => $record, 'source' => $source,
                        'emailStatus' => $this->statuses()[$record->getKey()] ?? '',
                        'trackingColumns' => $this->personnelTable()->columns ?? [],
                        'trackingValues' => $this->trackingEntry($record)->values ?? [],
                    ]))->modalSubmitAction(false)->modalCancelActionLabel('Close'),
                // Edit actions sit behind one menu so the actions column stays narrow and sticky.
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\Action::make('employee')->label('Edit profile / employment')->icon('heroicon-o-pencil-square')
                        ->visible(fn (Employee $record): bool => $this->canAccessEmployeeProfiles() && EmployeeResource::canEdit($record))
                        ->url(fn (Employee $record): string => EmployeeResource::getUrl('edit', ['record' => $record])),
                    ...$this->trackingRowActions($definition),
                ])->label('More actions')->tooltip('More actions'),
            ])
            ->bulkActions([
                Tables\Actions\BulkAction::make('exportSelected')->label('Export selected members')
                    ->visible(fn (): bool => $this->canExportDirectory())
                    ->icon('heroicon-o-arrow-down-tray')->form([
                        Select::make('format')->options(['xlsx' => 'Excel (.xlsx)', 'csv' => 'CSV (.csv)', 'pdf' => 'PDF (.pdf)'])->default('xlsx')->required(),
                    ])
                    ->action(fn (array $data, \Illuminate\Database\Eloquent\Collection $records): StreamedResponse => app(PersonnelTableExporter::class)
                        ->export($this->actor(), $this->personnelTable(), $records, $this->exportColumns(), $data['format'], 'selected')),
            ])
            ->searchPlaceholder('Search name, employee ID, rank, station, unit or shift');
    }

    protected function applySearchToTableQuery(Builder $query): Builder
    {
        return app(PersonnelSource::class)->filter($query, ['search' => (string) $this->getTableSearch()]);
    }

    protected function getHeaderActions(): array
    {
        $definition = $this->personnelTable();

        $actions = [
            Action::make('addPersonnel')->label('Add personnel')->icon('heroicon-o-plus')
                ->visible(fn (): bool => $this->canAccessEmployeeProfiles() && EmployeeResource::canCreate())->url(fn (): string => EmployeeResource::getUrl('create')),
            Action::make('export')->label('Export')->icon('heroicon-o-arrow-down-tray')->color('gray')
                ->visible(fn (): bool => $this->canExportDirectory())
                ->form([
                    Select::make('format')->options(['xlsx' => 'Excel (.xlsx)', 'csv' => 'CSV (.csv)', 'pdf' => 'PDF (.pdf)'])->default('xlsx')->required(),
                    Radio::make('scope')->label('Members to export')->options(['filtered' => 'Current filters and search', 'all' => 'All authorized members'])->default('filtered')->required(),
                ])->action(fn (array $data): StreamedResponse => $this->exportDirectory($data)),
            Action::make('multiSort')->label('Sort columns')->icon('heroicon-o-bars-arrow-down')->color('gray')
                ->fillForm(fn (): array => ['sorts' => $this->personnelSortRules])
                ->form([Repeater::make('sorts')->label('Sort in this order')->minItems(1)->maxItems(5)->reorderableWithButtons()
                    ->schema([
                        Select::make('field')->options(fn (): array => $this->sortOptions())->required(),
                        Select::make('direction')->options(['asc' => 'Ascending', 'desc' => 'Descending'])->required(),
                    ])->columns(2)])
                ->action(function (array $data): void {
                    $this->personnelTable();
                    $this->personnelSortRules = $this->validatedSort($data['sorts']);
                    $this->tableSortColumn = null;
                    $this->resetPage();
                    Notification::make()->title('Sort order applied')->success()->send();
                }),
            ...$this->trackingHeaderActions($definition),
            Action::make('reconcile')->label('Reconcile workbook')->color('gray')->icon('heroicon-o-document-magnifying-glass')
                ->visible(fn (): bool => PersonnelWorkbookReconciler::canView($this->actor()))
                ->url('/admin/personnel-reconciliation'),
        ];
        $actions = collect($actions)->keyBy(fn (Action $action): string => $action->getName());

        return [
            $actions['addPersonnel'], $actions['export'], $actions['configureColumns'],
            ActionGroup::make([$actions['saveView'], $actions['applyView'], $actions['deleteView']])->label('Views')->button()->color('gray'),
            ActionGroup::make([$actions['multiSort'], $actions['history'], $actions['restoreColumn'], $actions['reconcile']])->label('More')->button()->color('gray'),
        ];
    }

    private function sourceColumn(string $key, string $label, bool $first): Tables\Columns\Column
    {
        $source = $this->personnelSource();
        if (in_array($key, Employee::PROFILE_FIELDS, true)) {
            $column = Tables\Columns\TextInputColumn::make($key)->label($label)
                ->disabled(fn (Employee $record): bool => ! $this->canAccessEmployeeProfiles()
                    || (in_array($key, ['phone', 'display_name'], true) ? ! EmployeeResource::canUpdateProfile($record) : ! EmployeeResource::canManagePersonnel($record)))
                ->rules($key === 'name' ? ['required', 'string', 'max:255'] : ['nullable', 'string', 'max:255'])
                ->updateStateUsing(function (Employee $record, mixed $state) use ($key): mixed {
                    $this->personnelTable();
                    abort_unless($this->canAccessEmployeeProfiles(), 403);
                    $updated = app(EmployeeProfileService::class)->update($this->actor(), $record, [$key => $state]);
                    Notification::make()->title('Profile saved')->success()->send();

                    return $updated->{$key};
                });
        } else {
            $promotional = array_key_exists($key, PromotionalDirectory::FIELDS);
            $column = Tables\Columns\TextColumn::make($key)->label($label)
                ->state(fn (Employee $record): mixed => match (true) {
                    $key === 'email_match_status' => $this->statuses()[$record->getKey()] ?? null,
                    $promotional => $source->promotionalLines($record, $key) ?: null,
                    default => $source->value($record, $key),
                })
                ->placeholder($promotional ? 'Not on a list' : 'Not recorded')->wrap();
            if ($promotional) {
                $column->listWithLineBreaks();
            }
            if ($key === 'employee_id') {
                $column->description(fn (Employee $record): string => $record->rank ?: 'Rank not recorded');
            }
            // Companion values appear under their main column, so the table needs fewer columns. Each
            // companion stays its own sortable, toggleable and exportable column.
            if (isset(self::STACKED[$key])) {
                $companions = self::STACKED[$key];
                $column->description(fn (Employee $record): ?string => collect($companions)
                    ->map(fn (string $companion): mixed => $companion === 'email_match_status'
                        ? $this->statuses()[$record->getKey()] ?? null : $source->value($record, $companion))
                    ->filter()->implode(' · ') ?: null);
            }
        }
        if ($key !== 'employee_id' && ! $first) {
            $column->visibleFrom('md');
        }
        if ($key !== 'email_match_status') {
            $column->sortable(query: fn (Builder $query, string $direction): Builder => $this->orderSource($query, $key, $direction));
        }
        if (in_array($key, ['name', 'employee_id', 'city_email', 'rank', 'station', 'phone', 'display_name'], true)) {
            $column->searchable();
        }

        return $column->toggleable(isToggledHiddenByDefault: $this->hiddenByDefault($key));
    }

    /** Low-priority columns start hidden. They remain in the column chooser and the Details view. */
    private function hiddenByDefault(string $key): bool
    {
        return in_array($key, self::SECONDARY, true);
    }

    private function optionsFor(string $key): array
    {
        if ($this->directoryOptions === null) {
            $source = app(PersonnelSource::class);
            $this->directoryOptions = [];
            foreach ($source->query()->get() as $employee) {
                foreach (['rank', 'shift', 'a_day', 'station', 'division', 'unit', 'bid_position', 'roster_status'] as $field) {
                    $value = $source->value($employee, $field);
                    if ($value !== null && $value !== '') {
                        $this->directoryOptions[$field][(string) $value] = (string) $value;
                    }
                }
            }
        }
        $options = $this->directoryOptions[$key] ?? [];
        asort($options, SORT_NATURAL | SORT_FLAG_CASE);

        return $options;
    }

    private function statuses(): array
    {
        if ($this->emailStatuses !== null) {
            return $this->emailStatuses;
        }
        $actor = $this->actor();

        return $this->emailStatuses = PersonnelWorkbookReconciler::canView($actor)
            ? app(PersonnelWorkbookReconciler::class)->latestEmailStatuses($actor) : [];
    }

    private function applyPersonnelSort(Builder $query): Builder
    {
        // A saved view can outlive a removed tracking column. Preserve valid sort rules safely.
        $allowed = array_keys($this->sortOptions());
        $sorts = array_values(array_filter($this->personnelSortRules, fn ($sort): bool => is_array($sort)
            && in_array($sort['field'] ?? null, $allowed, true)));
        foreach ($this->validatedSort($sorts ?: [['field' => 'name', 'direction' => 'asc']]) as $sort) {
            $this->orderSource($query, $sort['field'], $sort['direction']);
        }

        return $query->orderBy('employees.id');
    }

    private function validatedSort(array $sorts): array
    {
        return Validator::make(['sorts' => $sorts], [
            'sorts' => ['required', 'array', 'min:1', 'max:5'],
            'sorts.*' => ['required', 'array:field,direction'],
            'sorts.*.field' => ['required', 'string', 'distinct', Rule::in(array_keys($this->sortOptions()))],
            'sorts.*.direction' => ['required', Rule::in(['asc', 'desc'])],
        ])->validate()['sorts'];
    }

    private function orderSource(Builder $query, string $key, string $direction): Builder
    {
        $definition = $this->personnelTable();
        if (array_key_exists($key, $this->trackingSortOptions($definition))) {
            return $this->orderTrackingColumn($query, $definition, $key, $direction);
        }

        return $this->personnelSource()->order($query, $key, $direction);
    }

    private function sortOptions(): array
    {
        return array_diff_key(app(PersonnelSource::class)->fields(), ['email_match_status' => true])
            + $this->trackingSortOptions($this->personnelTable());
    }

    private function exportColumns(): array
    {
        $shown = array_keys(array_filter($this->getTable()->getColumns(), fn (Tables\Columns\Column $column): bool => ! $column->isToggledHidden() && ! $column->isHidden()));
        // A value printed beneath another column is exported in its own column, so nothing the page shows is lost.
        $columns = [];
        foreach ($shown as $key) {
            $columns[] = $key;
            foreach (self::STACKED[$key] ?? [] as $companion) {
                $columns[] = $companion;
            }
        }
        $available = array_keys($this->getTable()->getColumns());

        return array_map(fn (string $key): string => str_starts_with($key, 'tracking_') ? substr($key, 9) : $key,
            array_values(array_unique(array_filter($columns, fn (string $key): bool => in_array($key, $available, true)))));
    }

    public function getMaxContentWidth(): MaxWidth
    {
        return MaxWidth::Full;
    }

    private function exportDirectory(array $data): StreamedResponse
    {
        $definition = $this->personnelTable();
        $scope = $data['scope'] ?? 'filtered';
        $query = $scope === 'all' ? $this->applyPersonnelSort(app(PersonnelTableService::class)->members($this->actor(), $definition)) : $this->getFilteredSortedTableQuery();

        $employees = new \Illuminate\Database\Eloquent\Collection(array_map(static function (\Illuminate\Database\Eloquent\Model $record): Employee {
            if (! $record instanceof Employee) {
                throw new \LogicException('A personnel directory export must contain employee records.');
            }

            return $record;
        }, $query->get()->all()));

        return app(PersonnelTableExporter::class)->export($this->actor(), $definition, $employees, $this->exportColumns(), $data['format'], $scope);
    }

    /** One source per request so promotional display values are loaded once, not once per column. */
    private function personnelSource(): PersonnelSource
    {
        return $this->personnelSource ??= app(PersonnelSource::class);
    }

    private function actor(): User
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User && $actor->isAuthenticationAllowed(), 403);

        return $actor;
    }

    private function canExportDirectory(): bool
    {
        try {
            app(PersonnelTableService::class)->authorize($this->actor(), $this->personnelTable(), 'export');

            return true;
        } catch (\Illuminate\Auth\Access\AuthorizationException) {
            return false;
        }
    }

    private function canAccessEmployeeProfiles(): bool
    {
        return $this->employeeProfilesAccessible ??= EmployeeResource::canViewAny();
    }
}
