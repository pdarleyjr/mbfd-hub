<?php

declare(strict_types=1);

namespace App\Filament\Resources\StationSupplyRequestResource\Pages;

use App\Filament\Resources\StationSupplyRequestResource;
use Filament\Resources\Pages\ListRecords;

class ListStationSupplyRequests extends ListRecords
{
    protected static string $resource = StationSupplyRequestResource::class;
}
