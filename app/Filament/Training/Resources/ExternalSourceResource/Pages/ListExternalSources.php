<?php

namespace App\Filament\Training\Resources\ExternalSourceResource\Pages;

use App\Filament\Training\Resources\ExternalSourceResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListExternalSources extends ListRecords
{
    protected static string $resource = ExternalSourceResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}
