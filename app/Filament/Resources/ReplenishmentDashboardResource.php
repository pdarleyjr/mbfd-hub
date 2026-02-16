<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ReplenishmentDashboardResource\Pages;
use App\Models\StationInventoryItem;
use App\Models\StationSupplyOrder;
use App\Models\StationSupplyOrderLine;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Notifications\Notification;

class ReplenishmentDashboardResource extends Resource
{
    protected static ?string $model = StationInventoryItem::class;
    protected static ?string $navigationIcon = 'heroicon-o-shopping-cart';
    protected static ?string $navigationGroup = 'Operations';
    protected static ?string $navigationLabel = 'Replenishment Dashboard';
    protected static ?int $navigationSort = 4;

    public static function canViewAny(): bool
    {
        return config('features.replenishment_dashboard', false);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->query(
                StationInventoryItem::query()
                    ->with(['station', 'inventoryItem'])
                    ->whereHas('inventoryItem')
                    ->get()
                    ->filter(function ($item) {
                        $threshold = $item->inventoryItem->low_threshold 
                            ?? ($item->par_quantity * 0.5);
                        return $item->quantity < $threshold;
                    })
                    ->toQuery()
            )
            ->columns([
                Tables\Columns\TextColumn::make('station.name')
                    ->label('Station')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('inventoryItem.name')
                    ->label('Item')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('quantity')
                    ->label('On Hand')
                    ->sortable()
                    ->badge()
                    ->color('danger'),
                Tables\Columns\TextColumn::make('par_quantity')
                    ->label('PAR')
                    ->sortable(),
                Tables\Columns\TextColumn::make('suggested_qty')
                    ->label('Suggested Order')
                    ->getStateUsing(fn ($record) => max(0, $record->par_quantity - $record->quantity))
                    ->badge()
                    ->color('warning'),
                Tables\Columns\TextColumn::make('inventoryItem.vendor_url')
                    ->label('Vendor')
                    ->url(fn ($record) => $record->inventoryItem->vendor_url, shouldOpenInNewTab: true)
                    ->placeholder('—')
                    ->formatStateUsing(fn ($state) => $state ? 'Open Link' : '—')
                    ->color('primary'),
                Tables\Columns\TextColumn::make('last_updated_at')
                    ->label('Last Updated')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('station')
                    ->relationship('station', 'name'),
                Tables\Filters\SelectFilter::make('category')
                    ->relationship('inventoryItem.category', 'name'),
            ])
            ->actions([
                // Individual actions can be added here
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('generate_draft')
                        ->label('Generate Order Email Draft')
                        ->icon('heroicon-o-envelope')
                        ->color('primary')
                        ->requiresConfirmation()
                        ->action(function ($records) {
                            $order = StationSupplyOrder::create([
                                'created_by' => auth()->id(),
                                'status' => 'draft',
                                'subject' => 'Supply Order - ' . now()->format('Y-m-d'),
                                'vendor_name' => 'Grainger',
                            ]);

                            foreach ($records as $record) {
                                StationSupplyOrderLine::create([
                                    'station_supply_order_id' => $order->id,
                                    'station_id' => $record->station_id,
                                    'inventory_item_id' => $record->inventory_item_id,
                                    'station_inventory_item_id' => $record->id,
                                    'qty_suggested' => max(0, $record->par_quantity - $record->quantity),
                                    'status' => 'pending',
                                ]);
                            }

                            Notification::make()
                                ->title('Draft order created')
                                ->body("Order #{$order->id} with {$records->count()} items")
                                ->success()
                                ->send();
                        }),
                    
                    Tables\Actions\BulkAction::make('mark_ordered')
                        ->label('Mark Ordered (Manual)')
                        ->icon('heroicon-o-phone')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->action(function ($records) {
                            $order = StationSupplyOrder::create([
                                'created_by' => auth()->id(),
                                'status' => 'manual_ordered',
                                'sent_via' => 'phone',
                                'subject' => 'Manual Order - ' . now()->format('Y-m-d'),
                                'vendor_name' => 'Grainger',
                            ]);

                            foreach ($records as $record) {
                                StationSupplyOrderLine::create([
                                    'station_supply_order_id' => $order->id,
                                    'station_id' => $record->station_id,
                                    'inventory_item_id' => $record->inventory_item_id,
                                    'station_inventory_item_id' => $record->id,
                                    'qty_suggested' => max(0, $record->par_quantity - $record->quantity),
                                    'qty_ordered' => max(0, $record->par_quantity - $record->quantity),
                                    'status' => 'ordered',
                                ]);
                            }

                            Notification::make()
                                ->title('Items marked as ordered')
                                ->body("Order #{$order->id} created for {$records->count()} items")
                                ->success()
                                ->send();
                        }),
                    
                    Tables\Actions\BulkAction::make('mark_delivered')
                        ->label('Mark Delivered')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->form([
                            Forms\Components\Repeater::make('deliveries')
                                ->label('Delivered Quantities')
                                ->schema([
                                    Forms\Components\TextInput::make('item')
                                        ->disabled(),
                                    Forms\Components\TextInput::make('qty_delivered')
                                        ->label('Quantity Delivered')
                                        ->numeric()
                                        ->required()
                                        ->minValue(1),
                                ])
                                ->default(fn ($records) => $records->map(fn ($r) => [
                                    'item' => $r->inventoryItem->name,
                                    'qty_delivered' => max(0, $r->par_quantity - $r->quantity),
                                ])->toArray()),
                        ])
                        ->action(function ($records, array $data) {
                            foreach ($records as $index => $record) {
                                $qtyDelivered = $data['deliveries'][$index]['qty_delivered'] ?? 0;
                                
                                // Update station inventory
                                $record->update([
                                    'quantity' => $record->quantity + $qtyDelivered,
                                    'updated_by' => auth()->id(),
                                    'last_updated_at' => now(),
                                ]);
                            }

                            Notification::make()
                                ->title('Deliveries recorded')
                                ->body("{$records->count()} items updated")
                                ->success()
                                ->send();
                        }),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListReplenishmentDashboard::route('/'),
        ];
    }
}
