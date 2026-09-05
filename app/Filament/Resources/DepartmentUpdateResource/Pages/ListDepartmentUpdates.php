<?php

declare(strict_types=1);

namespace App\Filament\Resources\DepartmentUpdateResource\Pages;

use App\Filament\Resources\DepartmentUpdateResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

final class ListDepartmentUpdates extends ListRecords
{
    protected static string $resource = DepartmentUpdateResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()->label('New Department Update')];
    }
}
