<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ApparatusResource\Pages;
use App\Filament\Resources\ApparatusResource\RelationManagers;
use App\Jobs\SyncApparatusToSheetJob;
use App\Models\Apparatus;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class ApparatusResource extends Resource
{
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
                                'Available' => 'Available',
                                'Reserve' => 'Reserve',
                                'Maintenance' => 'Maintenance',
                            ])
                            ->default('In Service'),
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
                        Forms\Components\TextInput::make('unit_id')
                            ->label('Unit ID')
                            ->maxLength(255),
                        Forms\Components\TextInput::make('vin')
                            ->label('VIN')
                            ->maxLength(255),
                        Forms\Components\TextInput::make('make')
                            ->maxLength(255),
                        Forms\Components\TextInput::make('model')
                            ->maxLength(255),
                        Forms\Components\TextInput::make('year')
                            ->numeric(),
                        Forms\Components\TextInput::make('mileage')
                            ->numeric(),
                    ])->columns(3)
                    ->collapsed(),
                Forms\Components\Section::make('Preventative Maintenance Tracking')
                    ->schema([
                        Forms\Components\Grid::make(3)
                            ->schema([
                                Forms\Components\TextInput::make('current_engine_hours')
                                    ->label('Current Engine Hours')
                                    ->numeric()
                                    ->step(0.1)
                                    ->helperText('Latest engine hour meter reading'),
                                Forms\Components\TextInput::make('current_miles')
                                    ->label('Current Mileage')
                                    ->numeric()
                                    ->helperText('Latest odometer reading'),
                                Forms\Components\DatePicker::make('last_pm_date')
                                    ->label('Last PM Date')
                                    ->helperText('Date of last PM service'),
                            ]),
                        Forms\Components\Grid::make(3)
                            ->schema([
                                Forms\Components\TextInput::make('last_pm_mileage')
                                    ->label('Mileage at Last PM')
                                    ->numeric(),
                                Forms\Components\TextInput::make('last_pm_engine_hours')
                                    ->label('Engine Hours at Last PM')
                                    ->numeric()
                                    ->step(0.1),
                                Forms\Components\Select::make('last_service_type')
                                    ->label('Last Service Type')
                                    ->options([
                                        '300-Hour PM' => '300-Hour PM',
                                        'Annual Inspection' => 'Annual Inspection',
                                        'Chassis Service' => 'Chassis Service',
                                    ]),
                            ]),
                        Forms\Components\Grid::make(2)
                            ->schema([
                                Forms\Components\TextInput::make('pm_interval_miles')
                                    ->label('PM Interval (Miles)')
                                    ->numeric(),
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
        return $table
            ->columns([
                // ── Default visible columns (5 max for alignment) ──
                Tables\Columns\TextColumn::make('designation')
                    ->label('Unit')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->grow(false)
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('vehicle_number')
                    ->label('Veh #')
                    ->searchable()
                    ->grow(false)
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->grow(false)
                    ->color(fn (?string $state): string => match ($state) {
                        'In Service' => 'success',
                        'Out of Service' => 'danger',
                        'Maintenance' => 'warning',
                        'Available' => 'info',
                        'Reserve' => 'gray',
                        default => 'gray',
                    })
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('pm_health_status')
                    ->label('PM')
                    ->badge()
                    ->grow(false)
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
                    ->getStateUsing(function (Apparatus $record): string {
                        $stationLabel = $record->station ? 'Sta ' . $record->station->station_number : null;
                        $assignment   = trim($record->assignment ?? '');
                        $currentLoc  = trim($record->current_location ?? '');

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
                    ->url(fn (Apparatus $record): string => url("/daily/vehicle-inspections/{$record->slug}"))
                    ->openUrlInNewTab()
                    ->tooltip('Click to submit meter reading')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('current_miles')
                    ->label('Miles')
                    ->numeric()
                    ->sortable()
                    ->url(fn (Apparatus $record): string => url("/daily/vehicle-inspections/{$record->slug}"))
                    ->openUrlInNewTab()
                    ->tooltip('Click to submit meter reading')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('notes')
                    ->label('Comments')
                    ->limit(30)
                    ->tooltip(fn ($record) => $record->notes)
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('inspections_count')
                    ->label('Inspections')
                    ->counts('inspections')
                    ->badge()
                    ->color('info')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('active_defects_count')
                    ->label('Active Issues')
                    ->getStateUsing(fn (Apparatus $record) => $record->defects()->where('resolved', false)->count())
                    ->badge()
                    ->color(fn ($state) => $state > 0 ? 'danger' : 'success')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('class_description')
                    ->label('Class')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('station.station_number')
                    ->label('Station')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('assignment')
                    ->label('Assignment')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('current_location')
                    ->label('Current Location')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('unit_id')
                    ->label('Unit ID')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('make')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('model')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('year')
                    ->numeric()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('mileage')
                    ->numeric()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('last_service_date')
                    ->date()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('vin')
                    ->label('VIN')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('reported_at')
                    ->label('Reported')
                    ->dateTime('n/j/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
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
                        if (!isset($data['value']) || $data['value'] === '') {
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
                        'Available' => 'Available',
                        'Reserve' => 'Reserve',
                        'Maintenance' => 'Maintenance',
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
                                'Available' => 'Available',
                                'Maintenance' => 'Maintenance',
                                'Reserve' => 'Reserve',
                            ])
                            ->default(fn ($record) => $record->status),
                        Forms\Components\Textarea::make('notes')
                            ->label('Reason / Notes')
                            ->visible(fn ($get) => $get('status') !== 'In Service'),
                    ])
                    ->action(function (Apparatus $record, array $data) {
                        $record->update(['status' => $data['status']]);

                        \Filament\Notifications\Notification::make()
                            ->title('Status Updated')
                            ->success()
                            ->body("Status changed to: {$data['status']}")
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
