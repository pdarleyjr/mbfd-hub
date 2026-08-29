<?php

namespace App\Filament\Resources\ApparatusResource\Pages;

use App\Filament\Resources\ApparatusResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewApparatus extends ViewRecord
{
    protected static string $resource = ApparatusResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
        ];
    }
}
