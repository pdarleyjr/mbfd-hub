<?php

declare(strict_types=1);

namespace App\Filament\Resources\StationInventorySubmissionResource\Pages;

use App\Filament\Resources\StationInventorySubmissionResource;
use Filament\Resources\Pages\ListRecords;

final class ListStationInventorySubmissions extends ListRecords
{
    protected static string $resource = StationInventorySubmissionResource::class;
}
