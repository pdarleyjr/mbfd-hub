<?php

declare(strict_types=1);

namespace App\Filament\Resources\ApparatusResource\RelationManagers;

use App\Filament\Concerns\HasRelationExportActions;
use App\Filament\Resources\ApparatusOperationalAlertResource;
use App\Models\Apparatus;
use App\Models\ApparatusOperationalAlert;
use App\Services\Exports\ExportableTable;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Alerts about the unit this truck carries now, and alerts raised while this truck carried it. A plain relation
 * cannot say that, so the table has its own query and the Apparatus model stays unchanged.
 */
class OperationalAlertsRelationManager extends RelationManager implements ExportableTable
{
    use HasRelationExportActions;

    protected static string $relationship = 'operationalAlerts';

    protected static ?string $title = 'Operational alerts';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can('viewAny', ApparatusOperationalAlert::class) ?? false;
    }

    /** Badge counts the alerts still active. */
    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        $count = $ownerRecord instanceof Apparatus ? self::forTruck($ownerRecord)->active()->count() : 0;

        return $count > 0 ? (string) $count : null;
    }

    public static function getBadgeColor(Model $ownerRecord, string $pageClass): ?string
    {
        return 'warning';
    }

    /** @return Builder<ApparatusOperationalAlert> */
    public static function forTruck(Apparatus $truck): Builder
    {
        return ApparatusOperationalAlert::query()->where(fn (Builder $query): Builder => $query
            ->where('apparatus_id', $truck->getKey())
            ->when(filled($truck->slug), fn (Builder $inner): Builder => $inner->orWhere('unit_slug', $truck->slug)));
    }

    public function getRelationship(): Relation|Builder
    {
        return $this->getTableQuery();
    }

    protected function getTableQuery(): Builder
    {
        $owner = $this->getOwnerRecord();
        abort_unless($owner instanceof Apparatus, 404);

        return self::forTruck($owner);
    }

    public function table(Table $table): Table
    {
        return static::withExportActions($table
            ->columns(ApparatusOperationalAlertResource::columns(showUnit: false))
            ->defaultSort(fn (Builder $query): Builder => ApparatusOperationalAlertResource::activeFirst($query))
            ->recordUrl(fn (ApparatusOperationalAlert $record): string => ApparatusOperationalAlertResource::getUrl('view', ['record' => $record]))
            ->actions([
                Tables\Actions\Action::make('open')
                    ->label('Open')
                    ->url(fn (ApparatusOperationalAlert $record): string => ApparatusOperationalAlertResource::getUrl('view', ['record' => $record])),
            ]));
    }
}
