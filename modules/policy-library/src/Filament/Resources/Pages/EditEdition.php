<?php

declare(strict_types=1);

namespace Mbfd\PolicyLibrary\Filament\Resources\Pages;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Mbfd\PolicyLibrary\Filament\Resources\EditionResource;
use Mbfd\PolicyLibrary\Filament\Resources\NodeResource;
use Mbfd\PolicyLibrary\Models\Edition;
use Mbfd\PolicyLibrary\Services\ImportService;

final class EditEdition extends EditRecord
{
    protected static string $resource = EditionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('navigation')->label('Review navigation & PDFs')->url(fn () => NodeResource::getUrl('index', ['tableFilters' => ['edition_id' => ['value' => $this->getRecord()->id]]], panel: 'policy-library')),
            Action::make('publish')->label('Publish edition')->requiresConfirmation()->modalDescription('This switches the complete manual. The previous edition remains in history.')
                ->visible(fn () => $this->getRecord()->id !== $this->getRecord()->manual->active_edition_id)
                ->action(function (): void {
                    abort_unless(EditionResource::canViewAny(), 403);
                    $record = $this->getRecord();
                    app(ImportService::class)->publish($record, auth('web')->id());
                    $record->refresh();
                    Notification::make()->title('Manual edition published.')->success()->send();
                }),
        ];
    }

    public function getRecord(): Edition
    {
        $record = parent::getRecord();
        if (! $record instanceof Edition) {
            throw new \LogicException('Manual edition not found.');
        }

        return $record;
    }
}
