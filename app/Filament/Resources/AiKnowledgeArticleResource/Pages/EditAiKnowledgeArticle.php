<?php

declare(strict_types=1);

namespace App\Filament\Resources\AiKnowledgeArticleResource\Pages;

use App\Filament\Resources\AiKnowledgeArticleResource;
use App\Models\AiAutomation\AiKnowledgeArticle;
use App\Models\AiAutomation\AiKnowledgeArticleVersion as Version;
use App\Models\User;
use App\Services\AiAutomation\AiAutomationAccess;
use App\Services\AiAutomation\Knowledge\KnowledgeLibrary;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

final class EditAiKnowledgeArticle extends EditRecord
{
    protected static string $resource = AiKnowledgeArticleResource::class;

    private const CONTENT_FIELDS = ['title', 'body', 'source_note', 'effective_date', 'review_date'];

    protected function getHeaderActions(): array
    {
        return [
            Action::make('preview')
                ->label('Preview')
                ->icon('heroicon-o-eye')
                ->color('gray')
                ->modalHeading('Preview')
                ->modalContent(fn () => view('filament.admin.ai-automation.markdown-preview', [
                    'title' => (string) ($this->data['title'] ?? ''),
                    'html' => AiKnowledgeArticleResource::markdownHtml((string) ($this->data['body'] ?? '')),
                ]))
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Close')
                ->modalWidth('3xl'),
            Action::make('submitForReview')
                ->label('Submit for review')
                ->icon('heroicon-o-paper-airplane')
                ->color('gray')
                ->action(fn () => $this->transition(false)),
            Action::make('publish')
                ->label('Approve & publish')
                ->icon('heroicon-o-check-badge')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Approve and publish this version?')
                ->modalDescription(fn (): string => 'Automated replies can use this wording as soon as you confirm.'.($this->liveVersion() !== null ? ' It replaces live version '.$this->liveVersion()->version.'.' : ''))
                ->modalSubmitActionLabel('Publish now')
                ->action(fn () => $this->transition(true)),
            AiKnowledgeArticleResource::historyAction(Action::make('history')),
            AiKnowledgeArticleResource::withdrawAction(Action::make('withdraw'))
                ->after(fn () => $this->refreshState()),
        ];
    }

    private function article(): AiKnowledgeArticle
    {
        $record = $this->getRecord();
        if (! $record instanceof AiKnowledgeArticle) {
            throw new InvalidArgumentException('Knowledge Library pages only edit articles.');
        }

        return $record;
    }

    protected function getSaveFormAction(): Action
    {
        return parent::getSaveFormAction()->label('Save draft');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $article = $this->article();
        $working = app(KnowledgeLibrary::class)->workingVersion($article);
        if ($working === null) {
            return $data;
        }

        return array_merge($data, [
            'title' => $working->title,
            'body' => $working->body,
            'change_summary' => $working->isOpen() ? $working->change_summary : null,
            'source_note' => $working->source_note,
            'effective_date' => $working->effective_date?->toDateString(),
            'review_date' => $working->review_date?->toDateString(),
        ]);
    }

    /** @param array<string, mixed> $data */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        try {
            $version = $this->persist($data);
        } catch (InvalidArgumentException $e) {
            Notification::make()->danger()->title('Not saved')->body($e->getMessage())->send();
            $this->halt();

            return $record;
        }

        if ($version === null) {
            Notification::make()->title('Nothing to save')->body('The text matches the live version. Change something to start a new draft.')->send();
            $this->halt();
        }

        return $record->refresh();
    }

    protected function afterSave(): void
    {
        $this->refreshState();
    }

    protected function getSavedNotificationTitle(): string
    {
        return 'Draft saved. It is not used in replies until you publish it.';
    }

    /** Save the form as a draft first, then submit it for review or publish it. */
    private function transition(bool $publish): void
    {
        $user = auth()->user();
        abort_unless($user instanceof User && AiAutomationAccess::allowsCurrentUser(), 403);

        try {
            $version = $this->persist($this->form->getState());
            if ($version === null) {
                Notification::make()->warning()->title('Nothing to '.($publish ? 'publish' : 'submit'))->body('The text matches the live version.')->send();

                return;
            }
            $library = app(KnowledgeLibrary::class);
            if ($publish) {
                $library->publish($version, $user);
                Notification::make()->success()->title('Published. Replies can use this version now.')->send();
            } else {
                $library->submitForReview($version, $user);
                Notification::make()->success()->title('Submitted for review.')->send();
            }
        } catch (InvalidArgumentException $e) {
            Notification::make()->danger()->title('Not done')->body($e->getMessage())->send();

            return;
        }

        $this->refreshState();
    }

    /**
     * Writes the form into the open draft, or starts a new one. Returns null when there is no open draft and
     * the text is unchanged from the live version (only tags and the member-safe flag are applied then).
     *
     * @param  array<string, mixed>  $data
     */
    private function persist(array $data): ?Version
    {
        $user = auth()->user();
        abort_unless($user instanceof User && AiAutomationAccess::allowsCurrentUser(), 403);

        /** @var AiKnowledgeArticle $article */
        $article = $this->article();
        $library = app(KnowledgeLibrary::class);
        $open = $library->openDraft($article);

        if ($open === null) {
            $base = $library->workingVersion($article);
            if ($base !== null && $this->sameContent($base, $data)) {
                $library->updateSettings($article, $data['topic_tags'] ?? [], (bool) ($data['member_safe'] ?? true), (bool) ($data['always_include'] ?? false));

                return null;
            }
        }

        return $library->saveDraft($article, $data, $user);
    }

    /** @param array<string, mixed> $data */
    private function sameContent(Version $base, array $data): bool
    {
        $incoming = [
            'title' => trim((string) ($data['title'] ?? '')),
            'body' => trim(str_replace(["\r\n", "\r"], "\n", (string) ($data['body'] ?? ''))),
            'source_note' => trim((string) ($data['source_note'] ?? '')),
            'effective_date' => (string) ($data['effective_date'] ?? ''),
            'review_date' => (string) ($data['review_date'] ?? ''),
        ];
        $current = [
            'title' => trim((string) $base->title),
            'body' => trim((string) $base->body),
            'source_note' => trim((string) $base->source_note),
            'effective_date' => (string) $base->effective_date?->toDateString(),
            'review_date' => (string) $base->review_date?->toDateString(),
        ];

        return array_intersect_key($incoming, array_flip(self::CONTENT_FIELDS)) === $current;
    }

    private function liveVersion(): ?Version
    {
        $id = $this->article()->published_version_id;

        return $id === null ? null : Version::query()->find($id);
    }

    private function refreshState(): void
    {
        $this->article()->refresh();
        $this->fillForm();
    }
}
