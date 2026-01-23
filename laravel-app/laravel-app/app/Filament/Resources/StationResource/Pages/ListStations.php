<?php

namespace App\Filament\Resources\StationResource\Pages;

use App\Filament\Resources\StationResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListStations extends ListRecords
{
    protected static string $resource = StationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
