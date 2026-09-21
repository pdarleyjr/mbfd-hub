<?php

namespace App\Filament\Resources\StationResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class InventorySubmissionsRelationManager extends RelationManager
{
    protected static string $relationship = 'inventorySubmissions';

    protected static ?string $title = 'Inventory Submissions';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),
                Tables\Columns\TextColumn::make('employee_name')
                    ->label('Employee')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('shift')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'A' => 'success',
                        'B' => 'warning',
                        'C' => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),
                Tables\Columns\TextColumn::make('submitted_at')
                    ->label('Submitted')
                    ->dateTime('M j, Y g:i A')
                    ->sortable()
                    ->timezone('America/New_York'),
                Tables\Columns\TextColumn::make('creator.name')
                    ->label('Created By')
                    ->sortable(),
            ])
            ->defaultSort('submitted_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('shift')
                    ->options([
                        'A' => 'A Shift',
                        'B' => 'B Shift',
                        'C' => 'C Shift',
                    ]),
            ])
            // There is no standalone Filament resource for these records.
            // Keep them in their canonical Station relation rather than
            // emitting a route that resolves to a non-existent resource.
            ->actions([])
            ->bulkActions([]);
    }
}
