<?php

declare(strict_types=1);

namespace App\Filament\Resources\ResourceDocumentResource\Actions;

use App\Models\ResourceDocument;
use App\Services\ResourceLibrary\ResourceFileInput;
use App\Services\ResourceLibrary\ResourceImportException;
use App\Services\ResourceLibrary\ResourceLibraryService;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * "Add document" on Admin > Resource Library. Everything goes through ResourceLibraryService::store(), the same
 * writer as the import command and "Replace file", so type, checksum, size and duplicate rules are the same.
 */
final class AddResourceDocumentAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'addDocument';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Add document')
            ->icon('heroicon-o-plus')
            ->modalHeading('Add a document')
            ->modalDescription('Members see it in Employee > Resources as soon as you add it, unless you choose to keep it hidden.')
            ->modalSubmitActionLabel('Add document')
            ->modalWidth('2xl')
            ->visible(fn (): bool => Gate::allows('create', ResourceDocument::class))
            ->form($this->schema())
            ->action(fn (array $data) => $this->addDocument($data));
    }

    /** @return list<mixed> */
    private function schema(): array
    {
        $extensions = implode(', ', array_map(fn (string $e): string => '.'.$e, ResourceLibraryService::allowedExtensions()));

        return [
            FileUpload::make('file')
                ->label('File')
                ->required()
                ->storeFiles(false)
                ->maxSize(intdiv(ResourceLibraryService::MAX_BYTES, 1024))
                ->rules([self::extensionRule(ResourceLibraryService::allowedExtensions(), 'Choose a PDF, Word, Excel or PowerPoint file ('.$extensions.').')])
                ->helperText('PDF, Word, Excel or PowerPoint. Up to '.ResourceLibraryService::maxMegabytes().' MB.')
                ->live()
                ->afterStateUpdated(function (mixed $state, Get $get, Set $set): void {
                    $name = self::uploadedName($state);
                    if ($name !== null && blank($get('title'))) {
                        $set('title', self::titleFromFilename($name));
                    }
                }),
            FileUpload::make('preview')
                ->label('PDF copy for in-browser preview')
                ->storeFiles(false)
                ->maxSize(intdiv(ResourceLibraryService::MAX_BYTES, 1024))
                ->rules([self::extensionRule(['pdf'], 'The preview must be a PDF.')])
                ->helperText('Optional, and only for Word, Excel or PowerPoint files: export a PDF from the same file so members can read it in the browser. Without it members can only download the file. Not used when the file itself is a PDF.'),
            TextInput::make('title')
                ->required()
                ->maxLength(200),
            TextInput::make('category')
                ->required()
                ->maxLength(120)
                ->datalist(fn (): array => ResourceDocument::query()->distinct()->orderBy('category')->pluck('category')->all())
                ->helperText('Pick an existing category or type a new one. Members see documents grouped by it.'),
            Textarea::make('description')
                ->maxLength(2000)
                ->rows(3),
            Toggle::make('published')
                ->label('Show to members now')
                ->helperText('Turn off to add it hidden. You can show it later from the list.')
                ->default(true),
        ];
    }

    /** @param  array<string, mixed>  $data */
    private function addDocument(array $data): void
    {
        // The button can be hidden; this is the check that actually protects the library.
        abort_unless(Gate::allows('create', ResourceDocument::class), 403);

        $file = self::firstUpload($data['file'] ?? null);
        $preview = self::firstUpload($data['preview'] ?? null);
        if ($file === null) {
            $this->fail('No file was added', 'Choose the file to add.');
        }

        $service = app(ResourceLibraryService::class);
        $title = trim((string) ($data['title'] ?? ''));
        $category = trim((string) ($data['category'] ?? ''));
        $description = trim((string) ($data['description'] ?? ''));
        $published = (bool) ($data['published'] ?? true);

        try {
            $result = $service->store(
                [
                    'slug' => $service->uniqueSlug($title),
                    'title' => $title,
                    'category' => $category,
                    'description' => $description === '' ? null : $description,
                    // New documents go to the end of their category.
                    'sort' => ((int) ResourceDocument::query()->where('category', $category)->max('sort')) + 1,
                    'published' => $published,
                ],
                new ResourceFileInput((string) $file->getRealPath(), $file->getClientOriginalName()),
                $preview === null ? null : new ResourceFileInput((string) $preview->getRealPath(), $preview->getClientOriginalName()),
                auth()->id(),
                'admin',
            );
        } catch (ResourceImportException $exception) {
            $this->fail('The document was not added', $exception->getMessage());
        }

        $notification = Notification::make()
            ->title($published ? 'Document added and visible to members' : 'Document added and hidden from members')
            ->body($result->warnings === [] ? null : implode(' ', $result->warnings));
        ($result->warnings === [] ? $notification->success() : $notification->warning())->send();
    }

    private function fail(string $title, string $body): never
    {
        Notification::make()->danger()->title($title)->body($body)->persistent()->send();

        throw new Halt;
    }

    /** @param  list<string>  $extensions */
    public static function extensionRule(array $extensions, string $message): Closure
    {
        // Filament evaluates the outer closure; Laravel calls the inner one as a validation rule.
        return fn (): Closure => function (string $attribute, mixed $value, Closure $fail) use ($extensions, $message): void {
            $name = self::uploadedName($value);
            if ($name !== null && ! in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), $extensions, true)) {
                $fail($message);
            }
        };
    }

    public static function firstUpload(mixed $state): ?TemporaryUploadedFile
    {
        $first = is_array($state) ? Arr::first($state) : $state;

        return $first instanceof TemporaryUploadedFile ? $first : null;
    }

    private static function uploadedName(mixed $state): ?string
    {
        return self::firstUpload($state)?->getClientOriginalName();
    }

    private static function titleFromFilename(string $filename): string
    {
        $base = Str::of(pathinfo($filename, PATHINFO_FILENAME))->replace(['_', '-'], ' ')->squish();

        return Str::limit((string) $base, 200, '');
    }
}
