<?php

namespace App\Filament\Resources;

use App\Filament\Resources\RecommendationResource\Pages;
use App\Models\ApparatusDefectRecommendation;
use App\Models\EquipmentItem;
use App\Models\ApparatusInventoryAllocation;
use App\Models\AdminAlertEvent;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Resources\Resource;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Closure;

class RecommendationResource extends Resource
{
    protected static ?string $model = ApparatusDefectRecommendation::class;

    protected static ?string $navigationIcon = 'heroicon-o-light-bulb';

    protected static ?string $navigationLabel = 'Replacement Recommendations';

    protected static ?string $navigationGroup = 'Fire Equipment';

    protected static ?int $navigationSort = 3;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Placeholder::make('apparatus_info')
                    ->label('Apparatus')
                    ->content(fn ($record) => $record?->defect?->apparatus?->unit_id ?? 'N/A'),
                Forms\Components\Placeholder::make('compartment_info')
                    ->label('Compartment')
                    ->content(fn ($record) => $record?->defect?->compartment ?? 'N/A'),
                Forms\Components\Placeholder::make('defect_item_info')
                    ->label('Defect Item')
                    ->content(fn ($record) => $record?->defect?->item ?? 'N/A'),
                Forms\Components\Select::make('equipment_item_id')
                    ->label('Recommended Equipment Item')
                    ->relationship('equipmentItem', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),
                Forms\Components\TextInput::make('recommended_qty')
                    ->label('Recommended Quantity')
                    ->numeric()
                    ->required()
                    ->minValue(1),
                Forms\Components\Select::make('match_method')
                    ->options([
                        'exact' => 'Exact',
                        'trigram' => 'Trigram',
                        'fuzzy' => 'Fuzzy',
                        'ai' => 'AI',
                        'manual' => 'Manual',
                    ])
                    ->disabled(),
                Forms\Components\TextInput::make('match_confidence')
                    ->label('Match Confidence')
                    ->disabled()
                    ->formatStateUsing(fn ($state) => $state ? number_format($state * 100, 2) . '%' : 'N/A'),
                Forms\Components\Textarea::make('reasoning')
                    ->disabled()
                    ->columnSpanFull(),
                Forms\Components\Select::make('status')
                    ->options([
                        'pending' => 'Pending',
                        'allocated' => 'Allocated',
                        'dismissed' => 'Dismissed',
                    ])
                    ->required(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('defect.apparatus.unit_id')
                    ->label('Apparatus')
                    ->searchable()
                    ->sortable()
                    ->badge()
                    ->color('primary'),
                Tables\Columns\TextColumn::make('defect.item')
                    ->label('Defect Item')
                    ->searchable()
                    ->limit(30),
                Tables\Columns\TextColumn::make('equipmentItem.name')
                    ->label('Recommended Item')
                    ->searchable()
                    ->limit(30),
                Tables\Columns\TextColumn::make('match_confidence')
                    ->label('Confidence')
                    ->formatStateUsing(fn ($state) => $state ? number_format($state * 100, 1) . '%' : 'N/A')
                    ->color(fn ($state) => $state >= 0.8 ? 'success' : ($state >= 0.5 ? 'warning' : 'danger'))
                    ->sortable(),
                Tables\Columns\TextColumn::make('match_method')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'exact' => 'success',
                        'trigram' => 'info',
                        'fuzzy' => 'warning',
                        'ai' => 'primary',
                        'manual' => 'gray',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('recommended_qty')
                    ->label('Qty'),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'pending' => 'warning',
                        'allocated' => 'success',
                        'dismissed' => 'gray',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('created_at')
                    ->since()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'pending' => 'Pending',
                        'allocated' => 'Allocated',
                        'dismissed' => 'Dismissed',
                    ])
                    ->default('pending'),
                Tables\Filters\SelectFilter::make('apparatus_id')
                    ->label('Apparatus')
                    ->relationship('defect.apparatus', 'unit_id')
                    ->searchable()
                    ->preload(),
                Tables\Filters\SelectFilter::make('match_method')
                    ->options([
                        'exact' => 'Exact',
                        'trigram' => 'Trigram',
                        'fuzzy' => 'Fuzzy',
                        'ai' => 'AI',
                        'manual' => 'Manual',
                    ]),
            ])
            ->actions([
                Tables\Actions\Action::make('allocate')
                    ->label('Allocate')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->tooltip('Allocate inventory item to apparatus')
                    ->visible(fn ($record) => $record->status === 'pending')
                    ->form([
                        Forms\Components\Placeholder::make('current_recommendation')
                            ->label('Current Recommendation')
                            ->content(fn ($record) => "Recommended: {$record->equipmentItem->name} (Stock: {$record->equipmentItem->stock})"),
                        Forms\Components\Select::make('equipment_item_id')
                            ->label('Item to Allocate')
                            ->relationship('equipmentItem', 'name')
                            ->searchable()
                            ->preload()
                            ->default(fn ($record) => $record->equipment_item_id)
                            ->required()
                            ->reactive()
                            ->afterStateUpdated(function ($state, $set) {
                                $item = EquipmentItem::find($state);
                                if ($item) {
                                    $set('available_stock', $item->stock);
                                }
                            }),
                        Forms\Components\Placeholder::make('available_stock')
                            ->label('Available Stock')
                            ->content(fn ($get) => "Available stock: " . ($get('available_stock') ?? 'N/A')),
                        Forms\Components\TextInput::make('qty_allocated')
                            ->label('Quantity to Allocate')
                            ->numeric()
                            ->required()
                            ->minValue(1)
                            ->default(fn ($record) => $record->recommended_qty)
                            ->rules([
                                fn ($get) => function (string $attribute, $value, Closure $fail) use ($get) {
                                    $itemId = $get('equipment_item_id');
                                    if ($itemId) {
                                        $item = EquipmentItem::find($itemId);
                                        if ($item && $value > $item->stock) {
                                            $fail("Only {$item->stock} units available in stock.");
                                        }
                                    }
                                },
                            ]),
                        Forms\Components\Textarea::make('notes')
                            ->label('Allocation Notes')
                            ->placeholder('e.g., Delivered to Engine 1, Officer notified'),
                    ])
                    ->action(function (ApparatusDefectRecommendation $record, array $data) {
                        DB::transaction(function () use ($record, $data) {
                            // 1. Get item and verify stock
                            $item = EquipmentItem::findOrFail($data['equipment_item_id']);
                            $qty = $data['qty_allocated'];
                            
                            if ($item->stock < $qty) {
                                throw new \Exception("Insufficient stock: {$item->stock} available, {$qty} requested");
                            }
                            
                            // 2. Create allocation record
                            $allocation = ApparatusInventoryAllocation::create([
                                'apparatus_id' => $record->defect->apparatus_id,
                                'apparatus_defect_id' => $record->apparatus_defect_id,
                                'equipment_item_id' => $item->id,
                                'qty_allocated' => $qty,
                                'allocated_by_user_id' => auth()->id(),
                                'allocated_at' => now(),
                                'notes' => $data['notes'] ?? null,
                            ]);
                            
                            // 3. Decrease stock
                            $item->decreaseStock(
                                $qty,
                                "Allocated to {$record->defect->apparatus->unit_id} for defect: {$record->defect->item}",
                                "ALLOC-{$allocation->id}"
                            );
                            
                            // 4. Mark recommendation as allocated
                            $record->update(['status' => 'allocated']);
                            
                            // 5. Mark defect as resolved
                            $record->defect->update([
                                'resolved' => true,
                                'resolved_at' => now(),
                                'resolution_notes' => "Allocated {$qty} × {$item->name} from inventory. {$data['notes']}",
                            ]);
                            
                            // 6. Create alert
                            AdminAlertEvent::create([
                                'type' => 'allocation_made',
                                'severity' => 'info',
                                'message' => "Allocated {$qty} × {$item->name} to {$record->defect->apparatus->unit_id}",
                                'related_type' => 'apparatus_inventory_allocation',
                                'related_id' => $allocation->id,
                                'created_by_user_id' => auth()->id(),
                            ]);
                            
                            // 7. Check for low stock
                            if ($item->refresh()->isLowStock()) {
                                AdminAlertEvent::create([
                                    'type' => 'low_stock',
                                    'severity' => 'critical',
                                    'message' => "Critical: Low stock for {$item->name} (current: {$item->stock}, min: {$item->reorder_min})",
                                    'related_type' => 'equipment_item',
                                    'related_id' => $item->id,
                                ]);
                            }
                        });
                        
                        Notification::make()
                            ->success()
                            ->title('Replacement allocated')
                            ->body('Defect resolved and stock updated')
                            ->send();
                            
                        // Redirect to apparatus page
                        return redirect()->to(ApparatusResource::getUrl('edit', ['record' => $record->defect->apparatus_id]));
                    }),
                Tables\Actions\Action::make('dismiss')
                    ->label('Dismiss')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->tooltip('Dismiss this recommendation')
                    ->requiresConfirmation()
                    ->visible(fn ($record) => $record->status === 'pending')
                    ->form([
                        Forms\Components\Textarea::make('dismiss_reason')
                            ->label('Reason for Dismissal')
                            ->required()
                            ->placeholder('e.g., Item not needed, incorrect match, etc.'),
                    ])
                    ->action(function (ApparatusDefectRecommendation $record, array $data) {
                        $record->update([
                            'status' => 'dismissed',
                            'reasoning' => $record->reasoning . "\n\n[DISMISSED] " . $data['dismiss_reason'],
                        ]);
                        
                        Notification::make()
                            ->warning()
                            ->title('Recommendation dismissed')
                            ->send();
                    }),
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
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
            'index' => Pages\ListRecommendations::route('/'),
            'edit' => Pages\EditRecommendation::route('/{record}/edit'),
        ];
    }
}
