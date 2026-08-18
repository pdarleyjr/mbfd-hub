<?php

declare(strict_types=1);

namespace App\Filament\Clusters\PersonnelUniformsEquipment\Resources\PersonnelRequestResource\Pages;

use App\Filament\Clusters\PersonnelUniformsEquipment\Resources\PersonnelRequestResource;
use Filament\Resources\Pages\ListRecords;

class ListPersonnelRequests extends ListRecords
{
    protected static string $resource = PersonnelRequestResource::class;
}
