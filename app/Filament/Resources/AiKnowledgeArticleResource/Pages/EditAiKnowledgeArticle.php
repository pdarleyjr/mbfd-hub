<?php

declare(strict_types=1);

namespace App\Filament\Resources\AiKnowledgeArticleResource\Pages;

use App\Filament\Resources\AiKnowledgeArticleResource;
use App\Models\AiAutomation\AiKnowledgeArticle;
use App\Models\AiAutomation\AiKnowledgeArticleVersion as Version;
use App\Models\User;
use App\Services\AiAutomation\Admin\KnowledgeAdminSupport;
use App\Services\AiAutomation\Admin\KnowledgeMatchExplainer;
use App\Services\AiAutomation\AiAutomationAccess;
use App\Services\AiAutomation\Knowledge\KnowledgeLibrary;
use App\Support\HubTimezone;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use InvalidArgumentException;

final class EditAiKnowledgeArticle extends EditRecord
{
    protected static string $resource = AiKnowledgeArticleResource::class;

    private const CONTENT_FIELDS = ['title', 'body', 'source_note', 'effective_date', 'review_date', 'sample_phrases'];

    public function getTitle(): string
    {
        return $this->article()->title;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('preview')
                ->label('Preview')
                ->icon('heroicon-o-eye')
                ->color('gray')
                ->modalHeading('How it reads')
                ->modalContent(fn () => view('filament.admin.ai-automation.markdown-preview', [
                    'title' => (string) ($this->data['title'] ?? ''),
                    'html' => AiKnowledgeArticleResource::markdownHtml((string) ($this->data['body'] ?? '')),
                    'checks' => $this->savedChecks(),
                ]))
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Close')
                ->modalWidth('3xl'),
            Action::make('publish')
                ->label('Publish')
                ->icon('heroicon-o-check-badge')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Make this the live wording?')
                ->modalDescription(fn (): string => 'Automated answers can use this wording as soon as you confirm.'.($this->liveVersion() !== null ? ' It replaces the wording that is live now.' : ''))
                ->modalSubmitActionLabel('Publish now')
                ->action(fn () => $this->publishNow()),
            $this->restoreAction(),
            $this->testMatchingAction(),
            AiKnowledgeArticleResource::historyAction(Action::make('history')),
            AiKnowledgeArticleResource::withdrawAction(Action::make('withdraw'))
                ->after(fn () => $this->refreshState()),
        ];
    }

    private function testMatchingAction(): Action
    {
        return Action::make('testMatching')
            ->label('Test a message')
            ->icon('heroicon-o-magnifying-glass')
            ->color('gray')
            ->modalHeading('Which instructions would be used?')
            ->modalDescription('Type a message a member might send. This shows which published instructions would be used for it, and why. Nothing is sent.')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close')
            ->form([
                TextInput::make('message')->label('A message from a member')->maxLength(600)->live(onBlur: true),
                Placeholder::make('matches')->hiddenLabel()->content(fn (Get $get) => view('filament.admin.ai-automation.match-results', [
                    'matches' => app(KnowledgeMatchExplainer::class)->forMessage((string) $get('message')),
                    'message' => trim((string) $get('message')),
                ])),
            ]);
    }

    /** @return list<array{phrase: string, ok: bool, note: string}> checks of the saved wording (save first to check changes) */
    private function savedChecks(): array
    {
        $working = app(KnowledgeLibrary::class)->workingVersion($this->article());

        return $working === null ? [] : app(KnowledgeMatchExplainer::class)->checkVersion($working);
    }

    private function restoreAction(): Action
    {
        return Action::make('restorePrevious')
            ->label('Restore previous')
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('gray')
            ->visible(fn (): bool => AiAutomationAccess::allowsCurrentUser() && $this->restorableVersions() !== [])
            ->modalHeading('Go back to an earlier version')
            ->modalDescription('The earlier wording is copied into a new draft. Nothing is lost, and the version you leave stays in the past versions.')
            ->modalSubmitActionLabel('Restore')
            ->form([
                Select::make('version_id')
                    ->label('Which version?')
                    ->options(fn (): array => $this->restorableVersions())
                    ->required()
                    ->live(),
                Placeholder::make('version_preview')
                    ->label('How it reads')
                    ->content(function (Get $get): HtmlString {
                        $version = $this->versionById($get('version_id'));

                        return new HtmlString($version === null
                            ? '<span class="text-sm text-gray-500">Choose a version to see its wording.</span>'
                            : '<div class="prose max-w-none text-sm">'.AiKnowledgeArticleResource::markdownHtml((string) $version->body).'</div>');
                    }),
                Toggle::make('go_live')
                    ->label('Make it live right away')
                    ->helperText('Leave off to look it over first. It will wait here as a draft until you press Publish.')
                    ->default(false),
            ])
            ->action(function (array $data): void {
                $this->restore($this->versionById($data['version_id'] ?? null), (bool) ($data['go_live'] ?? false));
            });
    }

    /** @return array<int, string> */
    private function restorableVersions(): array
    {
        $working = app(KnowledgeLibrary::class)->workingVersion($this->article());
        $options = [];
        foreach ($this->article()->versions()->get() as $version) {
            if ($version->isOpen() || ($working !== null && $working->getKey() === $version->getKey() && $version->status === Version::STATUS_PUBLISHED)) {
                continue;
            }
            $options[(int) $version->getKey()] = KnowledgeAdminSupport::versionLabel($version, HubTimezone::NAME);
        }

        return $options;
    }

    private function versionById(mixed $id): ?Version
    {
        if (! is_numeric($id)) {
            return null;
        }

        return Version::query()->where('article_id', $this->article()->getKey())->whereKey((int) $id)->first();
    }

    private function restore(?Version $version, bool $goLive): void
    {
        $user = auth()->user();
        abort_unless($user instanceof User && AiAutomationAccess::allowsCurrentUser(), 403);
        if ($version === null) {
            Notification::make()->warning()->title('Choose a version to go back to.')->send();

            return;
        }

        try {
            $library = app(KnowledgeLibrary::class);
            $draft = $library->saveDraft($this->article(), [
                'title' => $version->title,
                'body' => $version->body,
                'source_note' => $version->source_note,
                'sample_phrases' => KnowledgeAdminSupport::phrasesToLines($version->getAttribute('sample_phrases')),
                'effective_date' => $version->effective_date?->toDateString(),
                'review_date' => $version->review_date !== null && $version->review_date->toDateString() >= now(config('ai_automation.timezone'))->toDateString() ? $version->review_date->toDateString() : null,
                'change_summary' => 'Went back to the version saved '.$version->created_at?->timezone(HubTimezone::NAME)->format('M j, Y'),
            ], $user);
            if ($goLive) {
                $library->publish($draft, $user);
            }
        } catch (InvalidArgumentException $e) {
            Notification::make()->danger()->title('Not restored')->body($e->getMessage())->send();

            return;
        }

        Notification::make()->success()
            ->title($goLive ? 'Restored and live. Automated answers use it now.' : 'Restored as a draft. Look it over, then press Publish to make it live.')
            ->send();
        $this->refreshState();
    }

    private function article(): AiKnowledgeArticle
    {
        $record = $this->getRecord();
        if (! $record instanceof AiKnowledgeArticle) {
            throw new InvalidArgumentException('Knowledge & Instructions only edits articles.');
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
            'sample_phrases' => KnowledgeAdminSupport::phrasesToLines($working->getAttribute('sample_phrases')),
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
            Notification::make()->title('Nothing new to save')->body('The wording matches what is live. Change something to start a new draft.')->send();
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
        return 'Draft saved. It is not used in answers until you publish it.';
    }

    /** Saves the form as a draft first, then makes it live. */
    private function publishNow(): void
    {
        $user = auth()->user();
        abort_unless($user instanceof User && AiAutomationAccess::allowsCurrentUser(), 403);

        try {
            $version = $this->persist($this->form->getState());
            if ($version === null) {
                Notification::make()->warning()->title('Nothing new to publish')->body('The wording matches what is live.')->send();

                return;
            }
            app(KnowledgeLibrary::class)->publish($version, $user);
            Notification::make()->success()->title('Published. Automated answers use this wording now.')->send();
        } catch (InvalidArgumentException $e) {
            Notification::make()->danger()->title('Not published')->body($e->getMessage())->send();

            return;
        }

        $this->refreshState();
    }

    /**
     * Writes the form into the open draft, or starts a new one. Returns null when there is no open draft and
     * the text is unchanged from the live version (only the matching words and the two toggles are applied then).
     *
     * @param  array<string, mixed>  $data
     */
    private function persist(array $data): ?Version
    {
        $user = auth()->user();
        abort_unless($user instanceof User && AiAutomationAccess::allowsCurrentUser(), 403);

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
            'sample_phrases' => KnowledgeAdminSupport::phrasesToLines($data['sample_phrases'] ?? ''),
            'effective_date' => (string) ($data['effective_date'] ?? ''),
            'review_date' => (string) ($data['review_date'] ?? ''),
        ];
        $current = [
            'title' => trim((string) $base->title),
            'body' => trim((string) $base->body),
            'source_note' => trim((string) $base->source_note),
            'sample_phrases' => KnowledgeAdminSupport::phrasesToLines($base->getAttribute('sample_phrases')),
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
