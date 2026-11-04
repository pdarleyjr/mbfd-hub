<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Concerns\EnterpriseTable;
use App\Filament\Concerns\HasExportActions;
use App\Filament\Resources\AiPlaybookResource\Pages;
use App\Models\AiAutomation\AiAutomationPlaybook;
use App\Services\AiAutomation\Admin\AdminLabels;
use App\Services\AiAutomation\Admin\ResponseMessageGroups;
use App\Services\AiAutomation\AiAutomationAccess;
use App\Services\AiAutomation\PlaybookRepository;
use App\Support\HubTimezone;
use Closure;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use InvalidArgumentException;

/**
 * Response Messages: the wording of the automated replies. Administrators change the words only; the set of
 * messages is fixed (no create, no delete). Every save is a new version that can be restored, and applies to the
 * next reply with no deploy. The word lists that decide which message applies sit under Advanced.
 */
final class AiPlaybookResource extends Resource
{
    use \App\Filament\Concerns\RegistersSupportAutomationNavigation;
    use EnterpriseTable;
    use HasExportActions;

    protected static ?string $model = AiAutomationPlaybook::class;

    // No $navigationIcon: the Support Services Automation group carries the icon.
    protected static ?string $navigationGroup = AdminLabels::NAVIGATION_GROUP;

    protected static ?string $navigationLabel = 'Response Messages';

    protected static ?string $modelLabel = 'message';

    protected static ?string $pluralModelLabel = 'Response Messages';

    protected static ?string $slug = 'ai-playbooks';

    protected static ?int $navigationSort = 4;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make(fn (?AiAutomationPlaybook $record): string => $record->label ?? 'Message')
                ->description(fn (?AiAutomationPlaybook $record): ?string => $record === null ? null : self::describe($record))
                ->schema([
                    Forms\Components\Placeholder::make('current')
                        ->label('Last saved')
                        ->content(fn (?AiAutomationPlaybook $record): string => $record === null ? '' : self::currentLine($record)),
                    Forms\Components\Textarea::make('body')
                        ->label(fn (?AiAutomationPlaybook $record): string => $record?->isList() ? 'Words and phrases, one per line' : 'What should this message say?')
                        ->helperText(fn (?AiAutomationPlaybook $record): string => $record?->isList()
                            ? 'Capital letters do not matter. Up to '.PlaybookRepository::LIST_MAX_LINES.' lines, each up to '.PlaybookRepository::LIST_TERM_MAX_CHARS.' characters. An asterisk (*) at the end means "any ending", like burn*.'
                            : 'Up to '.PlaybookRepository::TEMPLATE_MAX_CHARS.' characters. Do not put web links, phone numbers or email addresses in a message.'.(self::usesItemName($record) ? ' Where {item} appears, the name of the item is filled in.' : ''))
                        ->rows(fn (?AiAutomationPlaybook $record): int => $record?->isList() ? 18 : 5)
                        ->required()
                        ->live(onBlur: true)
                        ->rules(fn (?AiAutomationPlaybook $record): array => [self::bodyRule($record)])
                        ->columnSpanFull(),
                ]),
            Forms\Components\Section::make('Preview')
                ->description('How a member would read it. Nothing is sent.')
                ->visible(fn (?AiAutomationPlaybook $record): bool => $record !== null && ! $record->isList())
                ->schema([
                    Forms\Components\Placeholder::make('preview')
                        ->hiddenLabel()
                        ->content(fn (Get $get): HtmlString => new HtmlString(
                            '<div class="rounded-lg border border-gray-200 p-3 dark:border-white/10"><p class="text-xs font-semibold text-gray-500">'
                            .e((string) config('ai_automation.sender_label')).'</p><p class="mt-1 text-sm">'
                            .e(ResponseMessageGroups::preview((string) $get('body'))).'</p></div>'
                        )),
                ]),
            Forms\Components\Section::make('Earlier versions')
                ->description('To go back to one, use "Restore previous" at the top of the page.')
                ->collapsed()
                ->schema([
                    Forms\Components\Placeholder::make('history')
                        ->hiddenLabel()
                        ->content(fn (?AiAutomationPlaybook $record) => view('filament.admin.ai-automation.playbook-revisions', [
                            'revisions' => $record === null ? collect() : $record->revisions()->get(),
                            'timezone' => HubTimezone::NAME,
                        ])),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        $table = self::applyEnterpriseDefaults($table)
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('updatedBy'))
            ->columns([
                Tables\Columns\TextColumn::make('label')->label('Message')->searchable()->sortable()->wrap()
                    ->description(fn (AiAutomationPlaybook $record): ?string => ResponseMessageGroups::plainHelp($record->help)),
                Tables\Columns\TextColumn::make('body')->label('Wording')->wrap()->limit(160)->visibleFrom('md')
                    ->searchable()
                    ->state(fn (AiAutomationPlaybook $record): string => $record->isList() ? count(array_filter(preg_split('/\R/', (string) $record->body) ?: [])).' words and phrases' : ResponseMessageGroups::preview((string) $record->body)),
                Tables\Columns\TextColumn::make('section')->label('Section')->badge()->color('gray')->visibleFrom('md')
                    ->state(fn (AiAutomationPlaybook $record): string => ResponseMessageGroups::sectionLabelOf($record)),
                Tables\Columns\TextColumn::make('updatedBy.name')->label('Changed by')->placeholder('Original wording')->visibleFrom('md'),
                Tables\Columns\TextColumn::make('updated_at')->label('Last changed')->dateTime('M j, Y g:i A', HubTimezone::NAME)->sortable()->visibleFrom('md'),
            ])
            ->groups([
                Group::make('section')->label('Section')
                    ->getTitleFromRecordUsing(fn (AiAutomationPlaybook $record): string => ResponseMessageGroups::sectionLabelOf($record))
                    ->getKeyFromRecordUsing(fn (AiAutomationPlaybook $record): string => ResponseMessageGroups::sectionOf((string) $record->key, (string) $record->kind))
                    ->orderQueryUsing(fn (Builder $query, string $direction): Builder => self::orderBySection($query))
                    ->titlePrefixedWithLabel(false)
                    ->collapsible(),
            ])
            ->defaultGroup('section')
            ->filters([
                Tables\Filters\SelectFilter::make('section')->label('Section')
                    ->options(ResponseMessageGroups::labels() + [ResponseMessageGroups::WORD_LISTS => ResponseMessageGroups::label(ResponseMessageGroups::WORD_LISTS)])
                    ->query(function (Builder $query, array $data): Builder {
                        $section = $data['value'] ?? null;
                        if (! is_string($section) || $section === '') {
                            return $query;
                        }
                        $rows = AiAutomationPlaybook::query()->get(['key', 'kind'])->map(fn (AiAutomationPlaybook $p): array => ['key' => (string) $p->key, 'kind' => (string) $p->kind]);

                        return $query->whereIn('key', ResponseMessageGroups::keysIn($section, $rows));
                    }),
            ])
            ->defaultSort('label')
            ->recordUrl(fn (AiAutomationPlaybook $record): string => self::getUrl('edit', ['record' => $record]))
            ->actions([Tables\Actions\EditAction::make()->label('Edit')->icon('heroicon-o-pencil-square')])
            ->bulkActions([])
            ->emptyStateHeading('No messages yet')
            ->emptyStateDescription('Ask your Hub administrator to run "php artisan ai-automation:seed" to load the standard messages.');

        return self::withExportActions($table);
    }

