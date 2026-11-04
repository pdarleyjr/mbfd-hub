<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Concerns\EnterpriseTable;
use App\Filament\Concerns\HasExportActions;
use App\Filament\Resources\SupportEquipmentRuleResource\Pages;
use App\Models\SupportTriage\SupportEquipmentRule;
use App\Models\User;
use App\Services\AiAutomation\Admin\AdminLabels;
use App\Services\AiAutomation\Admin\EquipmentRulePreview;
use App\Services\AiAutomation\AiAutomationAccess;
use App\Services\SupportTriage\EquipmentRuleService;
use App\Support\HubTimezone;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;

/**
 * Equipment Priorities: what is Low, Medium or High, who handles it, and what a member is told. Every change goes
 * through EquipmentRuleService, which keeps the history and the catalog version. Nothing is ever deleted: a rule
 * is stopped (turned off) and can be restored to any earlier version.
 */
final class SupportEquipmentRuleResource extends Resource
{
    use \App\Filament\Concerns\RegistersSupportAutomationNavigation;
    use EnterpriseTable;
    use HasExportActions;

    protected static ?string $model = SupportEquipmentRule::class;

    // No $navigationIcon: the Support Services Automation group carries the icon.
    protected static ?string $navigationGroup = AdminLabels::NAVIGATION_GROUP;

    protected static ?string $navigationLabel = 'Equipment Priorities';

    protected static ?string $modelLabel = 'equipment item';

    protected static ?string $pluralModelLabel = 'Equipment Priorities';

    protected static ?string $slug = 'support-equipment-priorities';

