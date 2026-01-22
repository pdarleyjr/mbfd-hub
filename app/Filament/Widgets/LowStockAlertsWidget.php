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
                // Return an empty query since stock_mutations table does not exist
                EquipmentItem::query()->whereRaw('1 = 0')
            )
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->url(fn ($record) => EquipmentItemResource::getUrl('edit', ['record' => $record])),
                
                Tables\Columns\TextColumn::make('reorder_min')
                    ->label('Threshold'),
                
                Tables\Columns\TextColumn::make('location.full_location')
                    ->label('Location'),
            ])
            ->paginated([5, 10, 25])
            ->defaultPaginationPageOption(5)
            ->emptyStateHeading('Stock tracking unavailable')
            ->emptyStateDescription('The stock mutations system is not yet configured')
            ->emptyStateIcon('heroicon-o-information-circle');
    }
}
