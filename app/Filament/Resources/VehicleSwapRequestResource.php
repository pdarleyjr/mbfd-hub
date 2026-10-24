<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Concerns\EnterpriseTable;
use App\Filament\Concerns\HasExportActions;
use App\Filament\Resources\VehicleSwapRequestResource\Pages;
use App\Filament\Support\VehicleSwapDecisionAction;
use App\Models\ApparatusVehicleSwapRequest;
use App\Services\Fleet\VehicleSwapService;
use App\Support\Fleet\FleetVehicleCatalog;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * "Pending vehicle swaps": possible truck swaps members reported at Daily
 * Checkout, waiting for a Fleet decision. Viewing follows admin.fleet.view and
 * deciding follows admin.fleet.manage, through the admin capability gate.
 */
class VehicleSwapRequestResource extends Resource
{
    use EnterpriseTable;
    use HasExportActions;

    protected static ?string $model = ApparatusVehicleSwapRequest::class;

    protected static ?string $slug = 'vehicle-swap-requests';

    protected static ?string $navigationIcon = 'heroicon-o-arrows-right-left';

    protected static ?string $navigationGroup = 'Operations';

    protected static ?int $navigationSort = 71;

    protected static ?string $navigationLabel = 'Pending vehicle swaps';

    protected static ?string $modelLabel = 'vehicle swap';

    protected static ?string $pluralModelLabel = 'vehicle swaps';

    public static function getNavigationBadge(): ?string
    {
        if (! static::canViewAny()) {
            return null;
        }
        $pending = ApparatusVehicleSwapRequest::query()->where('status', ApparatusVehicleSwapRequest::STATUS_PENDING)->count();

        return $pending > 0 ? (string) $pending : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function table(Table $table): Table
    {
        return static::withExportActions(self::applyEnterpriseDefaults($table)
            ->columns(self::columns(showUnit: true))
            ->filters([
                Tables\Filters\SelectFilter::make('apparatus_id')->relationship('apparatus', 'designation')->label('Unit')->searchable()->preload(),
            ])
            ->defaultSort('created_at', 'desc')
            ->actions(self::rowActions()));
    }

    /** @return list<Tables\Actions\Action> */
    public static function rowActions(): array
    {
        return [VehicleSwapDecisionAction::make()];
    }

    /** @return list<Tables\Columns\Column> */
    public static function columns(bool $showUnit): array
    {
        return [
            Tables\Columns\TextColumn::make('unit_designation')
                ->label($showUnit ? 'Unit' : 'Report')
                ->weight('bold')
                ->searchable()
                ->wrap()
                ->extraAttributes(['class' => 'hub-phone-summary'])
                ->description(fn (ApparatusVehicleSwapRequest $record): string => 'Assigned '.($record->assigned_vehicle_number ?: 'none').', reported '.$record->reported_vehicle_number.' by '.$record->reporter_name.'. '.ApparatusVehicleSwapRequest::answerLabel($record->answer).'. '.ApparatusVehicleSwapRequest::statusLabel($record->status)),
            Tables\Columns\TextColumn::make('assigned_vehicle_number')->label('Assigned')->visibleFrom('md')->placeholder('None')->extraAttributes(['class' => 'tabular-nums']),
            Tables\Columns\TextColumn::make('reported_vehicle_number')->label('Reported')->visibleFrom('md')->weight('bold')->extraAttributes(['class' => 'tabular-nums']),
            Tables\Columns\TextColumn::make('other_units')
                ->label('Also on')
                ->visibleFrom('md')
                ->getStateUsing(fn (ApparatusVehicleSwapRequest $record): string => app(VehicleSwapService::class)->otherUnitsHolding($record)
                    ->map(fn ($unit): string => ($unit->designation ?: $unit->name).' ('.FleetVehicleCatalog::roleLabel(FleetVehicleCatalog::roleFor($unit->getAttribute('status'))).')')
                    ->implode(', ') ?: 'No unit')
                ->wrap(),
            Tables\Columns\TextColumn::make('reporter_name')->label('Reported by')->visibleFrom('md')->searchable(),
            Tables\Columns\TextColumn::make('created_at')->label('Reported')->visibleFrom('md')->dateTime('M j, Y g:i A')->sortable(),
            Tables\Columns\TextColumn::make('answer')
                ->label('Swap completed?')
                ->visibleFrom('md')
                ->badge()
                ->formatStateUsing(fn (string $state): string => ApparatusVehicleSwapRequest::answerLabel($state))
                ->color(fn (string $state): string => $state === ApparatusVehicleSwapRequest::ANSWER_SWAP_COMPLETED ? 'info' : 'warning'),
            Tables\Columns\TextColumn::make('note')->visibleFrom('md')->placeholder('No note')->limit(60)->wrap()->toggleable(isToggledHiddenByDefault: true),
            Tables\Columns\TextColumn::make('status')
                ->visibleFrom('md')
                ->badge()
                ->formatStateUsing(fn (string $state): string => ApparatusVehicleSwapRequest::statusLabel($state))
                ->color(fn (string $state): string => $state === ApparatusVehicleSwapRequest::STATUS_PENDING ? 'warning' : ($state === ApparatusVehicleSwapRequest::STATUS_REJECTED ? 'gray' : 'success')),
            Tables\Columns\TextColumn::make('decidedBy.name')->label('Decided by')->visibleFrom('md')->placeholder('Not decided')->toggleable(isToggledHiddenByDefault: true),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['apparatus', 'decidedBy']);
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('viewAny', ApparatusVehicleSwapRequest::class) ?? false;
    }

    public static function canView(Model $record): bool
    {
        return auth()->user()?->can('view', $record) ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListVehicleSwapRequests::route('/'),
        ];
    }
}
