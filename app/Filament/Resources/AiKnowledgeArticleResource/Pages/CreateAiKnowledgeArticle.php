<?php

declare(strict_types=1);

namespace App\Filament\Resources\AiKnowledgeArticleResource\Pages;

use App\Filament\Resources\AiKnowledgeArticleResource;
use App\Models\User;
use App\Services\AiAutomation\AiAutomationAccess;
use App\Services\AiAutomation\Knowledge\KnowledgeLibrary;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

final class CreateAiKnowledgeArticle extends CreateRecord
{
    protected static string $resource = AiKnowledgeArticleResource::class;

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()->label('Save draft');
    }

    /** @param array<string, mixed> $data */
    protected function handleRecordCreation(array $data): Model
    {
        $editor = auth()->user();
        abort_unless($editor instanceof User && AiAutomationAccess::allowsCurrentUser(), 403);

        try {
            return app(KnowledgeLibrary::class)->createArticle($data, $editor);
        } catch (InvalidArgumentException $e) {
            Notification::make()->danger()->title('Not saved')->body($e->getMessage())->send();
            $this->halt();

            throw $e;
        }
    }

    protected function getCreatedNotificationTitle(): string
    {
        return 'Draft saved. It is not used in replies until you publish it.';
    }
}
