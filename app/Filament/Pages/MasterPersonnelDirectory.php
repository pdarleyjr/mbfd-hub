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

    /** The one personnel list. The class name and /admin/master-personnel-directory stay for saved links. */
    protected static ?string $title = 'Personnel Directory';

    protected static ?string $navigationLabel = 'Personnel Directory';

    protected static ?int $navigationSort = 10;

    /** Login and invitation columns: shown only to people who may open Employees & Access profiles. */
    private const ACCOUNT_COLUMNS = ['login', 'invitation'];

    protected static string $view = 'filament.pages.master-personnel-directory';

    /** Columns whose values also appear beneath another column (main column => companions). */
    private const STACKED = ['shift' => ['a_day'], 'unit' => ['bid_position', 'division'], 'city_email' => ['email_match_status']];

    /** Which columns stay in the row longest (lower is more important). The first column is always kept. */
    private const PRIORITY = ['name' => 0, 'employee_id' => 1, 'rank' => 2, 'shift' => 3, 'station' => 4, 'unit' => 5, 'city_email' => 6, 'phone' => 7, 'display_name' => 8];

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
            // Login and invitation summaries come from two eager loads per page, never one query per row.
            ->modifyQueryUsing(fn (Builder $query): Builder => $this->canAccessEmployeeProfiles()
                ? $query->with('user.memberOnboardingInvitation') : $query)
            ->columns([...$columns, ...$this->accountColumns(), ...$this->trackingColumns($definition)])
            ->recordClasses(fn (Employee $record): string => $this->trackingRecordClasses($record))
            // A row opens the member's profile for people who may open profiles; everyone else keeps Details.
            ->recordUrl(fn (Employee $record): ?string => $this->profileUrl($record))
            ->recordAction(fn (Employee $record): ?string => $this->profileUrl($record) === null ? 'details' : null)
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
                Tables\Filters\SelectFilter::make('login_status')->label('Login status')
                    ->visible(fn (): bool => $this->canAccessEmployeeProfiles())
                    ->options([
                        'awaiting_account' => 'Awaiting account', 'pending_activation' => 'Awaiting activation',
                        'active' => 'Active logins', 'disabled' => 'Disabled logins',
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $this->filterLoginStatus($query, $data['value'] ?? null)),
                ...$this->trackingFilters($definition),
            ])
            ->actions([
                Tables\Actions\Action::make('details')->label('Details')->icon('heroicon-o-eye')->iconButton()->tooltip('Details: every field for this member')
                    ->modalHeading(fn (Employee $record): string => $record->name)
                    ->modalContent(fn (Employee $record) => view('filament.pages.partials.personnel-table-member-details', [
                        'fields' => $source->fields(), 'record' => $record, 'source' => $source,
                        'emailStatus' => $this->statuses()[$record->getKey()] ?? '',
                        'trackingColumns' => $this->visibleTrackingColumns($this->personnelTable()),
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
            ActionGroup::make([
                Action::make('accountsOnboarding')->label('Accounts & Onboarding')->icon('heroicon-o-envelope')
                    ->visible(fn (): bool => $this->canAccessEmployeeProfiles())
                    ->url(fn (): string => EmployeeResource::getUrl('index')),
                Action::make('accountExceptions')->label('Account exceptions')->icon('heroicon-o-exclamation-triangle')
                    ->visible(fn (): bool => \App\Filament\Resources\AccountProfileResource::canViewAny())
                    ->url(fn (): string => \App\Filament\Resources\AccountProfileResource::getUrl()),
            ])->label('Accounts')->icon('heroicon-o-key')->button()->color('gray')
                ->visible(fn (): bool => $this->canAccessEmployeeProfiles() || \App\Filament\Resources\AccountProfileResource::canViewAny()),
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
        // Responsive tables (resources/js/hub-tables.js): lower priority columns move into the row's details panel
        // when the table is short of room, and rows stack as cards on phones. Every column stays sortable and editable.
        $column->extraHeaderAttributes(['data-hub-priority' => self::PRIORITY[$key] ?? 20] + ($key === 'city_email' ? ['data-hub-min' => '12'] : []));
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
        $shown = array_keys(array_filter($this->getTable()->getColumns(), fn (Tables\Columns\Column $column): bool => ! $column->isToggledHidden() && ! $column->isHidden()
            && ! in_array($column->getName(), self::ACCOUNT_COLUMNS, true)));
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

    public function canOpenProfiles(): bool
    {
        return $this->canAccessEmployeeProfiles();
    }

    /** The member's profile, or null when this viewer keeps the Details view instead. */
    private function profileUrl(Employee $record): ?string
    {
        return $this->canAccessEmployeeProfiles() && EmployeeResource::canEdit($record)
            ? EmployeeResource::getUrl('edit', ['record' => $record]) : null;
    }

    /**
     * Login and invitation status from Accounts & Onboarding, hidden until turned on. Never exported: the
     * directory export carries personnel and tracking columns only.
     *
     * @return list<Tables\Columns\Column>
     */
    private function accountColumns(): array
    {
        return [
            Tables\Columns\TextColumn::make('login')->label('Login')->badge()
                ->state(fn (Employee $record): string => self::loginLabel($record->user?->getRawOriginal('account_status')))
                ->color(fn (string $state): string => match ($state) {
                    'Active' => 'success', 'Disabled' => 'danger', 'Awaiting activation' => 'warning', default => 'gray',
                })
                ->visible(fn (): bool => $this->canAccessEmployeeProfiles())
                ->toggleable(isToggledHiddenByDefault: true),
            Tables\Columns\TextColumn::make('invitation')->label('Invitation')->badge()->color('gray')
                ->state(fn (Employee $record): string => self::invitationLabel($record))
                ->description(fn (Employee $record): ?string => $record->user?->memberOnboardingInvitation?->emailDeliveryLabel())
                ->visible(fn (): bool => $this->canAccessEmployeeProfiles())
                ->toggleable(isToggledHiddenByDefault: true),
        ];
    }

    private static function loginLabel(?string $status): string
    {
        return match ($status) {
            null => 'Awaiting account',
            'pending_activation' => 'Awaiting activation',
            default => ucfirst(str_replace('_', ' ', $status)),
        };
    }

    /** The same activation wording Accounts & Onboarding shows. */
    private static function invitationLabel(Employee $record): string
    {
        $status = $record->user?->getRawOriginal('account_status');
        if ($status === 'active') {
            return 'Account activated';
        }
        if ($status !== 'pending_activation') {
            return 'Not applicable';
        }
        $invitation = $record->user->memberOnboardingInvitation;
        if ($invitation === null) {
            return 'Invitation not sent';
        }
        if (in_array($invitation->delivery_status, ['pending', 'queued', 'redeemed'], true)
            && $invitation->expires_at?->lessThanOrEqualTo(now()) === true) {
            return 'Expired, ready to resend';
        }

        return $invitation->redeemed_at !== null || $invitation->delivery_status === 'redeemed'
            ? 'Opened / redeemed' : 'Awaiting activation';
    }

    /** The Accounts & Onboarding tabs as one filter. */
    private function filterLoginStatus(Builder $query, mixed $value): Builder
    {
        if (! $this->canAccessEmployeeProfiles() || ! is_string($value) || $value === '') {
            return $query;
        }

        return match ($value) {
            'awaiting_account' => $query->whereDoesntHave('user'),
            'pending_activation', 'active', 'disabled' => $query->whereHas('user', fn (Builder $users) => $users->where('account_status', $value)),
            default => $query,
        };
    }
}
