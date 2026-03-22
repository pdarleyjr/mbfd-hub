<?php

namespace App\Filament\Resources\Under25kProjectResource\Pages;

use App\Filament\Resources\Under25kProjectResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListUnder25kProjects extends ListRecords
{
    protected static string $resource = Under25kProjectResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
