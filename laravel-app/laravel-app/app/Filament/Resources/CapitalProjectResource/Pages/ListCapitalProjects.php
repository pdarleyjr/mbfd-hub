<?php

namespace App\Filament\Resources\CapitalProjectResource\Pages;

use App\Filament\Resources\CapitalProjectResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListCapitalProjects extends ListRecords
{
    protected static string $resource = CapitalProjectResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
