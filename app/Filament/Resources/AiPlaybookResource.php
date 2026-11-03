<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Concerns\EnterpriseTable;
use App\Filament\Resources\AiPlaybookResource\Pages;
use App\Models\AiAutomation\AiAutomationPlaybook;
use App\Services\AiAutomation\AiAutomationAccess;
use App\Services\AiAutomation\PlaybookRepository;
use App\Support\HubTimezone;
use Closure;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Response Playbooks: the reply wording and the word lists the AI Response engine uses. Administrators change
 * the text only; the set of playbooks is fixed (no create, no delete). Every save is a new version with a
 * revision row, and applies to the next reply with no deploy.
 */
final class AiPlaybookResource extends Resource
{
    use EnterpriseTable;

    protected static ?string $model = AiAutomationPlaybook::class;

    // No $navigationIcon: the AI Response Automation group carries the icon.
    protected static ?string $navigationGroup = 'AI Response Automation';

    protected static ?string $navigationLabel = 'Response Playbooks';

    protected static ?string $modelLabel = 'playbook';

    protected static ?string $pluralModelLabel = 'Response Playbooks';

    protected static ?string $slug = 'ai-playbooks';

    protected static ?int $navigationSort = 3;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make(fn (?AiAutomationPlaybook $record): string => $record->label ?? 'Playbook')
                ->description(fn (?AiAutomationPlaybook $record): ?string => $record?->help)
                ->schema([
                    Forms\Components\Placeholder::make('current')
                        ->label('Current version')
                        ->content(fn (?AiAutomationPlaybook $record): string => $record === null ? '' : self::currentLine($record)),
                    Forms\Components\Textarea::make('body')
                        ->label(fn (?AiAutomationPlaybook $record): string => $record?->isList() ? 'Words and phrases, one per line' : 'Reply text')
                        ->helperText(fn (?AiAutomationPlaybook $record): string => $record?->isList()
                            ? 'Matching ignores capital letters. Up to '.PlaybookRepository::LIST_MAX_LINES.' lines, each up to '.PlaybookRepository::LIST_TERM_MAX_CHARS.' characters.'
                            : 'Up to '.PlaybookRepository::TEMPLATE_MAX_CHARS.' characters. {item} is replaced with the item name. No web links, phone numbers or email addresses.')
                        ->rows(fn (?AiAutomationPlaybook $record): int => $record?->isList() ? 18 : 5)
                        ->required()
                        ->rules(fn (?AiAutomationPlaybook $record): array => [self::bodyRule($record)])
                        ->columnSpanFull(),
                ]),
            Forms\Components\Section::make('Earlier versions')
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
        return self::applyEnterpriseDefaults($table)
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('updatedBy'))
            ->columns([
                Tables\Columns\TextColumn::make('label')->label('Playbook')->searchable()->sortable()->description(fn (AiAutomationPlaybook $record): ?string => $record->help)->wrap(),
                Tables\Columns\TextColumn::make('kind')->label('Type')->badge()
                    ->formatStateUsing(fn (string $state): string => $state === AiAutomationPlaybook::KIND_LIST ? 'Word list' : 'Reply')
                    ->color(fn (string $state): string => $state === AiAutomationPlaybook::KIND_LIST ? 'info' : 'gray')
                    ->sortable(),
                Tables\Columns\TextColumn::make('version')->label('Version')->sortable()->formatStateUsing(fn ($state): string => 'v'.$state),
                Tables\Columns\TextColumn::make('updatedBy.name')->label('Updated by')->placeholder('Repository defaults')->visibleFrom('md'),
                Tables\Columns\TextColumn::make('updated_at')->label('Updated')->dateTime(timezone: HubTimezone::NAME)->sortable()->visibleFrom('md'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('kind')->label('Type')->options([
                    AiAutomationPlaybook::KIND_TEMPLATE => 'Reply',
                    AiAutomationPlaybook::KIND_LIST => 'Word list',
                ]),
            ])
            ->defaultSort('kind')
            ->actions([Tables\Actions\EditAction::make()->label('Edit')])
            ->bulkActions([])
            ->emptyStateHeading('No playbooks yet')
            ->emptyStateDescription('Run "php artisan ai-automation:seed" to load the default replies and word lists.');
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

    private static function currentLine(AiAutomationPlaybook $record): string
    {
        $editor = $record->updatedBy;
        $who = $editor !== null ? (string) $editor->name : 'repository defaults';

        return 'Version '.$record->version.', saved '.$record->updated_at?->timezone(HubTimezone::NAME)->format('M j, Y g:i A').' by '.$who.'.';
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
