<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Concerns\EnterpriseTable;
use App\Filament\Resources\AiKnowledgeArticleResource\Pages;
use App\Models\AiAutomation\AiKnowledgeArticle;
use App\Models\AiAutomation\AiKnowledgeArticleVersion as Version;
use App\Services\AiAutomation\AiAutomationAccess;
use App\Services\AiAutomation\Knowledge\KnowledgeLibrary;
use App\Support\HubTimezone;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Knowledge Library: the approved guidance the AI Response engine may draw on for member questions. Articles
 * move draft, in review, published; a published version is read-only and editing starts a new draft.
 * There are no model controls here by design.
 */
final class AiKnowledgeArticleResource extends Resource
{
    use EnterpriseTable;

    protected static ?string $model = AiKnowledgeArticle::class;

    // No $navigationIcon: the AI Response Automation group carries the icon.
    protected static ?string $navigationGroup = 'AI Response Automation';

    protected static ?string $navigationLabel = 'Knowledge Library';

    protected static ?string $modelLabel = 'article';

    protected static ?string $pluralModelLabel = 'Knowledge Library';

    protected static ?string $slug = 'ai-knowledge';

    protected static ?int $navigationSort = 2;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Status')
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
            Forms\Components\Section::make('Article')
                ->schema([
                    Forms\Components\TextInput::make('title')->label('Title')->required()->maxLength(200)->columnSpanFull(),
                    Forms\Components\TextInput::make('slug')
                        ->label('Address (slug)')
                        ->helperText('Lower-case letters, numbers and hyphens. Cannot be changed after the article is created.')
                        ->required(fn (string $operation): bool => $operation === 'create')
                        ->disabledOn('edit')
                        ->dehydrated(fn (string $operation): bool => $operation === 'create')
                        ->maxLength(120)
                        ->regex('/^[a-z0-9]+(?:-[a-z0-9]+)*$/')
                        ->unique('ai_knowledge_articles', 'slug', ignoreRecord: true),
                    Forms\Components\TagsInput::make('topic_tags')
                        ->label('Topic tags')
                        ->helperText('Words members use for this topic. Tags weigh heavily when a question is matched to an article.'),
                    Forms\Components\Toggle::make('member_safe')
                        ->label('Safe to use in replies to members')
                        ->helperText('Turn off to keep this article out of automatic replies. Applies immediately.')
                        ->default(true),
                ])->columns(2),
            Forms\Components\Section::make('Content')
                ->schema([
                    Forms\Components\FileUpload::make('body_file')
                        ->label('Fill the body from a file (optional)')
                        ->helperText('A .md or .txt file up to 200 KB. Its text replaces the body below; the file itself is not kept.')
                        ->storeFiles(false)
                        ->dehydrated(false)
                        ->maxSize(200)
                        ->afterStateUpdated(function (mixed $state, Set $set): void {
                            $file = is_array($state) ? (reset($state) ?: null) : $state;
                            if (! $file instanceof \Illuminate\Http\UploadedFile) {
                                return;
                            }
                            try {
                                $set('body', app(KnowledgeLibrary::class)->bodyFromUpload($file));
                            } catch (InvalidArgumentException $e) {
                                Notification::make()->danger()->title('File not used')->body($e->getMessage())->send();
                            }
                            $set('body_file', null);
                        })
                        ->columnSpanFull(),
                    Forms\Components\MarkdownEditor::make('body')
                        ->label('Guidance text (Markdown)')
                        ->required()
                        ->columnSpanFull(),
                    Forms\Components\TextInput::make('change_summary')
                        ->label('What changed')
                        ->helperText('One line shown in the version history.')
                        ->maxLength(255)
                        ->columnSpanFull(),
                    Forms\Components\Textarea::make('source_note')
                        ->label('Internal source note (never shown to members)')
                        ->helperText('Which procedure, policy or decision this comes from.')
                        ->rows(3)
                        ->maxLength(5000)
                        ->columnSpanFull(),
                    Forms\Components\DatePicker::make('effective_date')
                        ->label('Effective date')
                        ->helperText('Not used in replies before this date. Leave empty for immediately.')
                        ->native(false),
                    Forms\Components\DatePicker::make('review_date')
                        ->label('Review date')
                        ->helperText('After this date the article is not used until it is reviewed and published again.')
                        ->native(false)
                        ->afterOrEqual('today'),
                ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return self::applyEnterpriseDefaults($table)
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['publishedVersion', 'latestVersion']))
            ->columns([
                Tables\Columns\TextColumn::make('title')->searchable()->sortable()->wrap()
                    ->description(fn (AiKnowledgeArticle $record): string => $record->slug),
                Tables\Columns\TextColumn::make('slug')->searchable()->visibleFrom('md')->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('topic_tags')->label('Tags')->badge()->visibleFrom('md')
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where('topic_tags', 'like', '%'.$search.'%')),
                Tables\Columns\TextColumn::make('published_version')->label('Published')
                    ->state(fn (AiKnowledgeArticle $record): string => $record->publishedVersion === null ? 'None' : 'v'.$record->publishedVersion->version),
                Tables\Columns\TextColumn::make('status')->badge()
                    ->state(fn (AiKnowledgeArticle $record): string => self::statusLabel($record->statusKey()))
                    ->color(fn (AiKnowledgeArticle $record): string => match ($record->statusKey()) {
                        'published' => 'success',
                        'in_review' => 'warning',
                        'withdrawn' => 'danger',
                        default => 'gray',
                    })
                    ->description(fn (AiKnowledgeArticle $record): ?string => self::pendingNote($record)),
                Tables\Columns\TextColumn::make('review_date')->label('Review by')->visibleFrom('md')
                    ->state(fn (AiKnowledgeArticle $record) => ($record->publishedVersion ?? $record->latestVersion)?->review_date)
                    ->date('M j, Y')
                    ->placeholder('Not set')
                    ->color(fn (AiKnowledgeArticle $record): ?string => self::isStale($record) ? 'danger' : null)
                    ->description(fn (AiKnowledgeArticle $record): ?string => self::isStale($record) ? 'Overdue: not used in replies' : null),
                Tables\Columns\TextColumn::make('updated_at')->label('Updated')->dateTime(timezone: HubTimezone::NAME)->sortable()->visibleFrom('md'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->label('Status')->options([
                    'published' => 'Published',
                    'draft' => 'Has a draft',
                    'in_review' => 'In review',
                    'withdrawn' => 'Withdrawn',
                ])->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                    'published' => $query->whereNotNull('published_version_id'),
                    'draft' => $query->whereHas('versions', fn (Builder $v) => $v->where('status', Version::STATUS_DRAFT)),
                    'in_review' => $query->whereHas('versions', fn (Builder $v) => $v->where('status', Version::STATUS_IN_REVIEW)),
                    'withdrawn' => $query->whereNull('published_version_id')->whereHas('versions', fn (Builder $v) => $v->where('status', Version::STATUS_WITHDRAWN)),
                    default => $query,
                }),
            ])
            ->defaultSort('updated_at', 'desc')
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\EditAction::make(),
                    self::historyAction(Tables\Actions\Action::make('history')),
                    self::withdrawAction(Tables\Actions\Action::make('withdraw')),
                ])->label('Actions')->icon('heroicon-m-ellipsis-horizontal')->button()->color('gray'),
            ])
            ->bulkActions([])
            ->emptyStateHeading('No articles yet')
            ->emptyStateDescription('Add an article, or run "php artisan ai-automation:seed" to load the starter guide.');
    }

    /** Version history modal; used by the table and the edit page. */
    /**
     * @template T of \Filament\Actions\MountableAction
     *
     * @param  T  $action
     * @return T
     */
    public static function historyAction(\Filament\Actions\MountableAction $action): \Filament\Actions\MountableAction
    {
        return $action
            ->label('Version history')
            ->icon('heroicon-o-clock')
            ->color('gray')
            ->modalHeading(fn (AiKnowledgeArticle $record): string => 'Version history: '.$record->title)
            ->modalContent(fn (AiKnowledgeArticle $record) => view('filament.admin.ai-automation.version-history', [
                'versions' => $record->versions()->get(),
                'timezone' => HubTimezone::NAME,
            ]))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close')
            ->modalWidth('4xl')
            ->visible(fn (): bool => AiAutomationAccess::allowsCurrentUser());
    }

    /** Withdraw with confirmation; used by the table and the edit page. */
    /**
     * @template T of \Filament\Actions\MountableAction
     *
     * @param  T  $action
     * @return T
     */
    public static function withdrawAction(\Filament\Actions\MountableAction $action): \Filament\Actions\MountableAction
    {
        return $action
            ->label('Withdraw')
            ->icon('heroicon-o-no-symbol')
            ->color('danger')
            ->visible(fn (AiKnowledgeArticle $record): bool => $record->published_version_id !== null && AiAutomationAccess::allowsCurrentUser())
            ->requiresConfirmation()
            ->modalHeading(fn (AiKnowledgeArticle $record): string => 'Withdraw "'.$record->title.'"?')
            ->modalDescription('The article stops being used in replies right away. Its history is kept, and you can start a new draft from it later.')
            ->modalSubmitActionLabel('Withdraw now')
            ->action(function (AiKnowledgeArticle $record): void {
                $user = auth()->user();
                abort_unless($user instanceof \App\Models\User && AiAutomationAccess::allowsCurrentUser(), 403);
                $done = app(KnowledgeLibrary::class)->withdraw($record, $user);
                Notification::make()->{$done ? 'success' : 'warning'}()
                    ->title($done ? 'Withdrawn. Replies no longer use this article.' : 'This article was not published.')
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

    /** Articles are withdrawn, never deleted: the history stays. */
    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    private static function statusLabel(string $key): string
    {
        return match ($key) {
            'published' => 'Published',
            'in_review' => 'In review',
            'withdrawn' => 'Withdrawn',
            default => 'Draft',
        };
    }

    private static function pendingNote(AiKnowledgeArticle $record): ?string
    {
        $latest = $record->latestVersion;
        if ($record->published_version_id === null || $latest === null || ! $latest->isOpen()) {
            return null;
        }

        return ($latest->status === Version::STATUS_IN_REVIEW ? 'Version ' : 'Draft version ').$latest->version.($latest->status === Version::STATUS_IN_REVIEW ? ' in review' : ' pending');
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
