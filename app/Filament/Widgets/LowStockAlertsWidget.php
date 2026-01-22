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
                EquipmentItem::query()
                    ->where('is_active', true)
                    ->whereColumn('stock', '<=', 'reorder_min')
                    ->orderBy('stock', 'asc')
            )
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->url(fn ($record) => EquipmentItemResource::getUrl('edit', ['record' => $record])),
                
                Tables\Columns\TextColumn::make('stock')
                    ->badge()
                    ->color(fn ($state, $record) => 
                        $state == 0 ? 'danger' : 
                        ($state <= $record->reorder_min / 2 ? 'danger' : 'warning')
                    ),
                
                Tables\Columns\TextColumn::make('reorder_min')
                    ->label('Threshold'),
                
                Tables\Columns\TextColumn::make('location.full_location')
                    ->label('Location'),
            ])
            ->paginated([5, 10, 25])
            ->defaultPaginationPageOption(5)
            ->emptyStateHeading('All stock levels are healthy')
            ->emptyStateDescription('No items below reorder thresholds')
            ->emptyStateIcon('heroicon-o-check-circle');
    }
}
