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
            Actions\DeleteAction::make()
                // Vehicles with take-home inspection history cannot be deleted.
                ->hidden(fn (): bool => ($record = $this->getRecord()) instanceof \App\Models\UnitMasterVehicle && ($record->takeHomeInspections()->exists() || $record->documents()->exists())),
        ];
    }
}
