<?php

namespace App\Filament\Resources\Under25kProjectResource\Pages;

use App\Filament\Resources\Under25kProjectResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditUnder25kProject extends EditRecord
{
    protected static string $resource = Under25kProjectResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\DeleteAction::make(),
        ];
    }
}
