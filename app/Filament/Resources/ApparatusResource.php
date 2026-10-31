<?php

namespace App\Filament\Resources;

use App\Enums\ApparatusPmServiceType;
use App\Enums\DailyCheckoutChecklistTemplate;
use App\Enums\DailyCheckoutRequirement;
use App\Filament\Concerns\EnterpriseTable;
use App\Filament\Concerns\HasExportActions;
use App\Filament\Resources\ApparatusResource\ApparatusWorkflowActions;
use App\Filament\Resources\ApparatusResource\Pages;
use App\Filament\Resources\ApparatusResource\RelationManagers;
use App\Jobs\SyncApparatusToSheetJob;
use App\Models\Apparatus;
use App\Models\Station;
use App\Models\UnitMasterVehicle;
use App\Support\Fleet\ApparatusOverview;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Support\Enums\Alignment;
use Filament\Tables;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Fleet -> Apparatus: the operational profile Fleet managers and mechanics
 * work from (status, open work, PM, Daily Checkout, service). Vehicle identity
 * (vehicle #, make, model, year, VIN) is shared with the Master Vehicle
 * Inventory record, which also keeps documents, accidents and history.
 */
class ApparatusResource extends Resource
{
    protected static ?int $navigationSort = 10;

    use EnterpriseTable;
    use HasExportActions;

    protected static ?string $model = Apparatus::class;

    protected static ?string $navigationIcon = 'heroicon-o-truck';

    protected static ?string $navigationGroup = 'Fleet';

    protected static ?string $modelLabel = 'Fire Apparatus';

    protected static ?string $navigationLabel = 'Apparatus';

    protected static ?string $pluralModelLabel = 'Fire Apparatus';

    protected static ?string $recordTitleAttribute = 'designation';

    /** SQL for engine hours since the last PM, shared by the PM tabs and filter. */
    public const HOURS_SINCE_PM_SQL = '(COALESCE(current_engine_hours, 0) - COALESCE(last_pm_engine_hours, 0))';

    /** Each unit's own PM interval; 300 hours when none is set (matches Apparatus::getPmHealthStatus()). */
    public const PM_INTERVAL_SQL = 'COALESCE(pm_interval_hours, 300)';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Overview')
                    ->schema([
                        Forms\Components\View::make('filament.resources.apparatus-resource.partials.overview')
                            ->viewData(fn (?Apparatus $record): array => [
                                'apparatus' => $record,
                                'overview' => $record?->exists ? ApparatusOverview::for($record) : null,
                            ]),
                    ])
                    ->visible(fn (?Apparatus $record): bool => $record?->exists === true),
                Forms\Components\Section::make('Unit')
                    ->schema([
                        Forms\Components\TextInput::make('designation')
                            ->label('Designation')
                            ->placeholder('E 1, R 2, L 3')
                            ->maxLength(255),
                        Forms\Components\TextInput::make('unit_id')
                            ->label('Unit ID')
                            ->maxLength(255),
                        Forms\Components\TextInput::make('class_description')
                            ->label('Class')
                            ->placeholder('ENGINE, RESCUE, LADDER')
                            ->maxLength(255),
                        Forms\Components\Select::make('station_id')
                            ->relationship('station', 'station_number')
                            ->searchable()
                            ->preload()
                            ->label('Station')
                            ->placeholder('Select station'),
                        Forms\Components\TextInput::make('assignment')
                            ->label('Assignment')
                            ->placeholder('Station 1, Reserve')
                            ->maxLength(255),
                        Forms\Components\TextInput::make('current_location')
                            ->label('Current location')
                            ->placeholder('Station 1, Fire Fleet')
                            ->maxLength(255),
                        Forms\Components\Textarea::make('notes')
                            ->label('Fleet notes')
                            ->rows(2)
                            ->columnSpanFull(),
                    ])->columns(3),
                Forms\Components\Section::make('Status and Daily Checkout')
                    ->schema([
                        Forms\Components\Select::make('status')
                            ->label('Operational status')
                            ->options(ApparatusWorkflowActions::STATUS_OPTIONS)
                            ->default('In Service'),
                        Forms\Components\Select::make('daily_checkout_requirement')
                            ->label('Daily Checkout policy')
                            ->options(DailyCheckoutRequirement::options())
                            ->default(DailyCheckoutRequirement::Unknown->value)
                            ->required()
                            ->helperText('Classify explicitly. This does not change the operational status.'),
                        Forms\Components\Select::make('daily_checkout_template')
                            ->label('Approved Daily Checkout template')
                            ->options(DailyCheckoutChecklistTemplate::options())
                            ->default(DailyCheckoutChecklistTemplate::Pending->value)
                            ->required()
                            ->helperText('Pending permits only approved Engine, Rescue, and Ladder family mapping. Specialty and administrative apparatus stay blocked until a template is selected.'),
                    ])->columns(3),
                Forms\Components\Section::make('Vehicle')
                    ->description(fn (?Apparatus $record): string => self::vehicleSectionDescription($record))
                    ->schema([
                        Forms\Components\TextInput::make('vehicle_number')
                            ->label('Vehicle #')
                            ->maxLength(50)
                            ->disabled(fn (?Apparatus $record): bool => self::isLinked($record))
                            ->dehydrated(fn (?Apparatus $record): bool => ! self::isLinked($record))
                            ->helperText(fn (?Apparatus $record): string => self::isLinked($record)
                                ? 'Set by the linked vehicle record. Use "Change vehicle record" under More to point this unit at a different vehicle.'
                                : 'Links this unit to the Master Vehicle Inventory vehicle with the same number.'),
                        Forms\Components\TextInput::make('year')
                            ->numeric()
                            ->minValue(1950)
                            ->maxValue(2100)
                            ->placeholder('YYYY'),
                        Forms\Components\TextInput::make('make')->maxLength(255),
                        Forms\Components\TextInput::make('model')->maxLength(255),
                        Forms\Components\TextInput::make('vin')
                            ->label('VIN')
                            ->maxLength(255)
                            ->columnSpan(2),
                    ])->columns(4),
                Forms\Components\Section::make('Meters and PM')
                    ->schema([
                        Forms\Components\TextInput::make('current_engine_hours')
                            ->label('Engine hours')
                            ->numeric()
                            ->step(0.1)
                            ->suffix('hrs'),
                        Forms\Components\TextInput::make('current_miles')
                            ->label('Odometer')
                            ->numeric()
                            ->suffix('mi'),
                        Forms\Components\Select::make('last_service_type')
                            ->label('Last service type')
                            ->options(ApparatusPmServiceType::options()),
                        Forms\Components\DatePicker::make('last_service_date')
                            ->label('Last service date'),
                        Forms\Components\DatePicker::make('last_pm_date')
                            ->label('Last PM date'),
                        Forms\Components\TextInput::make('last_pm_engine_hours')
                            ->label('Engine hours at last PM')
                            ->numeric()
                            ->step(0.1)
                            ->suffix('hrs'),
                        Forms\Components\TextInput::make('last_pm_mileage')
                            ->label('Odometer at last PM')
                            ->numeric()
                            ->suffix('mi'),
                        Forms\Components\TextInput::make('pm_interval_hours')
                            ->label('PM interval')
                            ->numeric()
                            ->suffix('hrs')
                            ->default(300),
                        Forms\Components\TextInput::make('pm_interval_miles')
                            ->label('PM interval (miles)')
                            ->numeric()
                            ->suffix('mi')
                            ->default(3000),
                        Forms\Components\TextInput::make('mileage')
                            ->label('Historical mileage (legacy record)')
                            ->numeric()
                            ->disabled()
                            ->dehydrated(false)
                            ->visible(fn (?Apparatus $record): bool => (float) $record?->getAttribute('mileage') > 0),
                    ])
                    ->columns(4)
                    ->collapsible()
                    ->visible(fn () => auth()->user()?->can('manage_pm_settings') ?? true),
            ]);
    }

    public static function table(Table $table): Table
    {
        return static::withExportActions(self::applyEnterpriseDefaults($table)
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with(['station', 'masterVehicle'])
                ->withCount([
                    'openDefects as open_defects_count',
                    'serviceTickets as open_tickets_count' => fn ($tickets) => $tickets->open(),
                ]))
            ->columns([
                // Default columns: what a Fleet manager scans for first.
                Tables\Columns\TextColumn::make('designation')
                    ->wrap()
                    ->extraAttributes(['class' => 'hub-phone-summary'])
                    ->description(fn (Apparatus $record): string => self::unitSummary($record))
                    ->label('Unit')
                    ->searchable(['designation', 'vehicle_number', 'unit_id', 'name'])
                    ->sortable()
                    ->weight('bold')
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('vehicle_number')->visibleFrom('md')
                    ->label('Vehicle')
                    ->description(fn (Apparatus $record): string => self::vehicleSummary($record))
                    ->color(fn (Apparatus $record): ?string => $record->master_link_status === Apparatus::MASTER_LINK_NEEDS_REVIEW ? 'warning' : null)
                    ->searchable()
                    ->sortable()
                    ->toggleable()
                    ->placeholder('Not recorded'),
                Tables\Columns\TextColumn::make('status')->visibleFrom('md')
                    ->badge()
                    ->alignment(Alignment::Center)
                    ->color(fn (?string $state): string => self::statusColor($state))
                    ->sortable()
                    ->placeholder('Not set'),
                Tables\Columns\TextColumn::make('open_work')->visibleFrom('md')
                    ->label('Open work')
                    ->badge()
                    ->alignment(Alignment::Center)
                    ->getStateUsing(fn (Apparatus $record): string => self::openWorkLabel($record))
                    ->color(fn (Apparatus $record): string => (int) $record->getAttribute('open_defects_count') > 0
                        ? 'danger'
                        : ((int) $record->getAttribute('open_tickets_count') > 0 ? 'warning' : 'success')),
                Tables\Columns\TextColumn::make('pm_health_status')->visibleFrom('md')
                    ->label('PM')
                    ->badge()
                    ->alignment(Alignment::Center)
                    ->getStateUsing(function (Apparatus $record): string {
                        $health = $record->getPmHealthStatus();
                        $hours = $health['hours_since_pm'];

                        return match (true) {
                            $health['overdue'] => "Overdue {$hours}h",
                            $health['status'] === 'red' => "Due {$hours}h",
                            $health['status'] === 'yellow' => "Soon {$hours}h",
                            default => "{$hours}h",
                        };
                    })
                    ->color(fn (Apparatus $record): string => match ($record->getPmHealthStatus()['status']) {
                        'red' => 'danger',
                        'yellow' => 'warning',
                        default => 'success',
                    })
                    ->tooltip(function (Apparatus $record): string {
                        $health = $record->getPmHealthStatus();
                        $miles = number_format($health['miles_since_pm']);
                        $lastPm = $health['last_pm_date'] ?? 'not recorded';

                        return "{$health['hours_since_pm']} of {$health['interval_hours']} engine hours since PM | {$miles} mi | Last PM: {$lastPm}";
                    }),
                Tables\Columns\TextColumn::make('location_display')->visibleFrom('md')
                    ->label('Location')
                    ->getStateUsing(fn (Apparatus $record): string => self::locationLabel($record))
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where(fn (Builder $q): Builder => $q
                        ->whereRaw('LOWER(assignment) LIKE ?', ['%'.mb_strtolower($search).'%'])
                        ->orWhereRaw('LOWER(current_location) LIKE ?', ['%'.mb_strtolower($search).'%']))),

                // Optional columns.
                Tables\Columns\TextColumn::make('daily_checkout_requirement')->visibleFrom('md')
                    ->label('Daily Checkout')
                    ->badge()
                    ->getStateUsing(fn (Apparatus $record): string => ($record->getAttribute('daily_checkout_requirement') ?? DailyCheckoutRequirement::Unknown)->value)
                    ->formatStateUsing(fn (string $state): string => DailyCheckoutRequirement::options()[$state] ?? 'Unknown - needs policy confirmation')
                    ->color(fn (string $state): string => match ($state) {
                        DailyCheckoutRequirement::Required->value => 'success',
                        DailyCheckoutRequirement::Unknown->value => 'warning',
                        default => 'gray',
                    })
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('daily_checkout_template')->visibleFrom('md')
                    ->label('Checkout template')
                    ->badge()
                    ->getStateUsing(fn (Apparatus $record): string => ($record->getAttribute('daily_checkout_template') ?? DailyCheckoutChecklistTemplate::Pending)->value)
                    ->formatStateUsing(fn (string $state): string => DailyCheckoutChecklistTemplate::options()[$state] ?? 'Invalid template configuration')
                    ->color(fn (string $state): string => $state === DailyCheckoutChecklistTemplate::Pending->value ? 'warning' : 'gray')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('current_engine_hours')->visibleFrom('md')
                    ->label('Engine hrs')
                    ->numeric(decimalPlaces: 1)
                    ->sortable()
                    ->alignment(Alignment::End)
                    ->fontFamily('tabular-nums')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('current_miles')->visibleFrom('md')
                    ->label('Miles')
                    ->numeric()
                    ->sortable()
                    ->alignment(Alignment::End)
                    ->fontFamily('tabular-nums')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('notes')->visibleFrom('md')
                    ->label('Fleet notes')
                    ->limit(30)
                    ->tooltip(fn (Apparatus $record): ?string => $record->getAttribute('notes'))
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('inspections_count')->visibleFrom('md')
                    ->label('Inspections')
                    ->counts(['inspections' => fn (Builder $query): Builder => $query->whereNull('apparatus_inspections.test_excluded_at')])
                    ->badge()
                    ->alignment(Alignment::Center)
                    ->color('info')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('class_description')->visibleFrom('md')
                    ->label('Class')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('station.station_number')->visibleFrom('md')
                    ->label('Station')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('unit_id')->visibleFrom('md')
                    ->label('Unit ID')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('make')->visibleFrom('md')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('model')->visibleFrom('md')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('year')->visibleFrom('md')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('vin')->visibleFrom('md')
                    ->label('VIN')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('last_service_date')->visibleFrom('md')
                    ->label('Last service')
                    ->date()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('reported_at')->visibleFrom('md')
                    ->label('Reported')
                    ->dateTime('n/j/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->placeholder('—'),
            ])
            ->defaultSort('designation')
            ->filters([
                Tables\Filters\SelectFilter::make('pm_status')
                    ->label('PM status')
                    ->options([
                        'overdue' => 'Overdue',
                        'due' => 'Due',
                        'due_soon' => 'Due soon',
                        'ok' => 'OK',
                    ])
                    ->query(fn (Builder $query, array $data): Builder => blank($data['value'] ?? null)
                        ? $query
                        : self::wherePm($query, (string) $data['value'])),
                Tables\Filters\SelectFilter::make('station')
                    ->relationship('station', 'station_number')
                    ->searchable()
                    ->preload()
                    ->label('Station'),
                Tables\Filters\SelectFilter::make('status')
                    ->options(ApparatusWorkflowActions::STATUS_OPTIONS),
                Tables\Filters\SelectFilter::make('class_description')
                    ->label('Class')
                    ->options(fn () => Apparatus::query()
                        ->whereNotNull('class_description')
                        ->distinct()
                        ->pluck('class_description', 'class_description')
                        ->toArray()),
            ], layout: FiltersLayout::AboveContentCollapsible)
            ->headerActions([
                Tables\Actions\Action::make('sync_to_sheet')
                    ->label('Sync to Google Sheet')
                    ->icon('heroicon-o-arrow-up-tray')
                    ->color('gray')
                    ->visible(fn () => config('google_sheets.apparatus_sync_enabled'))
                    ->requiresConfirmation()
                    ->modalHeading('Sync Fire Apparatus to Google Sheet')
                    ->modalDescription('This will overwrite the Equipment Maintenance tab with current apparatus data. Continue?')
                    ->action(function () {
                        SyncApparatusToSheetJob::dispatch();
                        \Filament\Notifications\Notification::make()
                            ->title('Sync queued')
                            ->body('The apparatus data will be synced to the Equipment Maintenance sheet shortly.')
                            ->success()
                            ->send();
                    }),
            ])
            ->recordUrl(fn (Apparatus $record): string => static::getUrl('view', ['record' => $record]))
            ->actions([
                Tables\Actions\ActionGroup::make([
                    ApparatusWorkflowActions::changeStatus(Tables\Actions\Action::make('updateStatus')),
                    ApparatusWorkflowActions::scheduleService(Tables\Actions\Action::make('scheduleService')),
                    ApparatusWorkflowActions::logPm(Tables\Actions\Action::make('logPmService')),
                    ApparatusWorkflowActions::scheduleChangeout(Tables\Actions\Action::make('scheduleChangeout')),
                    ApparatusWorkflowActions::addOperationalAlert(Tables\Actions\Action::make('addOperationalAlert')),
                    ApparatusWorkflowActions::dailyCheckout(Tables\Actions\Action::make('daily_checkout')),
                    ApparatusWorkflowActions::masterRecord(Tables\Actions\Action::make('vehicle_record')),
                    Tables\Actions\EditAction::make(),
                ])->label('Actions')->icon('heroicon-m-ellipsis-horizontal')->button()->color('gray'),
            ]));
    }

    /** Engine-hour PM windows, using each unit's own interval. */
    public static function wherePm(Builder $query, string $window): Builder
    {
        $since = self::HOURS_SINCE_PM_SQL;
        $interval = self::PM_INTERVAL_SQL;

        return match ($window) {
            'overdue' => $query->whereRaw("{$since} >= {$interval} + 5"),
            'due' => $query->whereRaw("{$since} >= {$interval} AND {$since} < {$interval} + 5"),
            'due_soon' => $query->whereRaw("{$since} >= {$interval} - 50 AND {$since} < {$interval}"),
            'attention' => $query->whereRaw("{$since} >= {$interval} - 50"),
            'ok' => $query->whereRaw("{$since} < {$interval} - 50"),
            default => $query,
        };
    }

    public static function statusColor(?string $status): string
    {
        return match ($status) {
            'In Service', 'in_service' => 'success',
            'Out of Service', 'out_of_service', 'in_repair' => 'danger',
            'Maintenance', 'maintenance' => 'warning',
            'Available' => 'info',
            default => 'gray',
        };
    }

    public static function isLinked(?Apparatus $record): bool
    {
        return $record?->unit_master_vehicle_id !== null;
    }

    private static function vehicleSectionDescription(?Apparatus $record): string
    {
        $master = $record?->masterVehicle;
        if ($master instanceof UnitMasterVehicle) {
            return 'Shared with Master Vehicle Inventory #'.$master->veh_number.'. Saving here updates the vehicle record too.';
        }

        return 'Saved with the Master Vehicle Inventory record for this vehicle number.';
    }

    private static function unitSummary(Apparatus $record): string
    {
        $vehicle = trim(implode(' ', array_filter([$record->getAttribute('year'), $record->getAttribute('make'), $record->getAttribute('model')])));
        $parts = ['Veh '.($record->vehicle_number ?: 'not recorded')];
        if ($vehicle !== '') {
            $parts[] = $vehicle;
        }
        if ($record->master_link_status === Apparatus::MASTER_LINK_NEEDS_REVIEW) {
            $parts[] = 'Vehicle record needs review';
        }

        return implode(' · ', $parts);
    }

    /** Desktop vehicle column detail: year/make/model, or why the vehicle record needs Fleet. */
    private static function vehicleSummary(Apparatus $record): string
    {
        if ($record->master_link_status === Apparatus::MASTER_LINK_NEEDS_REVIEW) {
            return 'Vehicle record needs review';
        }

        return trim(implode(' ', array_filter([$record->getAttribute('year'), $record->getAttribute('make'), $record->getAttribute('model')]))) ?: 'Year, make and model not recorded';
    }

    private static function openWorkLabel(Apparatus $record): string
    {
        $defects = (int) $record->getAttribute('open_defects_count');
        $tickets = (int) $record->getAttribute('open_tickets_count');
        if ($defects === 0 && $tickets === 0) {
            return 'Clear';
        }

        return implode(' · ', array_filter([
            $defects > 0 ? $defects.' '.($defects === 1 ? 'issue' : 'issues') : null,
            $tickets > 0 ? $tickets.' '.($tickets === 1 ? 'ticket' : 'tickets') : null,
        ]));
    }

    private static function locationLabel(Apparatus $record): string
    {
        $stationLabel = $record->station instanceof Station ? 'Sta '.$record->station->station_number : null;
        $assignment = trim((string) $record->assignment);
        $current = trim((string) $record->current_location);
        if ($current !== '' && $current === $assignment) {
            $current = '';
        }
        if ($current !== '' && $assignment !== '' && $current !== $stationLabel) {
            return "{$assignment} → {$current}";
        }

        return $current ?: $assignment ?: $stationLabel ?: '—';
    }

    /** @return array<int, string> */
    public static function getGloballySearchableAttributes(): array
    {
        return ['designation', 'vehicle_number', 'unit_id', 'vin'];
    }

    /** @return array<string, string> */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        /** @var Apparatus $record */
        return array_filter([
            'Vehicle #' => (string) $record->vehicle_number,
            'Status' => (string) $record->getAttribute('status'),
        ]);
    }

    public static function getGlobalSearchResultUrl(Model $record): string
    {
        return static::getUrl('view', ['record' => $record]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\InspectionsRelationManager::class,
            RelationManagers\ServiceTicketsRelationManager::class,
            RelationManagers\VehicleSwapReportsRelationManager::class,
            RelationManagers\OperationalAlertsRelationManager::class,
            RelationManagers\ChangeoutsRelationManager::class,
            RelationManagers\DefectsRelationManager::class,
            RelationManagers\InventoryCorrectionsRelationManager::class,
            // A rare Fleet task, so it follows the everyday tabs.
            RelationManagers\IdentificationQuestionsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListApparatuses::route('/'),
            'create' => Pages\CreateApparatus::route('/create'),
            'view' => Pages\ViewApparatus::route('/{record}'),
            'edit' => Pages\EditApparatus::route('/{record}/edit'),
            'view-inspection' => Pages\ViewInspection::route('/{record}/inspections/{inspection}'),
        ];
    }
}
