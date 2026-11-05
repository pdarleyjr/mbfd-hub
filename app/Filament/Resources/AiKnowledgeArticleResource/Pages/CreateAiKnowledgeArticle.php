<?php

declare(strict_types=1);

namespace App\Filament\Resources\AiKnowledgeArticleResource\Pages;

use App\Filament\Resources\AiKnowledgeArticleResource;
use App\Models\AiAutomation\AiKnowledgeArticle;
use App\Models\AiAutomation\AiKnowledgeArticleVersion as Version;
use App\Models\User;
use App\Services\AiAutomation\Admin\KnowledgeAdminSupport;
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

    protected static bool $canCreateAnother = false;

    private bool $publishAfterCreate = false;

    public function getTitle(): string
    {
        return 'New article';
    }

    protected function getFormActions(): array
    {
        return [
            $this->getCreateFormAction(),
            Action::make('createAndPublish')
                ->label('Save and publish')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Publish this now?')
                ->modalDescription('Automated answers can use this wording as soon as you confirm.')
                ->modalSubmitActionLabel('Publish now')
                ->action(function (): void {
                    $this->publishAfterCreate = true;
                    $this->create();
                }),
            $this->getCancelFormAction(),
        ];
    }

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()->label('Save draft');
    }

    /** @param array<string, mixed> $data */
    protected function handleRecordCreation(array $data): Model
    {
        $editor = auth()->user();
        abort_unless($editor instanceof User && AiAutomationAccess::allowsCurrentUser(), 403);

        $title = trim((string) ($data['title'] ?? ''));
        $data['slug'] = KnowledgeAdminSupport::slugFor($title);
        $data['topic_tags'] = array_values(array_unique([...(array) ($data['topic_tags'] ?? []), ...KnowledgeAdminSupport::tagsFor($title)]));

        try {
            return app(KnowledgeLibrary::class)->createArticle($data, $editor);
        } catch (InvalidArgumentException $e) {
            Notification::make()->danger()->title('Not saved')->body($e->getMessage())->send();
            $this->halt();

            throw $e;
        }
    }

    protected function afterCreate(): void
    {
        if (! $this->publishAfterCreate) {
            return;
        }
        $editor = auth()->user();
        $article = $this->getRecord();
        if (! $editor instanceof User || ! $article instanceof AiKnowledgeArticle) {
            return;
        }
        $draft = app(KnowledgeLibrary::class)->openDraft($article);
        if (! $draft instanceof Version) {
            return;
        }
        try {
            app(KnowledgeLibrary::class)->publish($draft, $editor);
            Notification::make()->success()->title('Published. Automated answers can use this now.')->send();
        } catch (InvalidArgumentException $e) {
            Notification::make()->warning()->title('Saved, but not published')->body($e->getMessage())->send();
        }
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return $this->publishAfterCreate ? null : 'Draft saved. It is not used in answers until you publish it.';
    }
}