    /** Orders rows by section (in the order of the screen), then by name. */
    private static function orderBySection(Builder $query): Builder
    {
        $rows = AiAutomationPlaybook::query()->get(['key', 'kind']);
        if ($rows->isEmpty()) {
            return $query;
        }
        $sql = 'CASE "key"';
        $bindings = [];
        foreach ($rows as $row) {
            $sql .= ' WHEN ? THEN ?';
            $bindings[] = (string) $row->key;
            $bindings[] = ResponseMessageGroups::position(ResponseMessageGroups::sectionOf((string) $row->key, (string) $row->kind));
        }
        $sql .= ' ELSE 98 END';

        return $query->orderByRaw($sql, $bindings)->orderBy('label');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAiPlaybooks::route('/'),
            'edit' => Pages\EditAiPlaybook::route('/{record}/edit'),
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

    public static function canEdit(Model $record): bool
    {
        return AiAutomationAccess::allowsCurrentUser();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    private static function describe(AiAutomationPlaybook $record): string
    {
        $help = ResponseMessageGroups::plainHelp($record->help);
        $section = ResponseMessageGroups::sectionLabelOf($record);

        return $section.($help !== null ? '. '.$help : '');
    }

    private static function usesItemName(?AiAutomationPlaybook $record): bool
    {
        return $record !== null && ! $record->isList() && str_contains((string) $record->body, '{item}');
    }

    private static function currentLine(AiAutomationPlaybook $record): string
    {
        $editor = $record->updatedBy;
        $who = $editor !== null ? (string) $editor->name : 'the original wording';

        return $record->updated_at?->timezone(HubTimezone::NAME)->format('M j, Y g:i A').' by '.$who.'.';
    }

    private static function bodyRule(?AiAutomationPlaybook $record): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail) use ($record): void {
            if ($record === null) {
                return;
            }
            try {
                app(PlaybookRepository::class)->validate($record, (string) $value);
            } catch (InvalidArgumentException $e) {
                $fail($e->getMessage());
            }
        };
    }
}
