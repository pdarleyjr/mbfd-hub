<?php

namespace App\Filament\Resources;

use App\Enums\ApparatusPmServiceType;
use App\Enums\ApparatusServiceTicketCategory;
use App\Enums\ApparatusServiceTicketPriority;
use App\Enums\DailyCheckoutChecklistTemplate;
use App\Enums\DailyCheckoutRequirement;
use App\Filament\Concerns\EnterpriseTable;
use App\Filament\Resources\ApparatusResource\Pages;
use App\Filament\Resources\ApparatusResource\RelationManagers;
use App\Jobs\SyncApparatusToSheetJob;
use App\Models\Apparatus;
use App\Models\User;
use App\Services\ApparatusServiceTicketWorkflowService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Support\Enums\Alignment;
use Filament\Tables;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class ApparatusResource extends Resource
{
    use EnterpriseTable;

    protected static ?string $model = Apparatus::class;

    protected static ?string $navigationIcon = 'heroicon-o-truck';

    protected static ?string $navigationGroup = 'Fleet Management';

    protected static ?string $modelLabel = 'Fire Apparatus';

    protected static ?string $navigationLabel = 'Fire Apparatus';

    protected static ?string $pluralModelLabel = 'Fire Apparatus';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Operational Information')
                    ->schema([
                        Forms\Components\TextInput::make('designation')
                            ->label('Designation')
                            ->placeholder('E 1, R 2, L 3, etc.')
                            ->maxLength(255),
                        Forms\Components\TextInput::make('vehicle_number')
                            ->label('Vehicle #')
                            ->maxLength(50),
                        Forms\Components\TextInput::make('class_description')
                            ->label('Class')
                            ->placeholder('ENGINE, RESCUE, LADDER, etc.')
                            ->maxLength(255),
                    ])->columns(3),
                Forms\Components\Section::make('Status & Location')
                    ->schema([
                        Forms\Components\Select::make('station_id')
                            ->relationship('station', 'station_number')
                            ->searchable()
                            ->preload()
                            ->label('Station')
                            ->placeholder('Select Station'),
                        Forms\Components\Select::make('status')
                            ->options([
                                'In Service' => 'In Service',
                                'Out of Service' => 'Out of Service',
                                'Maintenance' => 'Maintenance',
                                'Available' => 'Available',
                                'Reserve' => 'Reserve',
                            ])
                            ->default('In Service'),
                        Forms\Components\Select::make('daily_checkout_requirement')
                            ->label('Daily Checkout Policy')
                            ->options(DailyCheckoutRequirement::options())
                            ->default(DailyCheckoutRequirement::Unknown->value)
                            ->required()
                            ->helperText('Classify explicitly. This does not change the operational status of the apparatus.'),
                        Forms\Components\Select::make('daily_checkout_template')
                            ->label('Approved Daily Checkout Template')
                            ->options(DailyCheckoutChecklistTemplate::options())
                            ->default(DailyCheckoutChecklistTemplate::Pending->value)
                            ->required()
                            ->helperText('Pending permits only approved Engine, Rescue, and Ladder family mapping. Specialty and administrative apparatus remain blocked until an authorized template is selected.'),
                        Forms\Components\TextInput::make('assignment')
                            ->label('Assignment')
                            ->placeholder('Station 1, Reserve, etc.')
                            ->maxLength(255),
                        Forms\Components\TextInput::make('current_location')
                            ->label('Current Location')
                            ->placeholder('Station 1, Fire Fleet, etc.')
                            ->maxLength(255),
                        Forms\Components\DatePicker::make('last_service_date'),
                    ])->columns(4),
                Forms\Components\Textarea::make('notes')
                    ->columnSpanFull(),
                Forms\Components\Section::make('Vehicle Details')
                    ->schema([
                        Forms\Components\Grid::make(4)
                            ->schema([
                                Forms\Components\TextInput::make('unit_id')
                                    ->label('Unit ID')
                                    ->maxLength(255),
                                Forms\Components\TextInput::make('vin')
                                    ->label('VIN')
                                    ->maxLength(255)
                                    ->columnSpan(2),
                                Forms\Components\TextInput::make('year')
                                    ->numeric()
                                    ->placeholder('YYYY'),
                            ]),
                        Forms\Components\Grid::make(4)
                            ->schema([
                                Forms\Components\TextInput::make('make')
                                    ->maxLength(255),
                                Forms\Components\TextInput::make('model')
                                    ->maxLength(255),
                                Forms\Components\TextInput::make('mileage')
                                    ->numeric()
                                    ->label('Original Mileage')
                                    ->helperText('Historical mileage record'),
                            ]),
                    ])->columns(1)
                    ->collapsed(),
                Forms\Components\Section::make('Current Meter Readings')
                    ->schema([
                        Forms\Components\Grid::make(4)
                            ->schema([
                                Forms\Components\TextInput::make('current_engine_hours')
                                    ->label('Engine Hours')
                                    ->numeric()
                                    ->step(0.1)
                                    ->suffix(' hrs')
                                    ->helperText('Latest engine hour meter reading'),
                                Forms\Components\TextInput::make('current_miles')
                                    ->label('Mileage')
                                    ->numeric()
                                    ->suffix(' mi')
                                    ->helperText('Latest odometer reading'),
                                Forms\Components\Select::make('last_service_type')
                                    ->label('Last Service Type')
                                    ->options(ApparatusPmServiceType::options())
                                    ->helperText('Most recent service type'),
                                Forms\Components\DatePicker::make('last_service_date')
                                    ->label('Service Date')
                                    ->helperText('Date of last service'),
                            ]),
                    ])->columns(1)
                    ->visible(fn () => auth()->user()?->can('manage_pm_settings') ?? true),
                Forms\Components\Section::make('Preventative Maintenance Tracking')
                    ->schema([
                        Forms\Components\Grid::make(3)
                            ->schema([
                                Forms\Components\DatePicker::make('last_pm_date')
                                    ->label('Last PM Date')
                                    ->helperText('Date of last PM service'),
                                Forms\Components\TextInput::make('last_pm_mileage')
                                    ->label('Mileage at Last PM')
                                    ->numeric()
                                    ->suffix(' mi'),
                                Forms\Components\TextInput::make('last_pm_engine_hours')
                                    ->label('Engine Hours at Last PM')
                                    ->numeric()
                                    ->step(0.1)
                                    ->suffix(' hrs'),
                            ]),
                        Forms\Components\Grid::make(2)
                            ->schema([
                                Forms\Components\TextInput::make('pm_interval_miles')
                                    ->label('PM Interval (Miles)')
                                    ->numeric()
                                    ->default(5000),
                                Forms\Components\TextInput::make('pm_interval_hours')
                                    ->label('PM Interval (Hours)')
                                    ->numeric()
                                    ->default(300),
                            ]),
                    ])
                    ->columns(1)
                    ->collapsed()
                    ->visible(fn () => auth()->user()?->can('manage_pm_settings') ?? true),
            ]);
    }

    public static function table(Table $table): Table
    {
        return self::applyEnterpriseDefaults($table)
            ->columns([
                // ── Default visible columns (5 max for alignment) ──
                Tables\Columns\TextColumn::make('designation')
                    ->label('Unit')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->alignment(Alignment::Center)
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('vehicle_number')
                    ->label('Veh #')
                    ->searchable()
                    ->alignment(Alignment::Center)
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->alignment(Alignment::Center)
                    ->color(fn (?string $state): string => match ($state) {
                        'In Service' => 'success',
                        'Out of Service' => 'danger',
                        'Maintenance' => 'warning',
                        'Available' => 'info',
                        'Reserve' => 'gray',
                        default => 'gray',
                    })
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('daily_checkout_requirement')
                    ->label('Daily Checkout')
                    ->badge()
                    ->getStateUsing(fn (Apparatus $record): string => $record->daily_checkout_requirement?->value ?? DailyCheckoutRequirement::Unknown->value)
                    ->formatStateUsing(fn (string $state): string => DailyCheckoutRequirement::options()[$state] ?? 'Unknown - needs policy confirmation')
                    ->color(fn (string $state): string => match ($state) {
                        DailyCheckoutRequirement::Required->value => 'success',
                        DailyCheckoutRequirement::Unknown->value => 'warning',
                        default => 'gray',
                    })
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('daily_checkout_template')
                    ->label('Checkout Template')
                    ->badge()
                    ->getStateUsing(fn (Apparatus $record): string => $record->daily_checkout_template?->value ?? DailyCheckoutChecklistTemplate::Pending->value)
                    ->formatStateUsing(fn (string $state): string => DailyCheckoutChecklistTemplate::options()[$state] ?? 'Invalid template configuration')
                    ->color(fn (string $state): string => $state === DailyCheckoutChecklistTemplate::Pending->value ? 'warning' : 'gray')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('pm_health_status')
                    ->label('PM')
                    ->badge()
                    ->alignment(Alignment::Center)
                    ->getStateUsing(function (Apparatus $record): string {
                        $health = $record->getPmHealthStatus();
                        $hours = $health['hours_since_pm'];

                        if ($health['status'] === 'red') {
                            return $health['overdue'] ? "⚠ {$hours}h" : "DUE {$hours}h";
                        }
                        if ($health['status'] === 'yellow') {
                            return "~{$hours}h";
                        }

                        return "{$hours}h";
                    })
                    ->color(function (Apparatus $record): string {
                        $health = $record->getPmHealthStatus();

                        return match ($health['status']) {
                            'red' => 'danger',
                            'yellow' => 'warning',
                            default => 'success',
                        };
                    })
                    ->tooltip(function (Apparatus $record): ?string {
                        $health = $record->getPmHealthStatus();
                        $interval = $health['interval_hours'];
                        $hours = $health['hours_since_pm'];
                        $miles = number_format($health['miles_since_pm']);
                        $lastPm = $health['last_pm_date'] ?? 'Never';

                        return "Hours: {$hours}/{$interval} | Miles since PM: {$miles} | Last PM: {$lastPm}";
                    })
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('location_display')
                    ->label('Location')
                    ->alignment(Alignment::Start)
                    ->getStateUsing(function (Apparatus $record): string {
                        $stationLabel = $record->station ? 'Sta '.$record->station->station_number : null;
                        $assignment = trim($record->assignment ?? '');
                        $currentLoc = trim($record->current_location ?? '');

                        if ($currentLoc && $currentLoc === $assignment) {
                            $currentLoc = '';
                        }

                        if ($currentLoc && $assignment && $currentLoc !== $stationLabel) {
                            return "{$assignment} → {$currentLoc}";
                        }

                        return $currentLoc ?: $assignment ?: $stationLabel ?: '—';
                    })
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->where(function ($q) use ($search) {
                            $q->where('assignment', 'like', "%{$search}%")
                                ->orWhere('current_location', 'like', "%{$search}%");
                        });
                    })
                    ->placeholder('—'),

                // ── Toggleable columns (hidden by default) ──
                Tables\Columns\TextColumn::make('current_engine_hours')
                    ->label('Engine Hrs')
                    ->numeric(decimalPlaces: 1)
                    ->sortable()
                    ->alignment(Alignment::End)
                    ->fontFamily('tabular-nums')
                    ->url(fn (Apparatus $record): string => url("/daily/vehicle-inspections/{$record->slug}"))
                    ->openUrlInNewTab()
                    ->tooltip('Click to submit meter reading')
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('current_miles')
                    ->label('Miles')
                    ->numeric()
                    ->sortable()
                    ->alignment(Alignment::End)
                    ->fontFamily('tabular-nums')
                    ->url(fn (Apparatus $record): string => url("/daily/vehicle-inspections/{$record->slug}"))
                    ->openUrlInNewTab()
                    ->tooltip('Click to submit meter reading')
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('notes')
                    ->label('Comments')
                    ->limit(30)
                    ->alignment(Alignment::Start)
                    ->tooltip(fn ($record) => $record->notes)
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('inspections_count')
                    ->label('Inspections')
                    ->counts('inspections')
                    ->badge()
                    ->alignment(Alignment::Center)
                    ->color('info')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('active_defects_count')
                    ->label('Active Issues')
                    ->getStateUsing(fn (Apparatus $record) => $record->defects()->where('resolved', false)->count())
                    ->badge()
                    ->alignment(Alignment::Center)
                    ->color(fn ($state) => $state > 0 ? 'danger' : 'success')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('class_description')
                    ->label('Class')
                    ->searchable()
                    ->alignment(Alignment::Center)
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('station.station_number')
                    ->label('Station')
                    ->searchable()
                    ->alignment(Alignment::Center)
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('assignment')
                    ->label('Assignment')
                    ->searchable()
                    ->alignment(Alignment::Start)
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('current_location')
                    ->label('Current Location')
                    ->searchable()
                    ->alignment(Alignment::Start)
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('unit_id')
                    ->label('Unit ID')
                    ->searchable()
                    ->alignment(Alignment::Center)
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('make')
                    ->searchable()
                    ->alignment(Alignment::Start)
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('model')
                    ->searchable()
                    ->alignment(Alignment::Start)
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('year')
                    ->numeric()
                    ->sortable()
                    ->alignment(Alignment::Center)
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('mileage')
                    ->numeric()
                    ->sortable()
                    ->alignment(Alignment::End)
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('last_service_date')
                    ->date()
                    ->sortable()
                    ->alignment(Alignment::Center)
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('vin')
                    ->label('VIN')
                    ->searchable()
                    ->alignment(Alignment::Start)
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('reported_at')
                    ->label('Reported')
                    ->dateTime('n/j/Y')
                    ->sortable()
                    ->alignment(Alignment::Center)
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->alignment(Alignment::Center)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('designation')
            ->striped()
            ->filters([
                Tables\Filters\SelectFilter::make('pm_status')
                    ->label('PM Status')
                    ->options([
                        'overdue' => '🔴 Overdue',
                        'due' => '🔴 PM Due',
                        'due_soon' => '🟡 Due Soon',
                        'ok' => '🟢 OK',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        if (! isset($data['value']) || $data['value'] === '') {
                            return $query;
                        }

                        $interval = 300;
                        $warningThreshold = $interval - 50;

                        return match ($data['value']) {
                            'overdue' => $query->whereRaw(
                                '(current_engine_hours - COALESCE(last_pm_engine_hours, 0)) >= ?',
                                [$interval + 5]
                            ),
                            'due' => $query->whereRaw(
                                '(current_engine_hours - COALESCE(last_pm_engine_hours, 0)) >= ? AND (current_engine_hours - COALESCE(last_pm_engine_hours, 0)) < ?',
                                [$interval, $interval + 5]
                            ),
                            'due_soon' => $query->whereRaw(
                                '(current_engine_hours - COALESCE(last_pm_engine_hours, 0)) >= ? AND (current_engine_hours - COALESCE(last_pm_engine_hours, 0)) < ?',
                                [$warningThreshold, $interval]
                            ),
                            'ok' => $query->whereRaw(
                                '(current_engine_hours - COALESCE(last_pm_engine_hours, 0)) < ?',
                                [$warningThreshold]
                            ),
                            default => $query,
                        };
                    }),
                Tables\Filters\SelectFilter::make('station')
                    ->relationship('station', 'station_number')
                    ->searchable()
                    ->preload()
                    ->label('Station'),
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'In Service' => 'In Service',
                        'Out of Service' => 'Out of Service',
                        'Maintenance' => 'Maintenance',
                        'Available' => 'Available',
                        'Reserve' => 'Reserve',
                    ]),
                Tables\Filters\SelectFilter::make('class_description')
                    ->label('Class')
                    ->options(fn () => Apparatus::query()
                        ->whereNotNull('class_description')
                        ->distinct()
                        ->pluck('class_description', 'class_description')
                        ->toArray()),
                Tables\Filters\Filter::make('has_active_issues')
                    ->label('Has Active Issues')
                    ->query(fn (Builder $query) => $query->whereHas('defects', fn ($q) => $q->where('resolved', false))),
            ], layout: FiltersLayout::AboveContent)
            ->headerActions([
                Tables\Actions\Action::make('sync_to_sheet')
                    ->label('Sync to Google Sheet')
                    ->icon('heroicon-o-arrow-up-tray')
                    ->color('success')
                    ->visible(fn () => config('google_sheets.apparatus_sync_enabled'))
                    ->requiresConfirmation()
                    ->modalHeading('Sync Fire Apparatus to Google Sheet')
                    ->modalDescription('This will overwrite the Equipment Maintenance tab with current apparatus data. Continue?')
                    ->action(function () {
                        SyncApparatusToSheetJob::dispatch();
                        \Filament\Notifications\Notification::make()
                            ->title('Sync Queued')
                            ->body('The apparatus data will be synced to the Equipment Maintenance sheet shortly.')
                            ->success()
                            ->send();
                    }),
            ])
            ->actions([
                Tables\Actions\Action::make('daily_checkout')
                    ->label('Daily Checkout')
                    ->icon('heroicon-o-clipboard-document-check')
                    ->color('success')
                    ->visible(fn (Apparatus $record): bool => $record->daily_checkout_requirement === DailyCheckoutRequirement::Required || $record->daily_checkout_requirement === DailyCheckoutRequirement::Unknown)
                    ->disabled(fn (Apparatus $record): bool => ! $record->isDailyCheckoutRequired() || ! filled($record->slug))
                    ->tooltip(fn (Apparatus $record): string => $record->isDailyCheckoutRequired()
                        ? 'Open the exact Daily Checkout for this apparatus'
                        : 'Classify the Daily Checkout policy before starting a checkout')
                    ->url(fn (Apparatus $record): string => url("/daily/vehicle-inspections/{$record->slug}"))
                    ->openUrlInNewTab(),
                Tables\Actions\Action::make('view_inspections')
                    ->label('Inspections')
                    ->icon('heroicon-o-clipboard-document-list')
                    ->color('info')
                    ->tooltip('View all inspections for this apparatus')
                    ->url(fn (Apparatus $record): string => static::getUrl('edit', ['record' => $record])),
                Tables\Actions\Action::make('updateStatus')
                    ->label('Status')
                    ->icon('heroicon-m-arrow-path')
                    ->color('info')
                    ->form([
                        Forms\Components\Select::make('status')
                            ->options([
                                'In Service' => 'In Service',
                                'Out of Service' => 'Out of Service',
                                'Maintenance' => 'Maintenance',
                                'Available' => 'Available',
                                'Reserve' => 'Reserve',
                            ])
                            ->default(fn ($record) => $record->status),
                    ])
                    ->action(function (Apparatus $record, array $data) {
                        /** @var User $actor */
                        $actor = auth()->user();
                        app(ApparatusServiceTicketWorkflowService::class)
                            ->changeOperationalStatus($record, $actor, $data['status']);

                        \Filament\Notifications\Notification::make()
                            ->title('Status Updated')
                            ->success()
                            ->body("Status changed to: {$data['status']}")
                            ->send();
                    }),
                Tables\Actions\Action::make('scheduleService')
                    ->label('Schedule Service')
                    ->icon('heroicon-o-calendar-days')
                    ->color('primary')
                    ->modalHeading(fn (Apparatus $record): string => 'Schedule Service · '.($record->designation ?: $record->name))
                    ->modalSubmitActionLabel('Create Scheduled Ticket')
                    ->form([
                        Forms\Components\Hidden::make('client_submission_id')
                            ->default(fn (): string => (string) Str::uuid())
                            ->required(),
                        Forms\Components\Select::make('category')
                            ->options(ApparatusServiceTicketCategory::options())
                            ->default('repair_mechanical')
                            ->required(),
                        Forms\Components\Select::make('priority')
                            ->options(ApparatusServiceTicketPriority::options())
                            ->default('routine')
                            ->required(),
                        Forms\Components\TextInput::make('service_type')
                            ->label('Service Type')
                            ->placeholder('Example: PMA, pump repair, aerial inspection')
                            ->maxLength(255)
                            ->required(),
                        Forms\Components\TextInput::make('title')
                            ->label('Service Summary')
                            ->minLength(5)
                            ->maxLength(255)
                            ->required(),
                        Forms\Components\Textarea::make('description')
                            ->label('Service Scope / Observed Issue')
                            ->minLength(10)
                            ->maxLength(10000)
                            ->rows(4)
                            ->required(),
                        Forms\Components\DateTimePicker::make('scheduled_for')->required(),
                        Forms\Components\TextInput::make('scheduled_location')
                            ->label('Service Location')
                            ->helperText('Use the established Fleet/location name. No default is assumed.')
                            ->maxLength(255)
                            ->required(),
                        Forms\Components\DateTimePicker::make('expected_return_at')
                            ->label('Expected Return')
                            ->afterOrEqual('scheduled_for'),
                        Forms\Components\Select::make('assigned_to_user_id')
                            ->label('Assigned To')
                            ->options(fn (): array => User::query()->orderBy('name')->pluck('name', 'id')->all())
                            ->searchable(),
                        Forms\Components\TextInput::make('assigned_vendor')->maxLength(255),
                        Forms\Components\Textarea::make('public_note')
                            ->label('Public Update')
                            ->helperText('Visible at the origin station and during vehicle checkout.')
                            ->rows(2)
                            ->required(),
                        Forms\Components\Textarea::make('internal_note')
                            ->label('Internal Note')
                            ->helperText('Visible only to authorized Fleet and administrators.')
                            ->rows(2),
                    ])
                    ->action(function (Apparatus $record, array $data): void {
                        /** @var User $actor */
                        $actor = auth()->user();
                        $data['status'] = 'scheduled';
                        $result = app(ApparatusServiceTicketWorkflowService::class)
                            ->createFleetTicket($record, $actor, $data);

                        \Filament\Notifications\Notification::make()
                            ->title($result->created ? 'Service Scheduled' : 'Service Ticket Already Exists')
                            ->success()
                            ->body("{$result->ticket->ticket_number}: {$result->ticket->title}")
                            ->send();
                    }),
                Tables\Actions\Action::make('logPmService')
                    ->label('Log PM')
                    ->icon('heroicon-o-wrench-screwdriver')
                    ->color(fn (Apparatus $record): string => $record->isPmDue() ? 'danger' : 'gray')
                    ->tooltip('Log a completed PM service')
                    ->form([
                        Forms\Components\Hidden::make('client_submission_id')
                            ->default(fn (): string => (string) Str::uuid())
                            ->required(),
                        Forms\Components\Placeholder::make('current_readings')
                            ->label('Current Meter Readings')
                            ->content(function (Apparatus $record): string {
                                $hours = $record->current_engine_hours ?? 'N/A';
                                $miles = number_format($record->current_miles ?? 0);
                                $health = $record->getPmHealthStatus();

                                return "Engine Hours: {$hours} | Miles: {$miles} | Hours since PM: {$health['hours_since_pm']}h";
                            }),
                        Forms\Components\DatePicker::make('service_date')
                            ->label('Service Date')
                            ->default(now())
                            ->required(),
                        Forms\Components\Select::make('service_type')
                            ->label('Service Type')
                            ->options(ApparatusPmServiceType::options())
                            ->required(),
                        Forms\Components\TextInput::make('service_engine_hours')
                            ->label('Engine Hours at Service')
                            ->numeric()
                            ->step(0.1)
                            ->default(fn (Apparatus $record) => $record->current_engine_hours)
                            ->helperText('Defaults to current reading. Adjust if service was at a different reading.'),
                        Forms\Components\TextInput::make('service_mileage')
                            ->label('Mileage at Service')
                            ->numeric()
                            ->default(fn (Apparatus $record) => $record->current_miles)
                            ->helperText('Defaults to current reading.'),
                        Forms\Components\Textarea::make('service_notes')
                            ->label('Service Notes')
                            ->placeholder('Additional service details...'),
                    ])
                    ->action(function (Apparatus $record, array $data) {
                        /** @var User $actor */
                        $actor = auth()->user();
                        $result = app(ApparatusServiceTicketWorkflowService::class)
                            ->logPmService($record, $actor, $data);
                        $unit = $record->designation ?? $record->vehicle_number;
                        $serviceHours = $result->ticket->service_engine_hours ?? $record->current_engine_hours ?? 0;
                        \Filament\Notifications\Notification::make()
                            ->title($result->created ? 'PM Service Logged' : 'PM Service Already Logged')
                            ->success()
                            ->body("{$unit}: {$result->ticket->ticket_number} recorded. Next service due at ".round((float) $serviceHours + ($record->pm_interval_hours ?? 300), 1).'h')
                            ->send();
                    }),
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\InspectionsRelationManager::class,
            RelationManagers\DefectsRelationManager::class,
            RelationManagers\ServiceTicketsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListApparatuses::route('/'),
            'create' => Pages\CreateApparatus::route('/create'),
            'edit' => Pages\EditApparatus::route('/{record}/edit'),
            'view-inspection' => Pages\ViewInspection::route('/{record}/inspections/{inspection}'),
        ];
    }
}
