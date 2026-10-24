<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Concerns\EnterpriseTable;
use App\Filament\Resources\ResourceDocumentResource\Pages;
use App\Models\ResourceDocument;
use App\Services\ResourceLibrary\ResourceLibraryService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

/**
 * Admin view of the Employee > Resources library. Documents are added with `resources:import`; here
 * administrators curate metadata, show or hide a document, and replace its file.
 */
final class ResourceDocumentResource extends Resource
{
    use EnterpriseTable;

    protected static ?string $model = ResourceDocument::class;

    protected static ?string $navigationIcon = 'heroicon-o-book-open';

    protected static ?string $navigationGroup = 'Communications';

    protected static ?string $navigationLabel = 'Resource Library';

    protected static ?string $modelLabel = 'resource document';

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
                        ->helperText('Lower numbers list first inside a category.'),
                    Forms\Components\Textarea::make('description')->maxLength(2000)->rows(3)->columnSpanFull(),
                    Forms\Components\Toggle::make('published')
                        ->label('Visible to members')
                        ->helperText('Turn off to hide the document from Employee > Resources. Nothing is deleted.'),
                ])->columns(2),
            Forms\Components\Section::make('Current file')
                ->schema([
                    Forms\Components\Placeholder::make('current_file')
                        ->hiddenLabel()
                        ->content(fn (?ResourceDocument $record): HtmlString => self::currentFileSummary($record)),
                    Forms\Components\Placeholder::make('history')
                        ->label('History')
                        ->content(fn (?ResourceDocument $record): HtmlString => self::history($record)),
                ])
                ->visibleOn('edit'),
            Forms\Components\Section::make('Replace file')
                ->description('Uploading a different file keeps the title and link, and records the replacement in the history. Files over 50 MB: use the resources:import command.')
                ->schema([
                    Forms\Components\FileUpload::make('replacement_original')
                        ->label('New original')
                        ->storeFiles(false)
                        ->acceptedFileTypes(ResourceLibraryService::allowedMimeTypes())
                        ->maxSize(51200),
                    Forms\Components\FileUpload::make('replacement_preview')
                        ->label('Preview PDF (optional, for Word, Excel and PowerPoint originals)')
                        ->storeFiles(false)
                        ->acceptedFileTypes(['application/pdf'])
                        ->maxSize(51200)
                        ->helperText('Members open this PDF in the browser. Export it from the same file you upload above.'),
                ])
                ->columns(2)
                ->collapsed()
                ->visibleOn('edit'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return self::applyEnterpriseDefaults($table)
            ->columns([
                Tables\Columns\TextColumn::make('title')->searchable()->sortable()->limit(60),
                Tables\Columns\TextColumn::make('category')->badge()->searchable()->sortable(),
                Tables\Columns\TextColumn::make('file_type')
                    ->label('Type')
                    ->state(fn (ResourceDocument $record): string => $record->fileTypeLabel().($record->isPdf() || $record->hasPreview() ? '' : ' (no preview)')),
                Tables\Columns\TextColumn::make('page_count')->label('Pages')->placeholder('Unknown')->sortable(),
                Tables\Columns\TextColumn::make('size')->label('Size')->state(fn (ResourceDocument $record): string => $record->readableSize()),
                Tables\Columns\IconColumn::make('published')->label('Visible')->boolean(),
                Tables\Columns\TextColumn::make('updated_at')->label('Updated')->dateTime(timezone: 'America/New_York')->sortable(),
            ])
            ->defaultSort('category')
            ->filters([
                Tables\Filters\TernaryFilter::make('published')->label('Visible to members'),
                Tables\Filters\SelectFilter::make('category')
                    ->options(fn (): array => ResourceDocument::query()->distinct()->orderBy('category')->pluck('category', 'category')->all()),
            ])
            ->actions([Tables\Actions\EditAction::make()])
            ->bulkActions([]);
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

    private static function currentFileSummary(?ResourceDocument $record): HtmlString
    {
        if ($record === null) {
            return new HtmlString('');
        }
        $preview = match (true) {
            $record->isPdf() => 'The original PDF is what members open.',
            $record->hasPreview() => 'Members open a separate PDF preview.',
            default => 'No preview: members can only download this file.',
        };

        return new HtmlString(
            '<p><strong>'.e($record->original_filename).'</strong></p>'
            .'<p>'.e($record->fileTypeLabel().' · '.$record->readableSize().($record->page_count ? ' · '.$record->page_count.' pages' : '')).'</p>'
            .'<p>'.e($preview).'</p>'
            .'<p>SHA-256 <code>'.e($record->original_sha256).'</code></p>',
        );
    }

    private static function history(?ResourceDocument $record): HtmlString
    {
        if ($record === null) {
            return new HtmlString('');
        }
        $rows = $record->events()->latest('id')->limit(10)->get()->map(function ($event): string {
            $sha = (string) ($event->metadata['sha256'] ?? '');

            return '<li>'.e(ucfirst($event->event).' · '.$event->created_at->timezone('America/New_York')->format('M j, Y g:i A')
                .($sha !== '' && in_array($event->event, ['imported', 'replaced'], true) ? ' · '.substr($sha, 0, 12) : '')).'</li>';
        })->implode('');

        return new HtmlString($rows === '' ? '<p>No history yet.</p>' : '<ul>'.$rows.'</ul>');
    }
}
