<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\ApparatusResource;
use App\Filament\Resources\EquipmentItemResource;
use App\Models\ApparatusInventoryAllocation;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class RecentAllocationsWidget extends BaseWidget
{
    protected static ?string $heading = 'Recent Inventory Allocations';
    protected static ?int $sort = 4;

    protected int | string | array $columnSpan = [
        'sm' => 1,
        'md' => 1,
        'xl' => 1,
    ];

    public function table(Table $table): Table
    {
        return $table
            ->query(
                ApparatusInventoryAllocation::query()
                    ->where('allocated_at', '>=', now()->subDays(7))
                    ->with(['apparatus', 'equipmentItem', 'allocatedBy'])
                    ->orderBy('allocated_at', 'desc')
            )
            ->columns([
                Tables\Columns\TextColumn::make('apparatus.unit_id')
                    ->label('Apparatus')
                    ->badge()
                    ->url(fn ($record) => ApparatusResource::getUrl('edit', ['record' => $record->apparatus_id])),
                
                Tables\Columns\TextColumn::make('equipment_item.name')
                    ->label('Item')
                    ->url(fn ($record) => EquipmentItemResource::getUrl('edit', ['record' => $record->equipment_item_id])),
                
                Tables\Columns\TextColumn::make('qty_allocated')
                    ->label('Qty'),
                
                Tables\Columns\TextColumn::make('allocated_by.name')
                    ->label('By'),
                
                Tables\Columns\TextColumn::make('allocated_at')
                    ->dateTime()
                    ->since(),
            ])
            ->paginated([5, 10])
            ->defaultPaginationPageOption(5)
            ->emptyStateHeading('No recent allocations')
            ->emptyStateDescription('Allocations from the last 7 days will appear here');
    }
}
