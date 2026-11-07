<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Concerns\EnterpriseTable;
use App\Filament\Concerns\HasExportActions;
use App\Filament\Resources\ChecklistTemplateResource\Pages;
use App\Models\DailyCheckoutChecklistTemplateVersion as Version;
use App\Services\ChecklistTemplates\ChecklistTemplateEditor;
use App\Support\Fleet\ChecklistTemplateAccess;
use App\Support\HubTimezone;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Logistics edits the Daily Checkout apparatus checklists here (docs/checklist-template-editor.md section 7).
 * The index lists every template with its source and the publication record; the editor page holds the
 * draft, the publish preview and the history. Every change goes through ChecklistTemplateEditor: the
 * standard create, edit and delete flows are off.
 */
class ChecklistTemplateResource extends Resource
{
    use EnterpriseTable;
    use HasExportActions;

    protected static ?string $model = Version::class;

    protected static ?string $navigationGroup = 'Fleet';

    // Right after Inventory corrections (16).
    protected static ?int $navigationSort = 17;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationLabel = 'Checklist templates';

    protected static ?string $modelLabel = 'Checklist template version';

    protected static ?string $pluralModelLabel = 'Checklist templates';

    protected static ?string $slug = 'checklist-templates';

    public const STATUS_LABELS = [
        'draft' => 'Draft',
        'published' => 'Published',
        'retired' => 'Replaced',
        'discarded' => 'Discarded',
    ];

    public static function canViewAny(): bool
    {
        return ChecklistTemplateAccess::canView(auth()->user());
    }

    public static function canView(Model $record): bool
    {
        return static::canViewAny();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
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

    public static function canManage(): bool
    {
        return ChecklistTemplateAccess::canManage(auth()->user());
    }

    /** The publication record: every draft, publication and replaced version, newest first. */
    public static function table(Table $table): Table
    {
        return static::withExportActions(self::applyEnterpriseDefaults($table)
            ->heading('Publication record')
            ->description('Every draft and published version of every checklist. Open a template above to edit it.')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->where('status', '!=', 'discarded')
                ->with(['publishedBy:id,name,display_name', 'updatedBy:id,name,display_name']))
            ->defaultSort('updated_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('checklist_type')
                    ->label('Checklist')
                    ->formatStateUsing(fn (?string $state): string => self::typeLabel((string) $state))
                    ->description(fn (Version $record): ?string => filled($record->change_summary) ? (string) $record->change_summary : null)
                    ->wrap()
                    ->extraAttributes(['class' => 'hub-phone-summary']),
                Tables\Columns\TextColumn::make('version_number')
                    ->label('Version')
                    ->formatStateUsing(fn ($state): string => $state === null ? 'Draft' : 'v'.$state)
                    ->placeholder('Draft')
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => self::STATUS_LABELS[$state] ?? (string) $state)
                    ->color(fn (?string $state): string => match ($state) {
                        'published' => 'success',
                        'draft' => 'warning',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('published_at')
                    ->label('Published (Eastern)')
                    ->dateTime('M j, Y g:i A', HubTimezone::NAME)
                    ->placeholder('Not published')
                    ->description(fn (Version $record): ?string => self::personName($record->publishedBy))
                    ->sortable()
                    ->visibleFrom('md'),
                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Last change (Eastern)')
                    ->dateTime('M j, Y g:i A', HubTimezone::NAME)
                    ->description(fn (Version $record): ?string => self::personName($record->updatedBy))
                    ->sortable()
                    ->visibleFrom('lg'),
            ])
            ->filters([
                SelectFilter::make('checklist_type')
                    ->label('Checklist')
                    ->options(fn (): array => self::typeOptions()),
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(array_diff_key(self::STATUS_LABELS, ['discarded' => true])),
            ])
            ->actions([
                Tables\Actions\Action::make('open')
                    ->label(fn (): string => self::canManage() ? 'Edit' : 'View')
                    ->icon('heroicon-o-arrow-right')
                    ->url(fn (Version $record): string => static::getUrl('editor', ['type' => $record->checklist_type])),
            ])
            ->recordUrl(fn (Version $record): string => static::getUrl('editor', ['type' => $record->checklist_type]))
            ->bulkActions([]));
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListChecklistTemplates::route('/'),
            'editor' => Pages\EditChecklistTemplate::route('/{type}'),
        ];
    }

    /** @return array<string, string> type => label */
    public static function typeOptions(): array
    {
        $editor = app(ChecklistTemplateEditor::class);

        return collect($editor->types())->mapWithKeys(fn (string $type): array => [$type => $editor->label($type)])->all();
    }

    public static function typeLabel(string $type): string
    {
        try {
            return app(ChecklistTemplateEditor::class)->label($type);
        } catch (Throwable) {
            return $type;
        }
    }

    public static function personName(mixed $user): ?string
    {
        if (! $user instanceof Model) {
            return null;
        }
        $name = trim((string) ($user->getAttribute('display_name') ?: $user->getAttribute('name')));

        return $name !== '' ? $name : null;
    }

    /** Eastern date and time, the way Fleet pages print it. */
    public static function when(mixed $value): ?string
    {
        if (! $value instanceof \DateTimeInterface) {
            return null;
        }

        return \Carbon\CarbonImmutable::instance($value)->setTimezone(HubTimezone::NAME)->format('M j, Y g:i A T');
    }
}
