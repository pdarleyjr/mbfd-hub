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

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Basic Information')
                    ->schema([
                        Forms\Components\TextInput::make('unit_id')
                            ->label('Unit ID')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('vehicle_number')
                            ->label('Vehicle #')
                            ->maxLength(50),
                        Forms\Components\TextInput::make('vin')
                            ->label('VIN')
                            ->maxLength(255),
                    ])->columns(3),
                Forms\Components\Section::make('Vehicle Details')
                    ->schema([
                        Forms\Components\TextInput::make('make')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('model')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('year')
                            ->numeric(),
                        Forms\Components\TextInput::make('mileage')
                            ->required()
                            ->numeric()
                            ->default(0),
                    ])->columns(4),
                Forms\Components\Section::make('Status & Location')
                    ->schema([
                        Forms\Components\Select::make('status')
                            ->options([
                                'In Service' => 'In Service',
                                'Out of Service' => 'Out of Service',
                                'Reserve' => 'Reserve',
                                'Maintenance' => 'Maintenance',
                            ])
                            ->required()
                            ->default('In Service'),
                        Forms\Components\TextInput::make('location')
                            ->maxLength(255),
                        Forms\Components\TextInput::make('assignment')
                            ->maxLength(255),
                        Forms\Components\DatePicker::make('last_service_date'),
                    ])->columns(4),
                Forms\Components\Textarea::make('notes')
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('unit_id')
                    ->label('Unit ID')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('vehicle_number')
                    ->label('Vehicle #')
                    ->searchable(),
                Tables\Columns\TextColumn::make('make')
                    ->searchable(),
                Tables\Columns\TextColumn::make('model')
                    ->searchable(),
                Tables\Columns\TextColumn::make('year')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('mileage')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('location')
                    ->searchable(),
                Tables\Columns\TextColumn::make('assignment')
                    ->searchable(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'In Service' => 'success',
                        'Out of Service' => 'danger',
                        'Maintenance' => 'warning',
                        'Reserve' => 'gray',
                        default => 'gray',
                    }),
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
                Tables\Columns\TextColumn::make('last_service_date')
                    ->date()
                    ->sortable(),
                Tables\Columns\TextColumn::make('vin')
                    ->label('VIN')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('unit_id')
            ->striped()
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'In Service' => 'In Service',
                        'Out of Service' => 'Out of Service',
                        'Reserve' => 'Reserve',
                        'Maintenance' => 'Maintenance',
                    ]),
                Tables\Filters\Filter::make('has_active_issues')
                    ->label('Has Active Issues')
                    ->query(fn (Builder $query) => $query->whereHas('defects', fn ($q) => $q->where('resolved', false))),
            ])
            ->actions([
                Tables\Actions\Action::make('view_inspections')
                    ->label('Inspections')
                    ->icon('heroicon-o-clipboard-document-list')
                    ->color('info')
                    ->url(fn (Apparatus $record): string => static::getUrl('inspections', ['record' => $record])),
                Tables\Actions\Action::make('start_daily_checkout')
                    ->label('Daily Checkout')
                    ->icon('heroicon-o-play-circle')
                    ->color('success')
                    ->url(fn (Apparatus $record): string => "/daily/{$record->id}")
                    ->openUrlInNewTab(),
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
//             RelationManagers\EquipmentRecommendationsRelationManager::class,
//            RelationManagers\InventoryAllocationsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListApparatuses::route('/'),
            'create' => Pages\CreateApparatus::route('/create'),
            'edit' => Pages\EditApparatus::route('/{record}/edit'),
//             'inspections' => Pages\ApparatusInspections::route('/{record}/inspections'),
        ];
    }
}
