<?php

namespace App\Filament\Resources\StationResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Forms;
use pxlrbt\FilamentExcel\Actions\Tables\ExportAction;
use pxlrbt\FilamentExcel\Actions\Tables\ExportBulkAction;
use pxlrbt\FilamentExcel\Exports\ExcelExport;

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
                    ->sortable()
                    ->formatStateUsing(fn ($state, $record) => $state . ' ' . ($record->inventoryItem?->unit_label ?? '')),
                Tables\Columns\TextColumn::make('inventoryItem.par_quantity')
                    ->label('Expected (Par)')
                    ->sortable()
                    ->formatStateUsing(fn ($state, $record) => $state . ' ' . ($record->inventoryItem?->unit_label ?? '')),
                Tables\Columns\BadgeColumn::make('status')
                    ->colors([
                        'success' => 'ok',
                        'danger' => 'low',
                        'warning' => 'ordered',
                        'info' => 'overstocked',
                    ])
                    ->formatStateUsing(fn ($state) => match ((string)$state) {
                        'ok' => 'OK',
                        'low' => 'Low Stock',
                        'ordered' => 'Ordered',
                        'overstocked' => 'Overstocked',
                        default => ucfirst((string)$state),
                    }),
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
                        'overstocked' => 'Overstocked',
                    ]),
            ])
            
            ->headerActions([
                ExportAction::make('export')
                    ->label('Export')
                    ->color('gray')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->exports([
                        ExcelExport::make('xlsx')
                            ->label('Export as Excel (.xlsx)')
                            ->fromTable()
                            ->withFilename('mbfd_station_inventory_' . date('Y-m-d')),
                        ExcelExport::make('csv')
                            ->label('Export as CSV (.csv)')
                            ->fromTable()
                            ->withFilename('mbfd_station_inventory_' . date('Y-m-d'))
                            ->withWriterType(\Maatwebsite\Excel\Excel::CSV),
                    ]),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->form([
                        Forms\Components\TextInput::make('on_hand')
                            ->required()
                            ->numeric()
                            ->minValue(0)
                            ->suffix(fn($record) => $record->inventoryItem?->unit_label ?? '')
                            ->helperText(fn($record) => 'Expected: ' . ($record->inventoryItem?->par_quantity ?? '?') . ' ' . ($record->inventoryItem?->unit_label ?? '')),
                    ])
                    ->using(function ($record, array $data) {
                        $record->updateCount((int) $data['on_hand']);
                        return $record;
                    }),

                // Mark Ordered Action
                Tables\Actions\Action::make('mark_ordered')
                    ->label('Mark Ordered')
                    ->icon('heroicon-o-shopping-cart')
                    ->color('warning')
                    ->visible(fn ($record) => $record->status === 'low')
                    ->action(function ($record) {
                        $record->status = 'ordered';
                        $record->last_updated_at = now();
                        $record->save();
                    }),

                // Replenish Action
                Tables\Actions\Action::make('replenish')
                    ->label('Replenish')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn ($record) => in_array($record->status, ['low', 'ordered']))
                    ->requiresConfirmation()
                    ->action(function ($record) {
                        if ($record->inventoryItem) {
                            $record->updateCount($record->inventoryItem->par_quantity);
                        }
                    }),
                
                // Clear Overstock Alert
                Tables\Actions\Action::make('clear_alert')
                    ->label('Clear Alert')
                    ->icon('heroicon-o-x-circle')
                    ->color('gray')
                    ->visible(fn ($record) => $record->status === 'overstocked')
                    ->action(function ($record) {
                        $record->status = 'ok';
                        $record->save();
                    }),
            ])
            ->bulkActions([                    ExportBulkAction::make('export_selected')
                        ->label('Export Selected')
                        ->exports([
                            ExcelExport::make('xlsx')
                                ->label('Export as Excel (.xlsx)')
                                ->fromTable()
                                ->withFilename('mbfd_station_inventory_selected_' . date('Y-m-d')),
                            ExcelExport::make('csv')
                                ->label('Export as CSV (.csv)')
                                ->fromTable()
                                ->withFilename('mbfd_station_inventory_selected_' . date('Y-m-d'))
                                ->withWriterType(\Maatwebsite\Excel\Excel::CSV),
                        ]),
]);
    }
}
