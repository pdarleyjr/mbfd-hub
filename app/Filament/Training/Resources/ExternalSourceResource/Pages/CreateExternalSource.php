<?php

namespace App\Filament\Training\Resources\ExternalSourceResource\Pages;

use App\Filament\Training\Resources\ExternalSourceResource;
use Filament\Resources\Pages\CreateRecord;

class CreateExternalSource extends CreateRecord
{
    protected static string $resource = ExternalSourceResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['division'] = 'training';
        $data['created_by'] = auth()->id();

        if (isset($data['token'])) {
            $data['token_encrypted'] = \Illuminate\Support\Facades\Crypt::encryptString($data['token']);
            unset($data['token']);
        }

        return $data;
    }
}
