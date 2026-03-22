<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\ApparatusResource;
use App\Filament\Resources\EquipmentItemResource;
use App\Filament\Resources\RecommendationResource;
use App\Models\ApparatusDefectRecommendation;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class PendingRecommendationsWidget extends BaseWidget
{
    protected static ?string $heading = 'Pending Replacement Recommendations';
    protected static ?int $sort = 3;

    protected int | string | array $columnSpan = [
        'sm' => 1,
        'md' => 2,
        'xl' => 2,
    ];

    public function table(Table $table): Table
    {
        return $table
            ->query(
                ApparatusDefectRecommendation::query()
                    ->where('status', 'pending')
                    ->with(['defect.apparatus', 'equipmentItem'])
                    ->orderBy('created_at', 'desc')
            )
            ->columns([
                Tables\Columns\TextColumn::make('defect.apparatus.unit_id')
                    ->label('Apparatus')
                    ->badge()
                    ->url(fn ($record) => ApparatusResource::getUrl('edit', ['record' => $record->defect->apparatus_id])),
                
                Tables\Columns\TextColumn::make('defect.item')
                    ->label('Defect'),
                
                Tables\Columns\TextColumn::make('equipment_item.name')
                    ->label('Recommended')
                    ->url(fn ($record) => 
                        $record->equipment_item ? 
                        EquipmentItemResource::getUrl('edit', ['record' => $record->equipment_item_id]) : 
                        null
                    ),
                
                Tables\Columns\TextColumn::make('match_confidence')
                    ->formatStateUsing(fn ($state) => number_format($state * 100, 0) . '%')
                    ->badge()
                    ->color(fn ($state) => 
                        $state >= 0.8 ? 'success' : 
                        ($state >= 0.5 ? 'warning' : 'danger')
                    ),
                
                Tables\Columns\TextColumn::make('created_at')
                    ->since(),
            ])
            ->actions([
                Tables\Actions\Action::make('view')
                    ->url(fn ($record) => RecommendationResource::getUrl('edit', ['record' => $record]))
                    ->icon('heroicon-o-arrow-right'),
            ])
            ->paginated([5, 10])
            ->defaultPaginationPageOption(5)
            ->emptyStateHeading('No pending recommendations')
            ->emptyStateIcon('heroicon-o-check-circle');
    }
}
