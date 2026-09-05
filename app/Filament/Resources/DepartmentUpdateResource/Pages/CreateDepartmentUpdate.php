<?php

declare(strict_types=1);

namespace App\Filament\Resources\DepartmentUpdateResource\Pages;

use App\Filament\Resources\DepartmentUpdateResource;
use App\Jobs\SendDepartmentUpdateNotification;
use App\Models\DepartmentUpdate;
use Filament\Resources\Pages\CreateRecord;

final class CreateDepartmentUpdate extends CreateRecord
{
    protected static string $resource = DepartmentUpdateResource::class;

    public function mount(): void
    {
        parent::mount();

        $this->previousUrl = DepartmentUpdateResource::getUrl();
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['author_id'] = auth()->id();

        return $data;
    }

    protected function afterCreate(): void
    {
        $record = $this->getRecord();
        if ($record instanceof DepartmentUpdate) {
            SendDepartmentUpdateNotification::dispatch($record->id)->afterCommit();
        }
    }
}
