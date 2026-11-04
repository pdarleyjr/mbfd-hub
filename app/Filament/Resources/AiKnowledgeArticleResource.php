<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Concerns\EnterpriseTable;
use App\Filament\Concerns\HasExportActions;
use App\Filament\Resources\AiKnowledgeArticleResource\Pages;
use App\Models\AiAutomation\AiKnowledgeArticle;
use App\Models\AiAutomation\AiKnowledgeArticleVersion as Version;
use App\Services\AiAutomation\Admin\AdminLabels;
use App\Services\AiAutomation\Admin\KnowledgeAdminSupport;
use App\Services\AiAutomation\Admin\KnowledgeMatchExplainer;
use App\Services\AiAutomation\AiAutomationAccess;
use App\Services\AiAutomation\Knowledge\KnowledgeDocumentImporter;
use App\Services\AiAutomation\Knowledge\KnowledgeLibrary;
use App\Support\HubTimezone;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * Knowledge & Instructions: the approved guidance the automated answers may draw on. Written in plain
 * sentences, previewed, published and restored from earlier versions. The address, the matching words and the
 * Markdown behind the scenes are handled for the administrator. There are no model controls here by design.
 */
final class AiKnowledgeArticleResource extends Resource
{
    use \App\Filament\Concerns\RegistersSupportAutomationNavigation;
    use EnterpriseTable;
    use HasExportActions;

    protected static ?string $model = AiKnowledgeArticle::class;

    // No $navigationIcon: the Support Services Automation group carries the icon.
    protected static ?string $navigationGroup = AdminLabels::NAVIGATION_GROUP;

    protected static ?string $navigationLabel = 'Knowledge & Instructions';

    protected static ?string $modelLabel = 'article';

    protected static ?string $pluralModelLabel = 'Knowledge & Instructions';

    protected static ?string $slug = 'ai-knowledge';

