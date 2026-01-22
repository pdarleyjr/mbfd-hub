<?php

namespace App\Filament\Resources;

use App\Filament\Resources\EquipmentItemResource\Pages;
use App\Models\EquipmentItem;
use App\Models\InventoryLocation;
use App\Models\AdminAlertEvent;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Resources\Resource;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Builder;

class EquipmentItemResource extends Resource
{
    protected static ?string $model = EquipmentItem::class;

    protected static ?string $navigationIcon = 'heroicon-o-cube';

    protected static ?string $navigationGroup = 'Fire Equipment';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                Forms\Components\Select::make('category')
                    ->options([
                        'PPE' => 'PPE',
                        'Hose' => 'Hose',
                        'Nozzles' => 'Nozzles',
                        'Tools' => 'Tools',
                        'Medical' => 'Medical',
                        'SCBA' => 'SCBA',
                        'Other' => 'Other',
                    ])
                    ->required(),
                Forms\Components\Textarea::make('description')
                    ->columnSpanFull(),
                Forms\Components\TextInput::make('manufacturer')
                    ->maxLength(255),
                Forms\Components\Select::make('unit_of_measure')
                    ->options([
                        'each' => 'Each',
                        'box' => 'Box',
                        'case' => 'Case',
                        'pack' => 'Pack',
                        'roll' => 'Roll',
                        'pair' => 'Pair',
                    ])
                    ->required()
                    ->default('each'),
                Forms\Components\TextInput::make('reorder_min')
                    ->label('Low Stock Threshold')
                    ->numeric()
                    ->required()
                    ->default(0)
                    ->minValue(0),
                Forms\Components\TextInput::make('reorder_max')
                    ->label('Par Level')
                    ->numeric()
                    ->minValue(0)
                    ->nullable(),
                Forms\Components\Select::make('location_id')
                    ->relationship('location', 'location_name')
                    ->searchable()
                    ->preload()
                    ->createOptionForm([
                        Forms\Components\TextInput::make('location_name')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\Select::make('shelf')
                            ->options([
                                'A' => 'A',
                                'B' => 'B',
                                'C' => 'C',
                                'D' => 'D',
                                'E' => 'E',
                                'F' => 'F',
                            ]),
                        Forms\Components\TextInput::make('row')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(10),
                        Forms\Components\TextInput::make('bin')
                            ->maxLength(255),
                        Forms\Components\Textarea::make('notes')
                            ->columnSpanFull(),
                    ]),
                Forms\Components\Toggle::make('is_active')
                    ->default(true),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('current_stock')
                    ->label('Stock')
                    ->badge()
                    ->color(fn (EquipmentItem $record): string => 
                        $record->stock == 0 ? 'danger' : 
                        ($record->stock <= $record->reorder_min ? 'warning' : 'success')
                    )
                    ->sortable(query: function (Builder $query, string $direction): Builder {
                        return $query->orderBy('stock', $direction);
                    }),
                Tables\Columns\TextColumn::make('location.full_location')
                    ->label('Location')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('reorder_range')
                    ->label('Reorder Range')
                    ->getStateUsing(fn (EquipmentItem $record): string => 
                        "Min: {$record->reorder_min}" . ($record->reorder_max ? " / Max: {$record->reorder_max}" : '')
                    ),
                Tables\Columns\TextColumn::make('manufacturer')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('category')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'PPE' => 'info',
                        'Hose' => 'primary',
                        'Nozzles' => 'success',
                        'Tools' => 'warning',
                        'Medical' => 'danger',
                        'SCBA' => 'gray',
                        'Other' => 'secondary',
                        default => 'gray',
                    }),
                Tables\Columns\ToggleColumn::make('is_active')
                    ->label('Active'),
                Tables\Columns\TextColumn::make('updated_at')
                    ->since()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\Filter::make('low_stock')
                    ->label('Low Stock')
                    ->query(fn (Builder $query): Builder => 
                        $query->whereRaw('stock <= reorder_min')
                    ),
                Tables\Filters\SelectFilter::make('category')
                    ->options([
                        'PPE' => 'PPE',
                        'Hose' => 'Hose',
                        'Nozzles' => 'Nozzles',
                        'Tools' => 'Tools',
                        'Medical' => 'Medical',
                        'SCBA' => 'SCBA',
                        'Other' => 'Other',
                    ]),
                Tables\Filters\SelectFilter::make('shelf')
                    ->query(function (Builder $query, array $data): Builder {
                        if (!empty($data['value'])) {
                            return $query->whereHas('location', function (Builder $query) use ($data) {
                                $query->where('shelf', $data['value']);
                            });
                        }
                        return $query;
                    })
                    ->options([
                        'A' => 'A',
                        'B' => 'B',
                        'C' => 'C',
                        'D' => 'D',
                        'E' => 'E',
                        'F' => 'F',
                    ]),
                Tables\Filters\SelectFilter::make('row')
                    ->query(function (Builder $query, array $data): Builder {
                        if (!empty($data['value'])) {
                            return $query->whereHas('location', function (Builder $query) use ($data) {
                                $query->where('row', $data['value']);
                            });
                        }
                        return $query;
                    })
                    ->options([
                        '1' => '1',
                        '2' => '2',
                        '3' => '3',
                        '4' => '4',
                    ]),
                Tables\Filters\SelectFilter::make('manufacturer')
                    ->options(function (): array {
                        return EquipmentItem::query()
                            ->whereNotNull('manufacturer')
                            ->distinct()
                            ->pluck('manufacturer', 'manufacturer')
                            ->toArray();
                    }),
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Active Status')
                    ->placeholder('All items')
                    ->trueLabel('Active only')
                    ->falseLabel('Inactive only'),
            ])
            ->actions([
                Tables\Actions\Action::make('adjust_stock')
                    ->label('Adjust Stock')
                    ->icon('heroicon-o-arrows-up-down')
                    ->form([
                        Forms\Components\Select::make('operation')
                            ->options([
                                'increase' => 'Increase Stock',
                                'decrease' => 'Decrease Stock',
                                'set' => 'Set Stock to Exact Value',
                            ])
                            ->required()
                            ->reactive(),
                        Forms\Components\TextInput::make('quantity')
                            ->numeric()
                            ->required()
                            ->minValue(0)
                            ->label('Quantity'),
                        Forms\Components\Textarea::make('reason')
                            ->required()
                            ->label('Reason for Adjustment')
                            ->placeholder('e.g., Shipment received, Physical count adjustment, etc.'),
                    ])
                    ->action(function (EquipmentItem $record, array $data) {
                        $qty = (int) $data['quantity'];
                        $reason = $data['reason'];
                        $reference = 'ADMIN-' . now()->format('YmdHis');
                        
                        match ($data['operation']) {
                            'increase' => $record->increaseStock($qty, $reason, $reference),
                            'decrease' => $record->decreaseStock($qty, $reason, $reference),
                            'set' => $record->setStock($qty, $reason, $reference),
                        };
                        
                        // Create alert if low stock triggered
                        if ($record->refresh()->isLowStock()) {
                            AdminAlertEvent::create([
                                'type' => 'low_stock',
                                'severity' => 'warning',
                                'message' => "Low stock alert: {$record->name} (current: {$record->stock}, min: {$record->reorder_min})",
                                'related_type' => 'equipment_item',
                                'related_id' => $record->id,
                            ]);
                        }
                        
                        Notification::make()
                            ->success()
                            ->title('Stock adjusted')
                            ->body("{$record->name}: {$data['operation']} {$qty}")
                            ->send();
                    }),
                Tables\Actions\Action::make('move_location')
                    ->label('Move Location')
                    ->icon('heroicon-o-map-pin')
                    ->form([
                        Forms\Components\Select::make('location_id')
                            ->relationship('location', 'location_name')
                            ->searchable()
                            ->preload()
                            ->createOptionForm([
                                Forms\Components\TextInput::make('location_name')->required(),
                                Forms\Components\Select::make('shelf')
                                    ->options(['A' => 'A', 'B' => 'B', 'C' => 'C', 'D' => 'D', 'E' => 'E', 'F' => 'F']),
                                Forms\Components\TextInput::make('row')->numeric()->minValue(1)->maxValue(10),
                                Forms\Components\TextInput::make('bin'),
                            ])
                            ->required(),
                        Forms\Components\Textarea::make('notes')
                            ->label('Move Reason')
                            ->placeholder('e.g., Reorganizing supply room'),
                    ])
                    ->action(function (EquipmentItem $record, array $data) {
                        $oldLocation = $record->location?->full_location ?? 'N/A';
                        $record->update(['location_id' => $data['location_id']]);
                        $newLocation = $record->refresh()->location?->full_location ?? 'N/A';
                        
                        Notification::make()
                            ->success()
                            ->title('Location updated')
                            ->body("{$record->name} moved from {$oldLocation} to {$newLocation}")
                            ->send();
                    }),
                Tables\Actions\Action::make('set_thresholds')
                    ->label('Set Thresholds')
                    ->icon('heroicon-o-exclamation-triangle')
                    ->form([
                        Forms\Components\TextInput::make('reorder_min')
                            ->label('Low Stock Threshold')
                            ->numeric()
                            ->required()
                            ->default(fn ($record) => $record->reorder_min),
                        Forms\Components\TextInput::make('reorder_max')
                            ->label('Par Level (Target Stock)')
                            ->numeric()
                            ->default(fn ($record) => $record->reorder_max),
                    ])
                    ->action(function (EquipmentItem $record, array $data) {
                        $record->update([
                            'reorder_min' => $data['reorder_min'],
                            'reorder_max' => $data['reorder_max'],
                        ]);
                        
                        Notification::make()
                            ->success()
                            ->title('Thresholds updated')
                            ->send();
                    }),
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListEquipmentItems::route('/'),
            'create' => Pages\CreateEquipmentItem::route('/create'),
            'edit' => Pages\EditEquipmentItem::route('/{record}/edit'),
        ];
    }
}
