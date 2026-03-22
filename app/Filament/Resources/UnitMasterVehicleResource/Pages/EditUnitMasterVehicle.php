<?php

namespace App\Filament\Resources\UnitMasterVehicleResource\Pages;

use App\Filament\Resources\UnitMasterVehicleResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditUnitMasterVehicle extends EditRecord
{
    protected static string $resource = UnitMasterVehicleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
