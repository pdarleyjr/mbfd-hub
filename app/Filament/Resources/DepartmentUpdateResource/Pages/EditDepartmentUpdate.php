<?php

declare(strict_types=1);

namespace App\Filament\Resources\DepartmentUpdateResource\Pages;

use App\Filament\Resources\DepartmentUpdateResource;
use App\Jobs\SendDepartmentUpdateNotification;
use App\Models\DepartmentUpdate;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

final class EditDepartmentUpdate extends EditRecord
{
    protected static string $resource = DepartmentUpdateResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\ViewAction::make(), Actions\DeleteAction::make()];
    }

    protected function afterSave(): void
    {
        $record = $this->getRecord();
        if ($record instanceof DepartmentUpdate) {
            SendDepartmentUpdateNotification::dispatch($record->id)->afterCommit();
        }
    }
}