    protected static ?int $navigationSort = 2;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('The equipment')
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label('Equipment name')
                        ->helperText('What your checklists call it, for example "Thermal Imaging Camera".')
                        ->required()
                        ->maxLength(120)
                        ->live(onBlur: true),
                    Forms\Components\Select::make('category')
                        ->label('What kind of equipment is it?')
                        ->options(AdminLabels::categories())
                        ->required()
                        ->searchable()
                        ->live(),
                    Forms\Components\TagsInput::make('aliases')
                        ->label('Other names people use')
                        ->helperText('Press Enter after each name. End a name with * to match any ending, for example batter* matches battery and batteries.')
                        ->columnSpanFull(),
                ])->columns(2),
            Forms\Components\Section::make('How important is it?')
                ->schema([
                    Forms\Components\Radio::make('level')
                        ->label('Priority')
                        ->options(AdminLabels::levelChoices())
                        ->descriptions(AdminLabels::levelDescriptions())
                        ->required()
                        ->default('medium')
                        ->live(),
                    Forms\Components\Group::make([
                        Forms\Components\Select::make('handler')
                            ->label('Who handles it?')
                            ->options(AdminLabels::handlers())
                            ->default('support_services')
                            ->required()
                            ->live(),
                        Forms\Components\Select::make('contact_300')
                            ->label('When to contact 300')
                            ->options(AdminLabels::contact300Options())
                            ->default('urgent')
                            ->required()
                            ->live(),
                        Forms\Components\Toggle::make('is_ppe')
                            ->label('Is this firefighting protective gear?')
                            ->helperText('Helmets, bunker gear, masks and other personal protective equipment.')
                            ->live(),
                        Forms\Components\Toggle::make('is_expensive')
                            ->label('Is this expensive?')
                            ->helperText('Costly to replace, so it is tracked more carefully.')
                            ->live(),
                        Forms\Components\Toggle::make('is_critical')
                            ->label('Is it critical for readiness?')
                            ->helperText('A truck may not be ready to respond without it.')
                            ->live(),
                    ]),
                ])->columns(2),
            Forms\Components\Section::make('What should the reply say?')
                ->schema([
                    Forms\Components\Textarea::make('member_response')
                        ->label('Reply to the member')
                        ->helperText('Optional. Leave this blank and the standard message is used. Write {item} where the equipment name should appear. No web links, phone numbers or email addresses.')
                        ->rows(3)
                        ->maxLength(500)
                        ->live(onBlur: true)
                        ->columnSpanFull(),
                ]),
            Forms\Components\Section::make('Preview')
                ->description('What happens when this item is reported missing. Nothing is sent.')
                ->schema([
                    Forms\Components\Placeholder::make('preview')
                        ->hiddenLabel()
                        ->content(fn (Get $get): HtmlString => self::previewHtml([
                            'name' => $get('name'), 'category' => $get('category'), 'level' => $get('level'),
                            'handler' => $get('handler'), 'contact_300' => $get('contact_300'), 'member_response' => $get('member_response'),
                            'is_ppe' => $get('is_ppe'), 'is_critical' => $get('is_critical'), 'is_expensive' => $get('is_expensive'),
                        ])),
                ]),
            Forms\Components\Section::make('Advanced')
                ->description('Most items never need this.')
                ->collapsed()
                ->schema([
                    Forms\Components\Select::make('apparatus_scope')
                        ->label('Only for one kind of vehicle')
                        ->helperText('Use this when the same item matters more on one vehicle than on the others. A rule for one kind of vehicle is used before the general rule.')
                        ->options(AdminLabels::trucks())
                        ->placeholder('All vehicles')
                        ->native(false),
                    Forms\Components\Toggle::make('active')
                        ->label('Use this rule')
                        ->helperText('Turn off to stop using it. It stays here and can be turned back on.')
                        ->default(true),
                ])->columns(2),
            Forms\Components\Section::make('Earlier versions')
                ->description('To go back to one, use "Restore previous" at the top of the page.')
                ->visibleOn('edit')
                ->collapsed()
                ->schema([
                    Forms\Components\Placeholder::make('history')
                        ->hiddenLabel()
                        ->content(fn (?SupportEquipmentRule $record) => view('filament.admin.ai-automation.rule-revisions', [
                            'revisions' => $record === null ? collect() : $record->revisions()->get(),
                            'timezone' => HubTimezone::NAME,
                        ])),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        $table = self::applyEnterpriseDefaults($table)
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Equipment')->searchable(query: fn (Builder $query, string $search): Builder => $query
                    ->where(fn (Builder $inner) => $inner->where('name', 'like', '%'.$search.'%')->orWhere('aliases', 'like', '%'.$search.'%')))
                    ->sortable()->wrap()
                    ->description(fn (SupportEquipmentRule $record): ?string => self::describe($record)),
                Tables\Columns\TextColumn::make('category')->label('Kind')->formatStateUsing(fn (?string $state): string => AdminLabels::category($state))
                    ->sortable()->visibleFrom('md'),
                Tables\Columns\ViewColumn::make('level')->label('Priority')
                    ->view('filament.admin.ai-automation.level-buttons')
                    ->state(fn (SupportEquipmentRule $record): string => AdminLabels::level($record->level)),
                Tables\Columns\TextColumn::make('handler')->label('Handled by')->formatStateUsing(fn (?string $state): string => AdminLabels::handler($state))
                    ->sortable()->visibleFrom('md'),
                Tables\Columns\TextColumn::make('flags')->label('Notes')->badge()->color('gray')->visibleFrom('lg')
                    ->state(fn (SupportEquipmentRule $record): array => array_values(array_filter([
                        $record->is_ppe ? 'Protective gear' : null,
                        $record->is_expensive ? 'Expensive' : null,
                        $record->is_critical ? 'Critical for readiness' : null,
                        ! $record->active ? 'Not in use' : null,
                    ]))),
                Tables\Columns\TextColumn::make('contact_300')->label('Contact 300')->formatStateUsing(fn (?string $state): string => AdminLabels::contact300($state))
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')->label('Last changed')->dateTime('M j, Y g:i A', HubTimezone::NAME)->sortable()->visibleFrom('lg'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('level')->label('Priority')->options(AdminLabels::levels()),
                Tables\Filters\SelectFilter::make('category')->label('Kind')->options(AdminLabels::categories()),
                Tables\Filters\SelectFilter::make('handler')->label('Handled by')->options(AdminLabels::handlers()),
                Tables\Filters\TernaryFilter::make('active')->label('In use')
                    ->placeholder('All')->trueLabel('In use')->falseLabel('Not in use')->default(true),
            ])
            ->defaultSort('name')
            ->recordUrl(fn (SupportEquipmentRule $record): string => self::getUrl('edit', ['record' => $record]))
            ->actions([Tables\Actions\EditAction::make()->label('Edit')->icon('heroicon-o-pencil-square')])
            ->bulkActions([
                self::bulkLevelAction('bulkLow', 'low'),
                self::bulkLevelAction('bulkMedium', 'medium'),
                self::bulkLevelAction('bulkHigh', 'high'),
            ])
            ->emptyStateHeading('No equipment listed yet')
            ->emptyStateDescription('Add an item, or open "Not classified yet" to sort the checklist items that have no priority.');

        return self::withExportActions($table);
    }

    private static function bulkLevelAction(string $name, string $level): Tables\Actions\BulkAction
    {
        $label = AdminLabels::level($level);

        return Tables\Actions\BulkAction::make($name)
            ->label('Make '.$label)
            ->icon($level === 'high' ? 'heroicon-o-exclamation-triangle' : 'heroicon-o-flag')
            ->color(AdminLabels::levelColor($level))
            ->requiresConfirmation()
            ->modalHeading('Make these '.$label.' priority?')
            ->modalDescription('Everything you ticked is changed to '.$label.'. Each one keeps its history, so you can go back.')
            ->modalSubmitActionLabel('Make '.$label)
            ->deselectRecordsAfterCompletion()
            ->authorize(fn (): bool => AiAutomationAccess::allowsCurrentUser())
            ->action(function (Collection $records) use ($level, $label): void {
                $user = auth()->user();
                abort_unless($user instanceof User && AiAutomationAccess::allowsCurrentUser(), 403);
                try {
                    $changed = app(EquipmentRuleService::class)->bulkSetLevel($records->modelKeys(), $level, $user);
                } catch (ValidationException $e) {
                    Notification::make()->danger()->title('Not changed')->body((string) collect($e->errors())->flatten()->first())->send();

                    return;
                }
                Notification::make()->success()
                    ->title($changed === 0 ? 'Nothing needed changing' : $changed.' '.($changed === 1 ? 'item is' : 'items are').' now '.$label)
                    ->send();
            });
    }

    /** Shown as the second line under the equipment name in the list. */
    private static function describe(SupportEquipmentRule $record): ?string
    {
        $parts = [];
        $aliases = array_values(array_filter((array) $record->aliases, 'is_string'));
        if ($aliases !== []) {
            $parts[] = 'Also called: '.implode(', ', array_slice($aliases, 0, 4)).(count($aliases) > 4 ? ' and '.(count($aliases) - 4).' more' : '');
        }
        if ($record->apparatus_scope !== null && $record->apparatus_scope !== '') {
            $parts[] = 'Only on '.AdminLabels::truck($record->apparatus_scope);
        }

        return $parts === [] ? null : implode(' · ', $parts);
    }

    /** @param array<string, mixed> $state */
    public static function previewHtml(array $state): HtmlString
    {
        $preview = app(EquipmentRulePreview::class)->build($state);
        $color = AdminLabels::levelColor($preview['level']);
        $badge = match ($color) {
            'success' => 'bg-success-100 text-success-800',
            'warning' => 'bg-warning-100 text-warning-800',
            default => 'bg-danger-100 text-danger-800',
        };

        return new HtmlString(
            '<div class="grid gap-3 md:grid-cols-2">'
            .'<div class="rounded-lg border border-gray-200 p-3"><p class="text-xs font-semibold text-gray-500">What the member reads</p>'
            .'<p class="mt-1 text-xs text-gray-500">'.e((string) config('ai_automation.sender_label')).'</p>'
            .'<p class="mt-1 text-sm">'.e($preview['reply']).'</p></div>'
            .'<div class="rounded-lg border border-gray-200 p-3 text-sm"><p class="text-xs font-semibold text-gray-500">What staff see</p>'
            .'<p class="mt-1">Priority: <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-semibold '.$badge.'">'.e($preview['level_label']).'</span></p>'
            .'<p class="mt-1">Handled by: <span class="font-semibold">'.e($preview['handler']).'</span></p>'
            .'<p class="mt-1 text-gray-600">'.e($preview['alerts']).'</p></div>'
            .'</div>'
        );
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSupportEquipmentRules::route('/'),
            'create' => Pages\CreateSupportEquipmentRule::route('/create'),
            'not-classified' => Pages\NotClassifiedYet::route('/not-classified-yet'),
            'edit' => Pages\EditSupportEquipmentRule::route('/{record}/edit'),
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

    /** Rules are turned off, never deleted: the history stays. */
    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }
}
