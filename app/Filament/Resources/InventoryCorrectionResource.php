<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Concerns\EnterpriseTable;
use App\Filament\Concerns\HasExportActions;
use App\Filament\Resources\InventoryCorrectionResource\Pages;
use App\Models\Apparatus;
use App\Models\ApparatusInventoryCorrection;
use App\Models\User;
use App\Services\Inventory\InventoryCorrectionReason;
use App\Support\Fleet\InventoryCorrectionAccess;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Logistics' queue of member-reported Daily Checkout inventory corrections
 * (docs/apparatus-inventory-corrections.md section 4). Decisions happen on the view page.
 */
class InventoryCorrectionResource extends Resource
{
    use EnterpriseTable;
    use HasExportActions;

    protected static ?string $model = ApparatusInventoryCorrection::class;

    protected static ?string $navigationGroup = 'Fleet';

    protected static ?int $navigationSort = 16;

    protected static ?string $navigationIcon = 'heroicon-o-pencil-square';

    protected static ?string $navigationLabel = 'Inventory corrections';

    protected static ?string $modelLabel = 'Inventory correction';

    protected static ?string $slug = 'inventory-corrections';

    public const OPEN_COUNT_CACHE_KEY = 'inventory-corrections:open-count';

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user instanceof User && InventoryCorrectionAccess::canReview($user);
    }

    public static function canView(Model $record): bool
    {
        return static::canViewAny();
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

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        if (! static::canViewAny()) {
            return null;
        }
        // Every admin page renders the navigation: keep the count off the database hot path.
        $open = (int) Cache::remember(self::OPEN_COUNT_CACHE_KEY, 60, static fn (): int => ApparatusInventoryCorrection::query()->open()->count());

        return $open > 0 ? (string) $open : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Awaiting a Logistics decision';
    }

    public static function table(Table $table): Table
    {
        return static::withExportActions(self::applyEnterpriseDefaults($table)
            ->modifyQueryUsing(fn ($query) => $query->with(['apparatus:id,designation,name,unit_id', 'reporter:id,name,display_name']))
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Reported')
                    ->dateTime('M j, Y g:i A', \App\Support\HubTimezone::NAME)
                    ->sortable()
                    ->wrap()
                    ->extraAttributes(['class' => 'hub-phone-summary'])
                    ->description(fn (ApparatusInventoryCorrection $record): string => self::unitLabel($record).' · '.self::itemLabel($record)),
                Tables\Columns\TextColumn::make('apparatus.designation')->label('Apparatus')->visibleFrom('md')
                    ->formatStateUsing(fn (ApparatusInventoryCorrection $record): string => self::unitLabel($record)),
                Tables\Columns\TextColumn::make('item')->label('Item')->visibleFrom('md')->searchable(['target_snapshot->name', 'proposal->name', 'member_note'])->wrap()
                    ->state(fn (ApparatusInventoryCorrection $record): string => self::itemLabel($record)),
                Tables\Columns\TextColumn::make('reason')->label('Reported problem')->wrap()
                    ->formatStateUsing(fn (?string $state): string => InventoryCorrectionReason::label($state)),
                Tables\Columns\TextColumn::make('reporter.name')->label('Member')->visibleFrom('lg')
                    ->formatStateUsing(fn (ApparatusInventoryCorrection $record): string => (string) ($record->reporter?->display_name ?: $record->reporter?->name ?: 'Member')),
                Tables\Columns\TextColumn::make('status')->label('Status')->badge()
                    ->formatStateUsing(fn (?string $state): string => ApparatusInventoryCorrection::statusLabel($state))
                    ->color(fn (?string $state): string => match ($state) {
                        ApparatusInventoryCorrection::STATUS_SUBMITTED => 'warning',
                        ApparatusInventoryCorrection::STATUS_CLARIFICATION => 'info',
                        ApparatusInventoryCorrection::STATUS_APPLIED => 'success',
                        ApparatusInventoryCorrection::STATUS_REJECTED, ApparatusInventoryCorrection::STATUS_REVERSED => 'gray',
                        default => 'gray',
                    }),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'open' => 'Needs a decision',
                        ...ApparatusInventoryCorrection::statusLabels(),
                    ])
                    ->default('open')
                    ->query(function ($query, array $data) {
                        $value = $data['value'] ?? null;
                        if ($value === 'open') {
                            return $query->whereIn('status', ApparatusInventoryCorrection::OPEN_STATUSES);
                        }

                        return filled($value) ? $query->where('status', $value) : $query;
                    }),
                SelectFilter::make('apparatus_id')
                    ->label('Apparatus')
                    ->options(fn (): array => Apparatus::query()->orderBy('designation')->get(['id', 'designation', 'name'])
                        ->mapWithKeys(fn (Apparatus $apparatus): array => [$apparatus->id => (string) ($apparatus->designation ?: $apparatus->name)])->all()),
                SelectFilter::make('reason')->label('Reported problem')->options(InventoryCorrectionReason::labels()),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()->label('Review'),
            ])
            ->bulkActions([]));
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListInventoryCorrections::route('/'),
            'view' => Pages\ViewInventoryCorrection::route('/{record}'),
        ];
    }

    public static function unitLabel(ApparatusInventoryCorrection $record): string
    {
        $apparatus = $record->getRelationValue('apparatus');

        return trim((string) ($apparatus?->designation ?: $apparatus?->name ?: $apparatus?->unit_id)) ?: 'Apparatus';
    }

    public static function itemLabel(ApparatusInventoryCorrection $record): string
    {
        $snapshot = (array) $record->target_snapshot;
        $name = $snapshot['name'] ?? (isset($record->proposal['name']) ? 'New: '.$record->proposal['name'] : 'Unlisted item');

        return $name.(isset($snapshot['compartment']) ? ' · '.$snapshot['compartment'] : '');
    }
}