    protected static ?int $navigationSort = 3;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Where this stands')
                ->visibleOn('edit')
                ->schema([
                    Forms\Components\Placeholder::make('status_panel')
                        ->hiddenLabel()
                        ->content(fn (?AiKnowledgeArticle $record) => $record === null ? '' : view('filament.admin.ai-automation.article-status', [
                            'article' => $record,
                            'live' => $record->published_version_id === null ? null : Version::query()->find($record->published_version_id),
                            'draft' => app(KnowledgeLibrary::class)->openDraft($record),
                            'timezone' => HubTimezone::NAME,
                        ])),
                ]),
            Forms\Components\Section::make('What to write')
                ->description('Write it the way you would explain it to a firefighter. Automated answers only use it after you press Publish.')
                ->schema([
                    Forms\Components\TextInput::make('title')
                        ->label('Title')
                        ->helperText('A short name for the subject, for example "Who to call about a missing battery".')
                        ->required()
                        ->maxLength(200)
                        ->columnSpanFull(),
                    Forms\Components\FileUpload::make('body_file')
                        ->label('Start from a file (optional)')
                        ->helperText('Choose a Word, PDF or text file up to 5 MB. Only the words are used, and you can change them below. The file itself is not kept.')
                        ->storeFiles(false)
                        ->dehydrated(false)
                        ->maxSize(5120)
                        ->extraInputAttributes(['accept' => '.docx,.pdf,.txt,.md'])
                        ->afterStateUpdated(fn (mixed $state, Set $set, Get $get) => self::fillFromFile($state, $set, $get))
                        ->columnSpanFull(),
                    Forms\Components\Textarea::make('body')
                        ->label('What should this say?')
                        ->helperText('Plain sentences. Leave a blank line between paragraphs. Start a line with a dash and a space to make a list.')
                        ->required()
                        ->rows(14)
                        ->autosize()
                        ->columnSpanFull(),
                    Forms\Components\Textarea::make('sample_phrases')
                        ->label('Example messages this should answer (optional)')
                        ->helperText('One example per line, written the way a member might ask or report it. This helps the right instructions get picked.')
                        ->rows(3)
                        ->maxLength(2000)
                        ->columnSpanFull(),
                    Forms\Components\Toggle::make('member_safe')
                        ->label('OK to share with members')
                        ->helperText('Turn this off to keep it out of automated answers without deleting it.')
                        ->default(true),
                    Forms\Components\Toggle::make('always_include')
                        ->label('Use this in every automated answer')
                        ->helperText('For short standing rules, such as who to contact on weekends. Leave off for everything else.')
                        ->default(false),
                ])->columns(2),
            Forms\Components\Section::make('More options')
                ->description('You can leave all of this alone.')
                ->collapsed()
                ->schema([
                    Forms\Components\TextInput::make('change_summary')
                        ->label('What did you change?')
                        ->helperText('One line saved with the past versions so others can see why.')
                        ->maxLength(255)
                        ->columnSpanFull(),
                    Forms\Components\TagsInput::make('topic_tags')
                        ->label('Words people use when they ask about this')
                        ->helperText('Press Enter after each one. We add some from the title automatically.')
                        ->columnSpanFull(),
                    Forms\Components\Textarea::make('source_note')
                        ->label('Where this came from (only staff see this)')
                        ->helperText('For example the memo, policy or decision it comes from.')
                        ->rows(3)
                        ->maxLength(5000)
                        ->columnSpanFull(),
                    Forms\Components\DatePicker::make('effective_date')
                        ->label('Start using on')
                        ->helperText('Leave empty to use it as soon as it is published.')
                        ->native(false),
                    Forms\Components\DatePicker::make('review_date')
                        ->label('Check this again by')
                        ->helperText('After this date it stops being used until someone checks it and publishes again.')
                        ->native(false)
                        ->afterOrEqual('today'),
                ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        $table = self::applyEnterpriseDefaults($table)
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['publishedVersion', 'latestVersion']))
            ->columns([
                Tables\Columns\TextColumn::make('title')->label('Title')->searchable()->sortable()->wrap()
                    ->description(fn (AiKnowledgeArticle $record): ?string => self::excerpt($record)),
                Tables\Columns\TextColumn::make('topics')->label('Topic')->badge()->color('gray')->visibleFrom('md')
                    ->state(fn (AiKnowledgeArticle $record): array => KnowledgeAdminSupport::topicsFor($record)),
                Tables\Columns\TextColumn::make('status')->label('Status')->badge()
                    ->state(fn (AiKnowledgeArticle $record): string => KnowledgeAdminSupport::statusLabel($record))
                    ->color(fn (AiKnowledgeArticle $record): string => KnowledgeAdminSupport::statusColor($record))
                    ->description(fn (AiKnowledgeArticle $record): ?string => self::isStale($record) ? 'Needs a check: not being used' : null),
                Tables\Columns\IconColumn::make('member_safe')->label('OK for members')->boolean()->visibleFrom('md'),
                Tables\Columns\IconColumn::make('always_include')->label('Used every time')->boolean()->visibleFrom('md'),
                Tables\Columns\TextColumn::make('updated_at')->label('Last changed')->dateTime('M j, Y g:i A', HubTimezone::NAME)->sortable()->visibleFrom('md'),
                Tables\Columns\TextColumn::make('search_words')->label('Matching words')->toggleable(isToggledHiddenByDefault: true)
                    ->state(fn (AiKnowledgeArticle $record): string => implode(', ', $record->tags()))
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where('topic_tags', 'like', '%'.$search.'%')),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('topic')->label('Topic')->options(KnowledgeAdminSupport::topicOptions())
                    ->query(function (Builder $query, array $data): Builder {
                        $words = KnowledgeAdminSupport::topicWords((string) ($data['value'] ?? ''));
                        if ($words === []) {
                            return $query;
                        }

                        return $query->where(function (Builder $inner) use ($words): void {
                            foreach ($words as $word) {
                                $inner->orWhere('title', 'like', '%'.$word.'%')->orWhere('topic_tags', 'like', '%'.$word.'%');
                            }
                        });
                    }),
                Tables\Filters\SelectFilter::make('status')->label('Status')->options([
                    'published' => 'Live',
                    'draft' => 'Has a draft',
                    'withdrawn' => 'Turned off',
                ])->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                    'published' => $query->whereNotNull('published_version_id'),
                    'draft' => $query->whereHas('versions', fn (Builder $v) => $v->whereIn('status', Version::OPEN_STATUSES)),
                    'withdrawn' => $query->whereNull('published_version_id')->whereHas('versions', fn (Builder $v) => $v->where('status', Version::STATUS_WITHDRAWN)),
                    default => $query,
                }),
            ])
            ->defaultSort('updated_at', 'desc')
            ->recordUrl(fn (AiKnowledgeArticle $record): string => self::getUrl('edit', ['record' => $record]))
            ->actions([
                Tables\Actions\EditAction::make()->label('Edit')->icon('heroicon-o-pencil-square'),
                Tables\Actions\ActionGroup::make([
                    self::previewAction(Tables\Actions\Action::make('preview')),
                    self::historyAction(Tables\Actions\Action::make('history')),
                    self::withdrawAction(Tables\Actions\Action::make('withdraw')),
                ])->label('More')->icon('heroicon-m-ellipsis-horizontal')->button()->color('gray'),
            ])
            ->bulkActions([])
            ->emptyStateHeading('Nothing written yet')
            ->emptyStateDescription('Add an article with the New article button. Automated answers use only what you publish.');

        return self::withExportActions($table);
    }

    /**
     * Fills the title and text from an uploaded file. The file is read once and then removed from temporary storage.
     */
    public static function fillFromFile(mixed $state, Set $set, Get $get): void
    {
        $file = is_array($state) ? (reset($state) ?: null) : $state;
        if (! $file instanceof UploadedFile) {
            return;
        }

        try {
            $result = app(KnowledgeDocumentImporter::class)->import($file);
            $set('body', $result['body']);
            if ($result['title'] !== null && trim((string) $get('title')) === '') {
                $set('title', $result['title']);
            }
            Notification::make()->success()->title('File read. Check the text below and change anything you like.')
                ->body($result['truncated'] ? 'The file was long, so only the first '.number_format(KnowledgeDocumentImporter::MAX_CHARACTERS).' characters were used.' : null)
                ->send();
        } catch (InvalidArgumentException $e) {
            Notification::make()->danger()->title('That file could not be used')->body($e->getMessage())->send();
        } finally {
            $set('body_file', null);
            if ($file instanceof TemporaryUploadedFile) {
                $file->delete();
            }
        }
    }

    /** Preview modal: how the article reads, from the working version. */
    public static function previewAction(\Filament\Actions\MountableAction $action): \Filament\Actions\MountableAction
    {
        return $action
            ->label('Preview')
            ->icon('heroicon-o-eye')
            ->color('gray')
            ->modalHeading(fn (AiKnowledgeArticle $record): string => $record->title)
            ->modalContent(function (AiKnowledgeArticle $record) {
                $working = app(KnowledgeLibrary::class)->workingVersion($record);

                return view('filament.admin.ai-automation.markdown-preview', [
                    'title' => $working !== null ? $working->title : $record->title,
                    'html' => self::markdownHtml($working !== null ? (string) $working->body : ''),
                    'checks' => $working !== null ? app(KnowledgeMatchExplainer::class)->checkVersion($working) : [],
                ]);
            })
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close')
            ->modalWidth('3xl')
            ->visible(fn (): bool => AiAutomationAccess::allowsCurrentUser());
    }

    /** Past versions modal; used by the table and the edit page. */
    /**
     * @template T of \Filament\Actions\MountableAction
     *
     * @param  T  $action
     * @return T
     */
    public static function historyAction(\Filament\Actions\MountableAction $action): \Filament\Actions\MountableAction
    {
        return $action
            ->label('Past versions')
            ->icon('heroicon-o-clock')
            ->color('gray')
            ->modalHeading(fn (AiKnowledgeArticle $record): string => 'Past versions: '.$record->title)
            ->modalContent(fn (AiKnowledgeArticle $record) => view('filament.admin.ai-automation.version-history', [
                'versions' => $record->versions()->get(),
                'timezone' => HubTimezone::NAME,
            ]))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close')
            ->modalWidth('4xl')
            ->visible(fn (): bool => AiAutomationAccess::allowsCurrentUser());
    }

    /** Stop using the live version, with confirmation; used by the table and the edit page. */
    /**
     * @template T of \Filament\Actions\MountableAction
     *
     * @param  T  $action
     * @return T
     */
    public static function withdrawAction(\Filament\Actions\MountableAction $action): \Filament\Actions\MountableAction
    {
        return $action
            ->label('Stop using this')
            ->icon('heroicon-o-no-symbol')
            ->color('danger')
            ->visible(fn (AiKnowledgeArticle $record): bool => $record->published_version_id !== null && AiAutomationAccess::allowsCurrentUser())
            ->requiresConfirmation()
            ->modalHeading(fn (AiKnowledgeArticle $record): string => 'Stop using "'.$record->title.'"?')
            ->modalDescription('Automated answers stop using it right away. Nothing is deleted, and you can restore an earlier version later.')
            ->modalSubmitActionLabel('Stop using it now')
            ->action(function (AiKnowledgeArticle $record): void {
                $user = auth()->user();
                abort_unless($user instanceof \App\Models\User && AiAutomationAccess::allowsCurrentUser(), 403);
                $done = app(KnowledgeLibrary::class)->withdraw($record, $user);
                Notification::make()->{$done ? 'success' : 'warning'}()
                    ->title($done ? 'Stopped. Automated answers no longer use this.' : 'This was not live.')
                    ->send();
            });
    }

    public static function markdownHtml(string $markdown): string
    {
        return Str::markdown($markdown, ['html_input' => 'strip', 'allow_unsafe_links' => false]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAiKnowledgeArticles::route('/'),
            'create' => Pages\CreateAiKnowledgeArticle::route('/create'),
            'edit' => Pages\EditAiKnowledgeArticle::route('/{record}/edit'),
        ];
    }

    public static function canViewAny(): bool
    {
        return AiAutomationAccess::allowsCurrentUser();
    }

    public static function canView(Model $record): bool
    {
        return AiAutomationAccess::allowsCurrentUser();
    }

    public static function canCreate(): bool
    {
        return AiAutomationAccess::allowsCurrentUser();
    }

    public static function canEdit(Model $record): bool
    {
        return AiAutomationAccess::allowsCurrentUser();
    }

    /** Articles are turned off, never deleted: the history stays. */
    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    private static function excerpt(AiKnowledgeArticle $record): ?string
    {
        $body = (string) data_get($record, 'latestVersion.body', '');
        if ($body === '') {
            return null;
        }
        $plain = trim((string) preg_replace('/\s+/u', ' ', strip_tags(self::markdownHtml($body))));

        return Str::limit($plain, 110);
    }

    private static function isStale(AiKnowledgeArticle $record): bool
    {
        $live = $record->publishedVersion;
        if ($live === null || $live->review_date === null) {
            return false;
        }

        return $live->review_date->toDateString() < \Carbon\CarbonImmutable::now((string) config('ai_automation.timezone', 'America/New_York'))->toDateString();
    }
}
