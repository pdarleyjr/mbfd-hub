<?php

namespace App\Filament\Resources\UnitMasterVehicleResource\Pages;

use App\Filament\Resources\UnitMasterVehicleResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListUnitMasterVehicles extends ListRecords
{
    protected static string $resource = UnitMasterVehicleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
