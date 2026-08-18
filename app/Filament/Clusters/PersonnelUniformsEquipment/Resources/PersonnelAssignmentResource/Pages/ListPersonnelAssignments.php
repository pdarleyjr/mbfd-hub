<?php

declare(strict_types=1);

namespace App\Filament\Clusters\PersonnelUniformsEquipment\Resources\PersonnelAssignmentResource\Pages;

use App\Filament\Clusters\PersonnelUniformsEquipment\Resources\PersonnelAssignmentResource;
use Filament\Resources\Pages\ListRecords;

class ListPersonnelAssignments extends ListRecords
{
    protected static string $resource = PersonnelAssignmentResource::class;
}
