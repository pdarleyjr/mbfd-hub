<?php

declare(strict_types=1);

namespace App\Filament\Resources\ApparatusResource\RelationManagers;

use App\Filament\Concerns\HasRelationExportActions;
use App\Filament\Resources\ApparatusChangeoutResource;
use App\Models\Apparatus;
use App\Models\ApparatusChangeout;
use App\Services\Exports\ExportableTable;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Changeouts this truck was part of, whether a unit left it or came to it, so the history follows the physical truck.
 * A plain relation cannot say "from or to", so the table has its own query and the Apparatus model stays unchanged.
 */
class ChangeoutsRelationManager extends RelationManager implements ExportableTable
{
    use HasRelationExportActions;

    protected static string $relationship = 'changeouts';

    protected static ?string $title = 'Vehicle changeouts';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can('viewAny', ApparatusChangeout::class) ?? false;
    }

    /** Badge counts changes nobody has acknowledged yet. */
    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        $count = $ownerRecord instanceof Apparatus
            ? self::forTruck($ownerRecord)->unacknowledged()->count()
            : 0;

        return $count > 0 ? (string) $count : null;
    }

    public static function getBadgeColor(Model $ownerRecord, string $pageClass): ?string
    {
        return 'warning';
    }

    /** @return Builder<ApparatusChangeout> */
    public static function forTruck(Apparatus $truck): Builder
    {
        return ApparatusChangeout::query()->involvingTruck((int) $truck->getKey());
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
            ->columns(ApparatusChangeoutResource::columns(showUnit: true))
            ->defaultSort(fn (Builder $query): Builder => ApparatusChangeoutResource::unacknowledgedFirst($query))
            ->recordUrl(fn (ApparatusChangeout $record): string => ApparatusChangeoutResource::getUrl('view', ['record' => $record]))
            ->actions([
                Tables\Actions\Action::make('review')
                    ->label('View')
                    ->url(fn (ApparatusChangeout $record): string => ApparatusChangeoutResource::getUrl('view', ['record' => $record])),
            ]));
    }
}
