<?php

namespace App\Filament\Resources\ApparatusResource\Pages;

use App\Filament\Resources\ApparatusResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateApparatus extends CreateRecord
{
    protected static string $resource = ApparatusResource::class;

    /**
     * The new apparatus row, its master vehicle link and any master row or audit entries
     * it causes are saved together or not at all. The unit identity guard on the model
     * reports against the form field it is about.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        try {
            return DB::transaction(fn (): Model => parent::handleRecordCreation($data));
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(collect($exception->errors())
                ->mapWithKeys(fn (array $messages, string $field): array => [(str_starts_with($field, 'data.') ? $field : 'data.'.$field) => $messages])
                ->all());
        }
    }
}
