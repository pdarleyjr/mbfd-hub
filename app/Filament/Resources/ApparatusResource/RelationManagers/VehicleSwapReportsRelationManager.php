<?php

declare(strict_types=1);

namespace App\Filament\Resources\ApparatusResource\RelationManagers;

use App\Filament\Concerns\HasRelationExportActions;
use App\Filament\Resources\VehicleSwapRequestResource;
use App\Models\Apparatus;
use App\Models\ApparatusVehicleSwapRequest;
use App\Services\Exports\ExportableTable;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** Vehicle swap reports for this unit, with the same Fleet decision as the queue. */
class VehicleSwapReportsRelationManager extends RelationManager implements ExportableTable
{
    use HasRelationExportActions;

    protected static string $relationship = 'vehicleSwapRequests';

    protected static ?string $title = 'Vehicle swaps';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can('viewAny', ApparatusVehicleSwapRequest::class) ?? false;
    }

    /** Badge counts reports still waiting for a decision. */
    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        $count = $ownerRecord instanceof Apparatus
            ? $ownerRecord->vehicleSwapRequests()->where('status', ApparatusVehicleSwapRequest::STATUS_PENDING)->count()
            : 0;

        return $count > 0 ? (string) $count : null;
    }

    public static function getBadgeColor(Model $ownerRecord, string $pageClass): ?string
    {
        return 'warning';
    }

    public function table(Table $table): Table
    {
        return static::withExportActions($table
            ->recordTitleAttribute('reported_vehicle_number')
            ->columns(VehicleSwapRequestResource::columns(showUnit: false))
            ->defaultSort('created_at', 'desc')
            ->actions(VehicleSwapRequestResource::rowActions()));
    }
}
