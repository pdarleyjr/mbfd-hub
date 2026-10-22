<?php

namespace App\Filament\Resources\InspectionResource\Pages;

use App\Filament\Resources\InspectionResource;
use Filament\Resources\Pages\ListRecords;

class ListInspections extends ListRecords
{
    protected static string $resource = InspectionResource::class;

    /** Inspections are only created by the Daily Checkout app, never from the admin panel. */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
