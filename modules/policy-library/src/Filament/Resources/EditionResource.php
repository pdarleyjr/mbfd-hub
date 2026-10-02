<?php

declare(strict_types=1);

namespace Mbfd\PolicyLibrary\Filament\Resources;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Tables;
use Filament\Tables\Table;
use Mbfd\PolicyLibrary\Models\DocumentRevision;
use Mbfd\PolicyLibrary\Models\Edition;
use Mbfd\PolicyLibrary\Services\ImportService;

final class EditionResource extends LibraryResource
{
    protected static ?string $model = Edition::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-path';

    protected static ?string $navigationLabel = 'Manual Editions';

    protected static ?int $navigationSort = 3;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('manual_id')->relationship('manual', 'name')->required()->disabledOn('edit')->dehydrated(),
            Forms\Components\TextInput::make('label')->required()->maxLength(255),
            Forms\Components\Placeholder::make('contents')->label('Edition contents')->content(function (?Edition $record): string {
                if (! $record) {
                    return 'Create the edition, then add its sections and documents.';
                }
                $documents = $record->nodes()->where('type', 'document')->with('currentRevision')->get();

                $pages = $documents->sum(function ($node): int {
                    $revision = $node->getRelationValue('currentRevision');

                    return $revision instanceof DocumentRevision ? $revision->page_count : 0;
                });

                return $documents->count().' documents · '.$pages.' pages. Review the navigation and PDF drafts before publishing.';
            })->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            Tables\Columns\TextColumn::make('manual.name'), Tables\Columns\TextColumn::make('label'),
            Tables\Columns\TextColumn::make('state')->badge(), Tables\Columns\TextColumn::make('nodes_count')->counts('nodes')->label('Entries'),
            Tables\Columns\TextColumn::make('published_at')->dateTime(),
        ])->defaultSort('id', 'desc')->actions([
            Tables\Actions\EditAction::make(),
            Tables\Actions\Action::make('publish')->label(fn (Edition $record) => $record->state === 'archived' ? 'Restore edition' : 'Publish edition')->requiresConfirmation()
                ->modalDescription('All documents must pass validation. This switches the complete manual; the current edition remains in history.')
                ->visible(fn (Edition $record) => $record->id !== $record->manual->active_edition_id)
                ->action(function (Edition $record): void {
                    abort_unless(static::canViewAny(), 403);
                    app(ImportService::class)->publish($record, auth('web')->id());
                    Notification::make()->title('Manual edition published.')->success()->send();
                }),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListEditions::route('/'), 'create' => Pages\CreateEdition::route('/create'), 'edit' => Pages\EditEdition::route('/{record}/edit')];
    }
}
