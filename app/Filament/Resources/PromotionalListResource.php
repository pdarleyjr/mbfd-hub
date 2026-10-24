<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\PromotionalListResource\Pages;
use App\Filament\Resources\PromotionalListResource\RelationManagers\EntriesRelationManager;
use App\Filament\Support\PromotionalListForms;
use App\Models\PromotionalList;
use App\Models\PromotionalListEntry;
use Filament\Forms\Form;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Official promotional eligibility lists (Lieutenant, Captain, Driver Engineer, Division Chief and others).
 * Access is enforced by PromotionalListPolicy and again inside PromotionalListService.
 */
class PromotionalListResource extends Resource
{
    protected static ?string $model = PromotionalList::class;

    protected static ?string $navigationIcon = 'heroicon-o-list-bullet';

    protected static ?string $navigationGroup = 'Personnel';

    protected static ?string $navigationLabel = 'Promotional Lists';

    protected static ?string $modelLabel = 'Promotional list';

    protected static ?string $pluralModelLabel = 'Promotional Lists';

    protected static ?string $slug = 'promotional-lists';

    protected static ?int $navigationSort = 13;

    protected static ?string $recordTitleAttribute = 'title';

    public static function form(Form $form): Form
    {
        return $form->schema(PromotionalListForms::listFields(true))->columns(['default' => 1, 'md' => 2]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Section::make()->columns(['default' => 1, 'md' => 3])->schema([
                TextEntry::make('title')->label('Title')->columnSpan(['md' => 2]),
                TextEntry::make('status')->label('Status')->badge()->state(fn (PromotionalList $record): string => $record->statusLabel())
                    ->color(fn (PromotionalList $record): string => self::statusColor($record)),
                TextEntry::make('list_type')->label('List type')->state(fn (PromotionalList $record): string => $record->typeName()),
                TextEntry::make('effective_date')->label('Effective')->state(fn (PromotionalList $record): string => $record->effective_date->format('M j, Y')),
                TextEntry::make('expires_on')->label('Expires')->placeholder('No expiry date')
                    ->state(fn (PromotionalList $record): ?string => $record->expires_on?->format('M j, Y')),
                TextEntry::make('eligible')->label('Eligible now')->state(fn (PromotionalList $record): string => (string) $record->entries()
                    ->where('status', PromotionalListEntry::STATUS_ELIGIBLE)->count()),
                TextEntry::make('rank_note')->label('Current ranks')->columnSpan(['md' => 2])->state(fn (PromotionalList $record): string => $record->isInEffect()
                    ? 'Current ranks count eligible entries in original-rank order.'
                    : 'This list is '.strtolower($record->statusLabel()).', so no entry holds a current rank.'),
                TextEntry::make('source_reference')->label('Source document')->columnSpanFull(),
                TextEntry::make('notes')->label('Notes')->placeholder('None')->columnSpanFull(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->withCount([
                'entries as eligible_entries_count' => fn (Builder $entries) => $entries->where('status', PromotionalListEntry::STATUS_ELIGIBLE),
            ]))
            ->columns([
                Tables\Columns\TextColumn::make('title')->label('List')->searchable()->sortable()->wrap()
                    ->description(fn (PromotionalList $record): string => $record->typeName()),
                Tables\Columns\TextColumn::make('effective_date')->label('Effective')->sortable()
                    ->state(fn (PromotionalList $record): string => $record->effective_date->format('M j, Y')),
                Tables\Columns\TextColumn::make('expires_on')->label('Expires')->sortable()->placeholder('No expiry date')
                    ->state(fn (PromotionalList $record): ?string => $record->expires_on?->format('M j, Y')),
                Tables\Columns\TextColumn::make('status')->label('Status')->badge()->sortable()
                    ->state(fn (PromotionalList $record): string => $record->statusLabel())
                    ->color(fn (PromotionalList $record): string => self::statusColor($record)),
                Tables\Columns\TextColumn::make('eligible_entries_count')->label('Eligible')->sortable()->alignEnd(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options(PromotionalList::statuses()),
                Tables\Filters\SelectFilter::make('list_type')->label('List type')->options(PromotionalList::types()),
            ])
            ->defaultSort('effective_date', 'desc')
            ->recordUrl(fn (PromotionalList $record): string => static::getUrl('view', ['record' => $record]))
            ->actions([Tables\Actions\ViewAction::make()])
            ->emptyStateHeading('No promotional lists yet')
            ->emptyStateDescription('Create a list to record an official promotional result.');
    }

    public static function getRelations(): array
    {
        return [EntriesRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPromotionalLists::route('/'),
            'create' => Pages\CreatePromotionalList::route('/create'),
            'view' => Pages\ViewPromotionalList::route('/{record}'),
            'edit' => Pages\EditPromotionalList::route('/{record}/edit'),
        ];
    }

    private static function statusColor(PromotionalList $list): string
    {
        return match ($list->effectiveStatus()) {
            PromotionalList::STATUS_ACTIVE => 'success',
            PromotionalList::STATUS_EXPIRED => 'danger',
            PromotionalList::STATUS_SUPERSEDED => 'warning',
            default => 'gray',
        };
    }
}
