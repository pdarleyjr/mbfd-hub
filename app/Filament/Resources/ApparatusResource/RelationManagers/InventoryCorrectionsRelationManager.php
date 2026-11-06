<?php

declare(strict_types=1);

namespace App\Filament\Resources\ApparatusResource\RelationManagers;

use App\Filament\Concerns\HasRelationExportActions;
use App\Filament\Resources\InventoryCorrectionResource;
use App\Models\Apparatus;
use App\Models\ApparatusInventoryCorrection;
use App\Services\DailyCheckoutChecklistResolver;
use App\Services\Exports\ExportableTable;
use App\Services\Inventory\InventoryCorrectionReason;
use App\Support\Fleet\InventoryCorrectionAccess;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** Member-reported checklist corrections for this unit and the approved changes now in effect. */
class InventoryCorrectionsRelationManager extends RelationManager implements ExportableTable
{
    use HasRelationExportActions;

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
        // What the resolver really applies: an "active" change it skips (overlay conflict) is not in effect.
        $resolution = $owner instanceof Apparatus ? app(DailyCheckoutChecklistResolver::class)->resolve($owner) : null;
        $applied = count($resolution['inventory_change_ids'] ?? []);
        $notApplying = count($resolution['overlay_conflicts'] ?? []);
        $description = $applied > 0
            ? "{$applied} approved ".($applied === 1 ? 'change is' : 'changes are').' in effect on this unit\'s Daily Checkout checklist.'
            : 'This unit\'s Daily Checkout checklist matches its template.';
        if ($notApplying > 0) {
            $description .= " {$notApplying} approved ".($notApplying === 1 ? 'change no longer applies' : 'changes no longer apply')
                .' (the checklist template changed). Open Inventory corrections to retire or follow up on '.($notApplying === 1 ? 'it' : 'them').'.';
        }

        return static::withExportActions($table
            ->description($description)
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('created_at')->label('Reported')->dateTime('M j, Y', \App\Support\HubTimezone::NAME)->sortable(),
                Tables\Columns\TextColumn::make('item')->label('Item')->searchable(['target_snapshot->name', 'proposal->name', 'member_note'])->wrap()
                    ->state(fn (ApparatusInventoryCorrection $record): string => InventoryCorrectionResource::itemLabel($record)),
                Tables\Columns\TextColumn::make('reason')->label('Reported problem')->wrap()
                    ->formatStateUsing(fn (?string $state): string => InventoryCorrectionReason::label($state)),
                Tables\Columns\TextColumn::make('status')->label('Status')->badge()
                    ->formatStateUsing(fn (?string $state): string => ApparatusInventoryCorrection::statusLabel($state))
                    ->color(fn (?string $state): string => ApparatusInventoryCorrection::statusColor($state)),
            ])
            ->actions([
                Tables\Actions\Action::make('review')->label('Review')
                    ->url(fn (ApparatusInventoryCorrection $record): string => InventoryCorrectionResource::getUrl('view', ['record' => $record])),
            ]));
    }
}
