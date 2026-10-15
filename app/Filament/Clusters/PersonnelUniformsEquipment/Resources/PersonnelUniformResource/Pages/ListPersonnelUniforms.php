<?php

namespace App\Filament\Clusters\PersonnelUniformsEquipment\Resources\PersonnelUniformResource\Pages;

use App\Filament\Clusters\PersonnelUniformsEquipment\Resources\PersonnelUniformResource;
use App\Filament\Resources\UniformResource\Pages\ListUniforms;

/** Same header actions as the stock list: add stock, assign stock to a member, open requests. */
class ListPersonnelUniforms extends ListUniforms
{
    protected static string $resource = PersonnelUniformResource::class;
}
