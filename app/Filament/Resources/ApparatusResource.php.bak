<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ApparatusResource\Pages;
use App\Filament\Resources\ApparatusResource\RelationManagers;
use App\Models\Apparatus;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
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
                            ->relationship('station', 'name')
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
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('designation')
                    ->label('Designation')
                    ->searchable()
                    ->sortable()
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('vehicle_number')
                    ->label('Vehicle #')
                    ->searchable()
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('class_description')
                    ->label('Class')
                    ->searchable()
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('station.name')
                    ->label('Station')
                    ->searchable()
                    ->sortable()
                    ->state(fn (Apparatus $record): ?string => $record->station?->name)
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('assignment')
                    ->label('Assignment')
                    ->searchable()
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('current_location')
                    ->label('Current Location')
                    ->searchable()
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'In Service' => 'success',
                        'Out of Service' => 'danger',
                        'Maintenance' => 'warning',
                        'Available' => 'info',
                        'Reserve' => 'gray',
                        default => 'gray',
                    })
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('notes')
                    ->limit(30)
                    ->tooltip(fn ($record) => $record->notes)
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('inspections_count')
                    ->label('Inspections')
                    ->counts('inspections')
                    ->badge()
                    ->color('info'),
                Tables\Columns\TextColumn::make('active_defects_count')
                    ->label('Active Issues')
                    ->getStateUsing(fn (Apparatus $record) => $record->defects()->where('resolved', false)->count())
                    ->badge()
                    ->color(fn ($state) => $state > 0 ? 'danger' : 'success'),
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
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('designation')
            ->striped()
            ->filters([
                Tables\Filters\SelectFilter::make('station')
                    ->relationship('station', 'name')
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
            ])
            ->actions([
                Tables\Actions\Action::make('start_daily_checkout')
                    ->label('Daily Checkout')
                    ->icon('heroicon-o-play-circle')
                    ->color('success')
                    ->tooltip('Start daily checkout for this apparatus')
                    ->url(fn (Apparatus $record): string => "/daily/{$record->id}")
                    ->openUrlInNewTab(),
                Tables\Actions\Action::make('updateStatus')
                    ->label('Update Status')
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
        ];
    }
}
