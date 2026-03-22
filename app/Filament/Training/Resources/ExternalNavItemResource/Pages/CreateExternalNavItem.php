<?php

namespace App\Filament\Training\Resources\ExternalNavItemResource\Pages;

use App\Filament\Training\Resources\ExternalNavItemResource;
use Filament\Resources\Pages\CreateRecord;

class CreateExternalNavItem extends CreateRecord
{
    protected static string $resource = ExternalNavItemResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['division'] = 'training';
        $data['created_by'] = auth()->id();
        return $data;
    }
}
