<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\EquipmentItemResource;
use App\Models\EquipmentItem;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class LowStockAlertsWidget extends BaseWidget
{
    protected static ?string $heading = 'Low Stock Alerts';
    protected static ?int $sort = 2;

    public function table(Table $table): Table
    {
        return $table
            ->query(
                // Get all active equipment items and filter collection-based for low stock
                EquipmentItem::query()
                    ->where('is_active', true)
                    ->get()
                    ->filter(fn ($item) => $item->stock <= $item->reorder_min)
                    ->toQuery()
            )
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->url(fn ($record) => EquipmentItemResource::getUrl('edit', ['record' => $record])),
                
                Tables\Columns\TextColumn::make('current_stock')
                    ->label('Current Stock')
                    ->badge()
                    ->color('danger'),
                
                Tables\Columns\TextColumn::make('reorder_min')
                    ->label('Threshold'),
                
                Tables\Columns\TextColumn::make('location.full_location')
                    ->label('Location'),
            ])
            ->paginated([5, 10, 25])
            ->defaultPaginationPageOption(5)
            ->emptyStateHeading('No low stock items')
            ->emptyStateDescription('All equipment items are adequately stocked')
            ->emptyStateIcon('heroicon-o-check-circle');
    }
}
