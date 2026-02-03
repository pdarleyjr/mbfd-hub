<?php

namespace App\Filament\Resources\StationInventorySubmissionResource\Pages;

use App\Filament\Resources\StationInventorySubmissionResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListStationInventorySubmissions extends ListRecords
{
    protected static string $resource = StationInventorySubmissionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            //
        ];
    }
}
