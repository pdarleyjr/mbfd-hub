<?php

namespace App\Filament\Resources\ApparatusResource\Pages;

use App\Filament\Resources\ApparatusResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class CreateApparatus extends CreateRecord
{
    protected static string $resource = ApparatusResource::class;

    /**
     * The new apparatus row, its master vehicle link and any master row or audit entries
     * it causes are saved together or not at all.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        return DB::transaction(fn (): Model => parent::handleRecordCreation($data));
    }
}
