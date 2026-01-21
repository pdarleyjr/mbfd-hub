<?php

namespace App\Filament\Resources\CapitalProjectResource\Pages;

use App\Filament\Resources\CapitalProjectResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditCapitalProject extends EditRecord
{
    protected static string $resource = CapitalProjectResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
