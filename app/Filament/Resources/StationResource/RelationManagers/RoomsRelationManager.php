<?php

namespace App\Filament\Resources\StationResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class RoomsRelationManager extends RelationManager
{
    protected static string $relationship = 'rooms';

    protected static ?string $title = 'Station Rooms';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    ->label('Room Name'),
                Forms\Components\TextInput::make('floor')
                    ->default(1),
                Forms\Components\Select::make('room_type')
                    ->options([
                        'combat_apparatus_bay' => 'Combat Apparatus Bay',
                        'rescue_apparatus_bay' => 'Rescue Apparatus Bay',
                        'support_apparatus_bay' => 'Support Apparatus Bay',
                        'fireboat_apparatus_area' => 'Fireboat Berth / Apparatus Area',
                        'office' => 'Office',
                        'kitchen' => 'Kitchen',
                        'dormitory' => 'Dorm',
                        'common_area' => 'TV Room / Common Area',
                        'restroom' => 'Bathroom',
                        'laundry' => 'Laundry',
                        'fitness' => 'Fitness / Workout Room',
                        'storage' => 'Storage',
                        'utility' => 'Utility / Mechanical',
                        'exterior' => 'Exterior / Grounds',
                        'other' => 'Other',
                    ])
                    ->required(),
                Forms\Components\TextInput::make('capacity')
                    ->numeric()
                    ->minValue(0)
                    ->label('Positions / Capacity'),
                Forms\Components\Textarea::make('notes')
                    ->columnSpanFull(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('room_type')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'combat_apparatus_bay', 'rescue_apparatus_bay', 'support_apparatus_bay', 'fireboat_apparatus_area' => 'danger',
                        'office' => 'info',
                        'kitchen' => 'warning',
                        'dormitory' => 'success',
                        'storage' => 'gray',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('floor')
                    ->sortable(),
                Tables\Columns\TextColumn::make('assets_count')
                    ->counts('assets')
                    ->label('Assets'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('room_type')
                    ->options([
                        'combat_apparatus_bay' => 'Combat Apparatus Bay',
                        'rescue_apparatus_bay' => 'Rescue Apparatus Bay',
                        'support_apparatus_bay' => 'Support Apparatus Bay',
                        'fireboat_apparatus_area' => 'Fireboat Berth / Apparatus Area',
                        'office' => 'Office',
                        'kitchen' => 'Kitchen',
                        'dormitory' => 'Dorm',
                        'common_area' => 'TV Room / Common Area',
                        'restroom' => 'Bathroom',
                        'laundry' => 'Laundry',
                        'fitness' => 'Fitness / Workout Room',
                        'storage' => 'Storage',
                        'utility' => 'Utility / Mechanical',
                        'exterior' => 'Exterior / Grounds',
                        'other' => 'Other',
                    ]),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }
}
