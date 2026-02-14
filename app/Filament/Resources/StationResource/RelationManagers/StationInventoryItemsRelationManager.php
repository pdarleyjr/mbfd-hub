<?php

namespace App\Filament\Resources\StationResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class StationInventoryItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'inventoryItems';
    protected static ?string $title = 'Inventory Items';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
            Tables\Columns\TextColumn::make('inventoryItem.name')
            ->label('Item')
            ->searchable()
            ->sortable(),
            Tables\Columns\TextColumn::make('inventoryItem.category.name')
            ->label('Category')
            ->sortable(),
            Tables\Columns\TextColumn::make('on_hand')
            ->label('On Hand')
            ->sortable(),
            Tables\Columns\BadgeColumn::make('status')
            ->colors([
                'success' => 'ok',
                'danger' => 'low',
                'warning' => 'ordered',
            ]),
            Tables\Columns\TextColumn::make('last_updated_at')
            ->label('Last Updated')
            ->dateTime('M j, Y g:i A')
            ->sortable()
            ->timezone('America/New_York'),
        ])
            ->defaultSort('status', 'asc')
            ->filters([
            Tables\Filters\SelectFilter::make('status')
            ->options([
                'ok' => 'OK',
                'low' => 'Low',
                'ordered' => 'Ordered',
            ]),
        ])
            ->actions([
            Tables\Actions\EditAction::make()
            ->form([
                Forms\Components\TextInput::make('on_hand')
                ->required()
                ->numeric()
                ->minValue(0)
                ->suffix(fn($record) => $record->inventoryItem->unit_label)
                ->helperText(fn($record) => 'Expected: ' . $record->inventoryItem->par_units . ' ' . $record->inventoryItem->unit_label),
            ]),
        ])
            ->bulkActions([]);
    }
}
