<?php

declare(strict_types=1);

namespace App\Filament\Resources\PromotionalListResource\Pages;

use App\Filament\Resources\PromotionalListResource;
use App\Models\PromotionalList;
use App\Services\Personnel\Promotions\PromotionalListService;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class EditPromotionalList extends EditRecord
{
    protected static string $resource = PromotionalListResource::class;

    /** @param array<string, mixed> $data */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        if (! $record instanceof PromotionalList) {
            throw new InvalidArgumentException('Expected a promotional list, got '.$record::class.'.');
        }

        try {
            return app(PromotionalListService::class)->updateList(auth()->user(), $record, $data);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(collect($exception->errors())
                ->mapWithKeys(fn (array $messages, string $field): array => ['data.'.$field => $messages])->all());
        }
    }

    protected function getRedirectUrl(): string
    {
        return PromotionalListResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
