<?php

namespace App\Filament\Resources\ApparatusResource\Pages;

use App\Filament\Resources\ApparatusResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditApparatus extends EditRecord
{
    protected static string $resource = ApparatusResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
