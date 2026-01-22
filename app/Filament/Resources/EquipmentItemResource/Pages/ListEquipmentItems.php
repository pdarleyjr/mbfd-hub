<?php

namespace App\Filament\Resources\EquipmentItemResource\Pages;

use App\Filament\Resources\EquipmentItemResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListEquipmentItems extends ListRecords
{
    protected static string $resource = EquipmentItemResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
