<?php

namespace App\Filament\Resources\InventoryLocationResource\Pages;

use App\Filament\Resources\InventoryLocationResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListInventoryLocations extends ListRecords
{
    protected static string $resource = InventoryLocationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
