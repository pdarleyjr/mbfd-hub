<?php

declare(strict_types=1);

namespace App\Filament\Resources\ApparatusOperationalAlertResource\Pages;

use App\Filament\Resources\ApparatusOperationalAlertResource;
use App\Models\User;
use App\Services\Fleet\ApparatusOperationalAlertService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreateApparatusOperationalAlert extends CreateRecord
{
    protected static string $resource = ApparatusOperationalAlertResource::class;

    protected static bool $canCreateAnother = false;

    public function getTitle(): string
    {
        return 'New operational alert';
    }

    /** @param array<string, mixed> $data */
    protected function handleRecordCreation(array $data): Model
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);

        try {
            return app(ApparatusOperationalAlertService::class)->create(ApparatusOperationalAlertResource::forService($data), $actor);
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
