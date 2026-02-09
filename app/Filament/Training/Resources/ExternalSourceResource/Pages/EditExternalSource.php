<?php

namespace App\Filament\Training\Resources\ExternalSourceResource\Pages;

use App\Filament\Training\Resources\ExternalSourceResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditExternalSource extends EditRecord
{
    protected static string $resource = ExternalSourceResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\DeleteAction::make()];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (isset($data['token']) && filled($data['token'])) {
            $data['token_encrypted'] = \Illuminate\Support\Facades\Crypt::encryptString($data['token']);
        }
        unset($data['token']);

        return $data;
    }
}
