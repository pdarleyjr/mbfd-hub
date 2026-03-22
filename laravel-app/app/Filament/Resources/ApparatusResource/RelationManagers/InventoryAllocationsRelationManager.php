<?php

namespace App\Filament\Resources\ApparatusResource\RelationManagers;

use App\Filament\Resources\EquipmentItemResource;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class InventoryAllocationsRelationManager extends RelationManager
{
    protected static string $relationship = 'inventoryAllocations';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                // Read-only - allocations created via recommendations
                Forms\Components\Placeholder::make('info')
                    ->content('Inventory allocations are created when equipment is allocated to defects via recommendations.'),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('equipment_item.name')
            ->defaultSort('allocated_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('equipment_item.name')
                    ->label('Item')
                    ->searchable()
                    ->sortable()
                    ->url(fn ($record) => 
                        EquipmentItemResource::getUrl('edit', ['record' => $record->equipment_item_id])
                    ),
                
                Tables\Columns\TextColumn::make('defect_info')
                    ->label('For Defect')
                    ->getStateUsing(fn ($record) => 
                        $record->defect 
                            ? "{$record->defect->compartment} - {$record->defect->item}" 
                            : 'N/A'
                    )
                    ->searchable(['defect.compartment', 'defect.item']),
                
                Tables\Columns\TextColumn::make('qty_allocated')
                    ->label('Quantity')
                    ->sortable(),
                
                Tables\Columns\TextColumn::make('allocated_by.name')
                    ->label('Allocated By')
                    ->searchable()
                    ->sortable(),
                
                Tables\Columns\TextColumn::make('allocated_at')
                    ->label('When')
                    ->dateTime()
                    ->sortable()
                    ->since(),
                
                Tables\Columns\TextColumn::make('notes')
                    ->label('Notes')
                    ->toggleable()
                    ->limit(50),
            ])
            ->filters([
                // No filters needed for view-only table
            ])
            ->headerActions([
                // No header actions - allocations created via recommendations
            ])
            ->actions([
                Tables\Actions\Action::make('view_item')
                    ->label('View Item')
                    ->icon('heroicon-o-cube')
                    ->url(fn ($record) => 
                        EquipmentItemResource::getUrl('edit', ['record' => $record->equipment_item_id])
                    ),
            ])
            ->bulkActions([
                // No bulk actions for view-only table
            ])
            ->emptyStateHeading('No inventory allocations yet')
            ->emptyStateDescription('Equipment will appear here when allocated to defects.')
            ->emptyStateIcon('heroicon-o-cube');
    }
}
