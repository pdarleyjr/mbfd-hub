<?php

namespace App\Filament\Resources\TakeHomeVehicleInspectionResource\Pages;

use App\Filament\Resources\TakeHomeVehicleInspectionResource;
use Filament\Resources\Pages\ListRecords;

class ListTakeHomeVehicleInspections extends ListRecords
{
    protected static string $resource = TakeHomeVehicleInspectionResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
