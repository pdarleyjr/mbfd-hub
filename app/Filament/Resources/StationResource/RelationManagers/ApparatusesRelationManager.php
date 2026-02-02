<?php

namespace App\Filament\Resources\StationResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class ApparatusesRelationManager extends RelationManager
{
    protected static string $relationship = 'apparatuses';
    protected static ?string $title = 'Assigned Apparatus';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('unit_number')
                    ->required()
                    ->maxLength(255),
                Forms\Components\Select::make('type')
                    ->options([
                        'engine' => 'Engine',
                        'ladder' => 'Ladder',
                        'rescue' => 'Rescue',
                        'battalion' => 'Battalion',
                        'boat' => 'Boat',
                        'utility' => 'Utility',
                    ])
                    ->required(),
                Forms\Components\TextInput::make('make')
                    ->maxLength(255),
                Forms\Components\TextInput::make('model')
                    ->maxLength(255),
                Forms\Components\TextInput::make('year')
                    ->numeric()
                    ->minValue(1900)
                    ->maxValue(2030),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('unit_number')
            ->columns([
                Tables\Columns\TextColumn::make('unit_number')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('type')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'engine' => 'danger',
                        'ladder' => 'warning',
                        'rescue' => 'info',
                        'battalion' => 'success',
                        'boat' => 'primary',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('make'),
                Tables\Columns\TextColumn::make('model'),
                Tables\Columns\TextColumn::make('year'),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'in_service' => 'success',
                        'out_of_service' => 'danger',
                        'maintenance' => 'warning',
                        default => 'gray',
                    }),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->options([
                        'engine' => 'Engine',
                        'ladder' => 'Ladder',
                        'rescue' => 'Rescue',
                        'battalion' => 'Battalion',
                        'boat' => 'Boat',
                        'utility' => 'Utility',
                    ]),
            ])
            ->headerActions([
                Tables\Actions\AttachAction::make()
                    ->preloadRecordSelect(),
            ])
            ->actions([
                Tables\Actions\DetachAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DetachBulkAction::make(),
                ]),
            ]);
    }
}