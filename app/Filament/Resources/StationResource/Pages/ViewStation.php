<?php

namespace App\Filament\Resources\StationResource\Pages;

use App\Filament\Resources\StationResource;
use App\Models\Station;
use App\Services\StationStaffingService;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Database\Eloquent\Model;

class ViewStation extends ViewRecord
{
    protected static string $resource = StationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
        ];
    }

    /**
     * Load everything the station profile summarises in one pass: apparatus for
     * the staffing figures and the readiness counts as subqueries.
     */
    protected function resolveRecord(int|string $key): Model
    {
        $record = parent::resolveRecord($key);

        if (! $record instanceof Station) {
            return $record;
        }

        return $record
            ->load(['apparatuses' => fn ($query) => $query->select(StationStaffingService::APPARATUS_COLUMNS)])
            ->loadCount([
                'rooms',
                'inventoryItems as low_stock_count' => fn ($query) => $query->where('status', 'low'),
                'inventoryItems as ordered_stock_count' => fn ($query) => $query->where('status', 'ordered'),
                'supplyRequests as supply_request_count' => fn ($query) => $query->active()->whereIn('status', ['open', 'ordered']),
            ]);
    }
}
