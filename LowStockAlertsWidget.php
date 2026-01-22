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
    protected int | string | array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        // Get low stock items filtered in PHP since stock is computed from mutations
        $lowStockIds = EquipmentItem::where('is_active', true)
            ->get()
            ->filter(fn ($item) => $item->stock <= $item->reorder_min)
            ->sortBy(fn ($item) => $item->stock)
            ->take(6)
            ->pluck('id');

        return $table
            ->query(EquipmentItem::whereIn('id', $lowStockIds))
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->url(fn ($record) => EquipmentItemResource::getUrl('edit', ['record' => $record])),
                
                Tables\Columns\TextColumn::make('current_stock')
                    ->label('Stock')
                    ->badge()
                    ->getStateUsing(fn ($record) => $record->stock)
                    ->color(fn ($state, $record) => 
                        $state == 0 ? 'danger' : 
                        ($state <= $record->reorder_min / 2 ? 'danger' : 'warning')
                    ),
                
                Tables\Columns\TextColumn::make('reorder_min')
                    ->label('Threshold'),
                
                Tables\Columns\TextColumn::make('location.full_location')
                    ->label('Location'),
            ])
            ->paginated(false)
            ->emptyStateHeading('All stock levels are healthy')
            ->emptyStateDescription('No items below reorder thresholds')
            ->emptyStateIcon('heroicon-o-check-circle');
    }
}
