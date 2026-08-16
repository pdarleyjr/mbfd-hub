<?php

declare(strict_types=1);

namespace App\Filament\Resources\StationRequestResource\Pages;

use App\Filament\Resources\StationRequestResource;
use Filament\Resources\Pages\ListRecords;

class ListStationRequests extends ListRecords
{
    protected static string $resource = StationRequestResource::class;
}
