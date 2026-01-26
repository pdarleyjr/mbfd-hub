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
                Tables\Columns\TextColumn::make('vehicle_number')
                    ->label('Vehicle No')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('designation')
                    ->label('Designation')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('assignment')
                    ->label('Assignment')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('current_location')
                    ->label('Current Location')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'Active' => 'success',
                        'Out of Service' => 'danger',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('notes')
                    ->label('Notes')
                    ->limit(50)
                    ->searchable(),
                Tables\Columns\TextColumn::make('class_description')
                    ->label('Class Description')
                    ->searchable()
                    ->sortable(),
            ])
            ->defaultSort('vehicle_number')
            ->striped()
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'Active' => 'Active',
                        'Out of Service' => 'Out of Service',
                    ]),
            ])
            ->actions([
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
