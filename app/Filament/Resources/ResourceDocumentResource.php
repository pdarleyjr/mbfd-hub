<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Concerns\EnterpriseTable;
use App\Filament\Concerns\HasExportActions;
use App\Filament\Resources\ResourceDocumentResource\Actions\AddResourceDocumentAction;
use App\Filament\Resources\ResourceDocumentResource\Pages;
use App\Filament\Resources\ResourceDocumentResource\Support\DocumentPanels;
use App\Models\ResourceDocument;
use App\Services\ResourceLibrary\ResourceLibraryService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * Admin view of the Employee > Resources library. Administrators add documents from the list page, curate
 * wording and order, show or hide a document, and replace its file. Nothing here deletes a document or a file.
 */
final class ResourceDocumentResource extends Resource
{
    use EnterpriseTable;
    use HasExportActions;

    protected static ?string $model = ResourceDocument::class;

    protected static ?string $navigationIcon = 'heroicon-o-book-open';

    protected static ?string $navigationGroup = 'Communications';

    protected static ?string $navigationLabel = 'Resource Library';

    protected static ?string $modelLabel = 'resource document';

    protected static ?string $pluralModelLabel = 'Resource Library';

    protected static ?int $navigationSort = 20;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Document')
                ->schema([
                    Forms\Components\TextInput::make('title')->required()->maxLength(200)->columnSpanFull(),
                    Forms\Components\TextInput::make('category')
                        ->required()
                        ->maxLength(120)
                        ->datalist(fn (): array => ResourceDocument::query()->distinct()->orderBy('category')->pluck('category')->all())
                        ->helperText('Members see documents grouped by this name.'),
                    Forms\Components\TextInput::make('sort')
                        ->numeric()
                        ->integer()
                        ->minValue(0)
                        ->required()
                        ->default(0)
                        ->helperText('Lower numbers list first inside a category. You can also drag rows in the list.'),
                    Forms\Components\Textarea::make('description')->maxLength(2000)->rows(3)->columnSpanFull(),
                    Forms\Components\Toggle::make('published')
                        ->label('Visible to members')
                        ->helperText('Turn off to hide the document from Employee > Resources. Nothing is deleted.'),
                ])->columns(2),
            Forms\Components\Section::make('Current file')
                ->schema([
                    Forms\Components\Placeholder::make('current_file')
                        ->hiddenLabel()
                        ->content(fn (?ResourceDocument $record) => DocumentPanels::currentFile($record)),
                ])
                ->visibleOn('edit'),
            Forms\Components\Section::make('Replace file')
                ->description('Upload a different file to keep the title and link. The old file is kept in the version history below. Up to '.ResourceLibraryService::maxMegabytes().' MB.')
                ->schema([
                    Forms\Components\FileUpload::make('replacement_original')
                        ->label('New original')
                        ->storeFiles(false)
                        ->rules([AddResourceDocumentAction::extensionRule(ResourceLibraryService::allowedExtensions(), 'Choose a PDF, Word, Excel or PowerPoint file.')])
                        ->maxSize(intdiv(ResourceLibraryService::MAX_BYTES, 1024)),
                    Forms\Components\FileUpload::make('replacement_preview')
                        ->label('PDF copy for in-browser preview (optional, for Word, Excel and PowerPoint originals)')
                        ->storeFiles(false)
                        ->rules([AddResourceDocumentAction::extensionRule(['pdf'], 'The preview must be a PDF.')])
                        ->maxSize(intdiv(ResourceLibraryService::MAX_BYTES, 1024))
                        ->helperText('Members open this PDF in the browser. Export it from the same file you upload above.'),
                ])
                ->columns(2)
                ->collapsed()
                ->visibleOn('edit'),
            Forms\Components\Section::make('Earlier versions')
                ->schema([
                    Forms\Components\Placeholder::make('versions')
                        ->hiddenLabel()
                        ->content(fn (?ResourceDocument $record) => DocumentPanels::versions($record)),
                ])
                ->visibleOn('edit'),
            Forms\Components\Section::make('History')
                ->schema([
                    Forms\Components\Placeholder::make('history')
                        ->hiddenLabel()
                        ->content(fn (?ResourceDocument $record) => DocumentPanels::history($record)),
                ])
                ->collapsed()
                ->visibleOn('edit'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return self::withExportActions(self::applyEnterpriseDefaults($table)
            ->columns([
                Tables\Columns\TextColumn::make('title')->searchable()->sortable()->limit(60),
                Tables\Columns\TextColumn::make('category')->badge()->searchable()->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->state(fn (ResourceDocument $record): string => $record->published ? 'Visible' : 'Hidden')
                    ->color(fn (string $state): string => $state === 'Visible' ? 'success' : 'gray')
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderBy('published', $direction)),
                Tables\Columns\TextColumn::make('file_type')
                    ->label('Type')
                    ->state(fn (ResourceDocument $record): string => $record->fileTypeLabel())
                    ->description(fn (ResourceDocument $record): ?string => $record->hasPreview() ? null : 'Download only - add a PDF copy to allow preview'),
                Tables\Columns\TextColumn::make('page_count')->label('Pages')->placeholder('Unknown')->sortable(),
                Tables\Columns\TextColumn::make('size')->label('Size')->state(fn (ResourceDocument $record): string => $record->readableSize()),
                Tables\Columns\TextColumn::make('updated_at')->label('Updated')->dateTime(timezone: 'America/New_York')->sortable(),
            ])
            ->defaultSort(fn (Builder $query): Builder => $query->orderBy('category')->orderBy('sort')->orderBy('title'))
            ->reorderable('sort')
            ->authorizeReorder(fn (): bool => Gate::allows('update', ResourceDocument::class))
            ->filters([
                Tables\Filters\TernaryFilter::make('published')->label('Visible to members'),
                Tables\Filters\SelectFilter::make('category')
                    ->options(fn (): array => ResourceDocument::query()->distinct()->orderBy('category')->pluck('category', 'category')->all()),
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\EditAction::make(),
                    self::visibilityAction(),
                ])->label('Actions')->icon('heroicon-m-ellipsis-horizontal')->button()->color('gray'),
            ])
            ->bulkActions([])
            ->emptyStateHeading('No documents yet')
            ->emptyStateDescription(fn (): string => Gate::allows('create', ResourceDocument::class)
                ? 'Choose Add document at the top of this page, pick a PDF or Office file, give it a title and a category, and members see it in Employee > Resources.'
                : 'A resource library manager adds documents here. Members see them in Employee > Resources.')
            ->emptyStateIcon('heroicon-o-book-open'));
    }

    /** Hide or show. This is the only way to take a document off the member page; nothing is deleted. */
    private static function visibilityAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('toggleVisibility')
            ->label(fn (ResourceDocument $record): string => $record->published ? 'Hide from members' : 'Show to members')
            ->icon(fn (ResourceDocument $record): string => $record->published ? 'heroicon-o-eye-slash' : 'heroicon-o-eye')
            ->color('gray')
            ->visible(fn (): bool => Gate::allows('update', ResourceDocument::class))
            ->requiresConfirmation()
            ->modalHeading(fn (ResourceDocument $record): string => $record->published ? 'Hide this document from members?' : 'Show this document to members?')
            ->modalDescription(fn (ResourceDocument $record): string => $record->published
                ? '"'.$record->title.'" disappears from Employee > Resources right away. Nothing is deleted, and you can show it again any time.'
                : '"'.$record->title.'" appears in Employee > Resources right away.')
            ->modalSubmitActionLabel(fn (ResourceDocument $record): string => $record->published ? 'Hide from members' : 'Show to members')
            ->action(function (ResourceDocument $record): void {
                // The button can be hidden; this is the check that actually protects the library.
                abort_unless(Gate::allows('update', $record), 403);

                $record->update(['published' => ! $record->published]);

                Notification::make()->success()
                    ->title($record->published ? 'Now visible to members' : 'Now hidden from members')
                    ->send();
            });
    }

    public static function getEloquentQuery(): Builder
    {
        // The extracted text can be megabytes per document; the admin list never shows it.
        return parent::getEloquentQuery()->select([
            'id', 'uuid', 'slug', 'title', 'category', 'description', 'original_filename', 'original_mime', 'original_size',
            'original_sha256', 'original_path', 'preview_path', 'preview_sha256', 'page_count', 'published', 'sort', 'published_at',
            'created_at', 'updated_at', 'deleted_at',
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListResourceDocuments::route('/'),
            'edit' => Pages\EditResourceDocument::route('/{record}/edit'),
        ];
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('viewAny', ResourceDocument::class) ?? false;
    }

    public static function canView(Model $record): bool
    {
        return auth()->user()?->can('view', $record) ?? false;
    }

    /**
     * There is no Filament create page: documents are added from the "Add document" modal on the list page,
     * which checks the `create` ability itself.
     */
    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return auth()->user()?->can('update', $record) ?? false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }
}
