<?php

declare(strict_types=1);

namespace App\Filament\Resources\ApparatusOperationalAlertResource\Pages;

use App\Filament\Resources\ApparatusOperationalAlertResource;
use App\Models\ApparatusOperationalAlert;
use App\Models\User;
use App\Services\Fleet\ApparatusOperationalAlertService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class EditApparatusOperationalAlert extends EditRecord
{
    protected static string $resource = ApparatusOperationalAlertResource::class;

    public function getTitle(): string
    {
        return 'Edit operational alert';
    }

    /** @param array<string, mixed> $data */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        abort_unless($record instanceof ApparatusOperationalAlert, 404);
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);

        try {
            return app(ApparatusOperationalAlertService::class)->update($record, ApparatusOperationalAlertResource::forService($data), $actor);
        } catch (ValidationException $exception) {
            Notification::make()->danger()->title('The alert was not saved')
                ->body((string) (collect($exception->errors())->flatten()->first() ?? 'Check the form and try again.'))->persistent()->send();
            throw new Halt;
        }
    }

    protected function getRedirectUrl(): string
    {
        return ApparatusOperationalAlertResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
