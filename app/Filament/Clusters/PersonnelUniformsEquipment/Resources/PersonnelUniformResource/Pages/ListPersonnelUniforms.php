<?php

namespace App\Filament\Clusters\PersonnelUniformsEquipment\Resources\PersonnelUniformResource\Pages;

use App\Filament\Clusters\PersonnelUniformsEquipment\Resources\PersonnelUniformResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPersonnelUniforms extends ListRecords
{
    protected static string $resource = PersonnelUniformResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
