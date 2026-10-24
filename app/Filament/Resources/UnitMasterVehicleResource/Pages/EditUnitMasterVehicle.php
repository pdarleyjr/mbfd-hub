<?php

namespace App\Filament\Resources\UnitMasterVehicleResource\Pages;

use App\Filament\Resources\UnitMasterVehicleResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class EditUnitMasterVehicle extends EditRecord
{
    protected static string $resource = UnitMasterVehicleResource::class;

    /**
     * The master edit, its history rows and the apparatus copy update commit or roll back together.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return DB::transaction(fn (): Model => parent::handleRecordUpdate($record, $data));
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                // Vehicles with take-home inspection history cannot be deleted.
                ->hidden(fn (): bool => ($record = $this->getRecord()) instanceof \App\Models\UnitMasterVehicle && ($record->takeHomeInspections()->exists() || $record->documents()->exists())),
        ];
    }
}
