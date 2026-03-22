<?php

namespace App\Filament\Training\Resources\ExternalNavItemResource\Pages;

use App\Filament\Training\Resources\ExternalNavItemResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditExternalNavItem extends EditRecord
{
    protected static string $resource = ExternalNavItemResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\DeleteAction::make()];
    }
}
