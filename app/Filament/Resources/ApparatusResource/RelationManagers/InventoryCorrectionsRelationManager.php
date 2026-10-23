<?php

declare(strict_types=1);

namespace App\Filament\Resources\ApparatusResource\RelationManagers;

use App\Filament\Resources\InventoryCorrectionResource;
use App\Models\Apparatus;
use App\Models\ApparatusInventoryChange;
use App\Models\ApparatusInventoryCorrection;
use App\Services\Inventory\InventoryCorrectionReason;
use App\Support\Fleet\InventoryCorrectionAccess;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** Member-reported checklist corrections for this unit and the approved changes now in effect. */
class InventoryCorrectionsRelationManager extends RelationManager
{
    protected static string $relationship = 'inventoryCorrections';

    protected static ?string $title = 'Inventory corrections';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return InventoryCorrectionAccess::canReview(auth()->user());
    }

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        $count = $ownerRecord instanceof Apparatus ? $ownerRecord->inventoryCorrections()->open()->count() : 0;

        return $count > 0 ? (string) $count : null;
    }

    public static function getBadgeColor(Model $ownerRecord, string $pageClass): ?string
    {
        return 'warning';
    }

    public function table(Table $table): Table
    {
        $owner = $this->getOwnerRecord();
        $active = $owner instanceof Apparatus
            ? ApparatusInventoryChange::query()->where('apparatus_id', $owner->getKey())->active()->count()
            : 0;

        return $table
            ->description($active > 0
                ? "{$active} approved ".($active === 1 ? 'change is' : 'changes are').' in effect on this unit\'s Daily Checkout checklist.'
                : 'This unit\'s Daily Checkout checklist matches its template.')
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('created_at')->label('Reported')->dateTime('M j, Y', 'America/New_York')->sortable(),
                Tables\Columns\TextColumn::make('item')->label('Item')->wrap()
                    ->state(fn (ApparatusInventoryCorrection $record): string => InventoryCorrectionResource::itemLabel($record)),
                Tables\Columns\TextColumn::make('reason')->label('Reported problem')->wrap()
                    ->formatStateUsing(fn (?string $state): string => InventoryCorrectionReason::label($state)),
                Tables\Columns\TextColumn::make('status')->label('Status')->badge()
                    ->formatStateUsing(fn (?string $state): string => ApparatusInventoryCorrection::statusLabel($state))
                    ->color(fn (?string $state): string => match ($state) {
                        ApparatusInventoryCorrection::STATUS_SUBMITTED => 'warning',
                        ApparatusInventoryCorrection::STATUS_CLARIFICATION => 'info',
                        ApparatusInventoryCorrection::STATUS_APPLIED => 'success',
                        default => 'gray',
                    }),
            ])
            ->actions([
                Tables\Actions\Action::make('review')->label('Review')
                    ->url(fn (ApparatusInventoryCorrection $record): string => InventoryCorrectionResource::getUrl('view', ['record' => $record])),
            ]);
    }
}
