<?php

declare(strict_types=1);

namespace App\Filament\Resources\AiPlaybookResource\Pages;

use App\Filament\Resources\AiPlaybookResource;
use App\Models\AiAutomation\AiAutomationPlaybook;
use App\Models\User;
use App\Services\AiAutomation\AiAutomationAccess;
use App\Services\AiAutomation\PlaybookRepository;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

final class EditAiPlaybook extends EditRecord
{
    protected static string $resource = AiPlaybookResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    protected function getSaveFormAction(): Action
    {
        return parent::getSaveFormAction()->label('Save new version');
    }

    /** @param array<string, mixed> $data */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $editor = auth()->user();
        abort_unless($editor instanceof User && AiAutomationAccess::allowsCurrentUser(), 403);
        abort_unless($record instanceof AiAutomationPlaybook, 404);

        try {
            return app(PlaybookRepository::class)->update($record, (string) ($data['body'] ?? ''), $editor);
        } catch (InvalidArgumentException $e) {
            Notification::make()->danger()->title('Not saved')->body($e->getMessage())->send();
            $this->halt();

            return $record;
        }
    }

    protected function afterSave(): void
    {
        $this->record->refresh();
        $this->fillForm();
    }

    protected function getSavedNotificationTitle(): string
    {
        return 'Saved. The next reply uses this wording.';
    }
}
