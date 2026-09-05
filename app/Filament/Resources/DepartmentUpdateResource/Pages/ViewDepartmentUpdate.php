<?php

declare(strict_types=1);

namespace App\Filament\Resources\DepartmentUpdateResource\Pages;

use App\Filament\Resources\DepartmentUpdateResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

final class ViewDepartmentUpdate extends ViewRecord
{
    protected static string $resource = DepartmentUpdateResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\EditAction::make()];
    }
}
