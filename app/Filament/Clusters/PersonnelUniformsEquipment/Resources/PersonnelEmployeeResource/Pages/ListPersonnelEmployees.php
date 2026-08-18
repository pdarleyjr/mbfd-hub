<?php

declare(strict_types=1);

namespace App\Filament\Clusters\PersonnelUniformsEquipment\Resources\PersonnelEmployeeResource\Pages;

use App\Filament\Clusters\PersonnelUniformsEquipment\Resources\PersonnelEmployeeResource;
use Filament\Resources\Pages\ListRecords;

class ListPersonnelEmployees extends ListRecords
{
    protected static string $resource = PersonnelEmployeeResource::class;
}
