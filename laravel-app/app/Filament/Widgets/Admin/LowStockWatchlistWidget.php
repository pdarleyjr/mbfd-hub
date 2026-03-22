<?php

namespace App\Filament\Widgets\Admin;

use App\Models\EquipmentItem;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class LowStockWatchlistWidget extends BaseWidget
{
    protected int | string | array $columnSpan = [
        'default' => 'full',
        'lg' => 3,
    ];

    protected static ?int $sort = 4;

    public function table(Table $table): Table
    {
        return $table
            ->query(
                EquipmentItem::query()
                    ->where('is_active', true)
                    ->whereRaw('stock <= reorder_min')
                    ->orderByRaw('stock ASC')
                    ->limit(10)
            )
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Item')
                    ->searchable()
                    ->wrap()
                    ->limit(40),
                    
                Tables\Columns\TextColumn::make('category')
                    ->label('Category')
                    ->wrap()
                    ->limit(25),
                    
                Tables\Columns\TextColumn::make('stock')
                    ->label('Stock')
                    ->numeric()
                    ->color(fn (int $state): string => $state === 0 ? 'danger' : ($state <= 3 ? 'warning' : 'gray')),
                    
                Tables\Columns\TextColumn::make('reorder_min')
                    ->label('Reorder At')
                    ->numeric(),
            ])
            ->heading('Low Stock Watchlist')
            ->paginated(false)
            ->emptyStateHeading('No Low Stock Items')
            ->emptyStateDescription('All equipment items are adequately stocked.')
            ->emptyStateIcon('heroicon-o-check-circle');
    }
}
