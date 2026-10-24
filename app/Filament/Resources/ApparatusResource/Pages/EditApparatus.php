<?php

namespace App\Filament\Resources\ApparatusResource\Pages;

use App\Filament\Resources\ApparatusResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class EditApparatus extends EditRecord
{
    protected static string $resource = ApparatusResource::class;

    /**
     * The apparatus edit, the master vehicle write-through and the audit entries commit
     * or roll back together.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return DB::transaction(fn (): Model => parent::handleRecordUpdate($record, $data));
    }

    public function hasCombinedRelationManagerTabsWithContent(): bool
    {
        return true;
    }

    public function getContentTabLabel(): ?string
    {
        return 'Overview';
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
