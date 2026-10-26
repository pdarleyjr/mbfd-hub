<?php

declare(strict_types=1);

namespace App\Services\ResourceLibrary;

use App\Models\ResourceDocument;
use App\Models\ResourceDocumentVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;
use ZipArchive;

/**
 * Adds a document to the library or replaces its files. The only writer of resource-library/ storage.
 *
 * Idempotent by checksum: offering the bytes a slug already holds changes nothing. A different file for an
 * existing slug is a replacement: the new bytes are staged and proven first at a path no earlier file uses,
 * and the database row, the version record and the history event commit together with the swap. The
 * previous bytes are never overwritten or deleted, so every superseded file stays downloadable by managers.
 * Nothing is ever deleted as a document.
 */
final class ResourceLibraryService
{
    /** Largest file the library accepts, original or preview. */
    public const MAX_BYTES = 52_428_800;

    /** extension => [mime type, leading bytes of a genuine file] */
    private const TYPES = [
        'pdf' => ['application/pdf', '%PDF-'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', "PK\x03\x04"],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', "PK\x03\x04"],
        'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', "PK\x03\x04"],
        'doc' => ['application/msword', "\xD0\xCF\x11\xE0"],
        'xls' => ['application/vnd.ms-excel', "\xD0\xCF\x11\xE0"],
        'ppt' => ['application/vnd.ms-powerpoint', "\xD0\xCF\x11\xE0"],
    ];

    /** OOXML extension => the folder every genuine package of that type contains. */
    private const OOXML_PART_PREFIX = [
        'docx' => 'word/',
        'xlsx' => 'xl/',
        'pptx' => 'ppt/',
    ];

    public function __construct(
        private readonly ResourceLibraryStorage $storage,
        private readonly ResourcePdfInspector $inspector,
    ) {}

    /** @return list<string> */
    public static function allowedExtensions(): array
    {
        return array_keys(self::TYPES);
    }

    /** @return list<string> */
    public static function allowedMimeTypes(): array
    {
        return array_values(array_unique(array_column(self::TYPES, 0)));
    }

    public static function maxMegabytes(): int
    {
        return intdiv(self::MAX_BYTES, 1_048_576);
    }

    /** A URL-safe slug from a title that no document uses, trashed or not (slugs are unique across both). */
    public function uniqueSlug(string $title): string
    {
        $base = trim(mb_substr(Str::slug($title), 0, 100), '-');
        $base = $base === '' ? 'document' : $base;

        $candidate = $base;
        for ($suffix = 2; ResourceDocument::withTrashed()->where('slug', $candidate)->exists(); $suffix++) {
            $candidate = $base.'-'.$suffix;
        }

        return $candidate;
    }

    /**
     * @param  array{slug: string, title: string, category: string, description?: string|null, sort?: int, published?: bool, recipient_employee_id?: int|null}  $attributes
     *
     * @throws ResourceImportException
     */
    public function store(
        array $attributes,
        ResourceFileInput $original,
        ?ResourceFileInput $preview = null,
        ?int $actorId = null,
        string $source = 'import',
        ?int $expectedPages = null,
    ): ResourceImportResult {
        $extension = $this->extensionOf($original->filename);
        $originalSha = $this->verify($original, $extension);
        $previewSha = null;
        if ($preview !== null) {
            if ($extension === 'pdf') {
                throw new ResourceImportException('"'.$original->filename.'" is already a PDF, so it does not take a separate preview.');
            }
            $previewSha = $this->verify($preview, 'pdf');
        }

        $existing = ResourceDocument::withTrashed()->where('slug', $attributes['slug'])->first();
        if ($existing?->trashed()) {
            throw new ResourceImportException('"'.$attributes['slug'].'" belongs to a deleted library document. Restore it in Admin before importing a new file.');
        }
        $recipient = $attributes['recipient_employee_id'] ?? null;
        if ($existing !== null && $existing->recipient_employee_id !== $recipient) {
            throw new ResourceImportException('"'.$attributes['slug'].'" belongs to a different recipient. Nothing was imported.');
        }
        $this->assertChecksumIsFree($originalSha, $existing?->id);

        if ($existing !== null
            && $existing->original_sha256 === $originalSha
            && ($previewSha === null || $previewSha === $existing->preview_sha256)) {
            return new ResourceImportResult(ResourceImportResult::UNCHANGED, $existing);
        }

        $inspection = $this->inspect($original, $extension, $preview);
        if ($recipient !== null && ($extension !== 'pdf' || $inspection['pages'] !== 16)) {
            throw new ResourceImportException('A personal taskbook must be a verified 16-page PDF. Nothing was imported.');
        }
        if ($expectedPages !== null && $inspection['pages'] !== null && $inspection['pages'] !== $expectedPages) {
            throw new ResourceImportException('"'.$attributes['slug'].'" should have '.$expectedPages.' pages but the PDF has '.$inspection['pages'].'. Nothing was imported.');
        }
        $warnings = $inspection['warnings'];

        $input = [
            'original' => $original, 'extension' => $extension, 'originalSha' => $originalSha,
            'preview' => $preview, 'previewSha' => $previewSha,
            'inspection' => $inspection, 'actorId' => $actorId, 'source' => $source,
        ];

        return $existing === null
            ? $this->create($attributes, $input, $warnings)
            : $this->replace($existing, $input, $warnings);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $input
     * @param  list<string>  $warnings
     */
    private function create(array $attributes, array $input, array $warnings): ResourceImportResult
    {
        /** @var ResourceFileInput $original */
        $original = $input['original'];
        /** @var ResourceFileInput|null $preview */
        $preview = $input['preview'];
        $uuid = (string) Str::uuid();
        $originalPath = $this->storage->originalPath($uuid, $input['extension']);
        $previewPath = $preview !== null ? $this->storage->previewPath($uuid) : null;

        try {
            $this->storeProven($originalPath, $original->localPath, $input['originalSha']);
            if ($preview !== null && $previewPath !== null) {
                $this->storeProven($previewPath, $preview->localPath, $input['previewSha']);
            }

            $document = DB::transaction(function () use ($attributes, $input, $uuid, $originalPath, $previewPath, $original): ResourceDocument {
                $document = new ResourceDocument([
                    'slug' => $attributes['slug'],
                    'title' => $attributes['title'],
                    'category' => $attributes['category'],
                    'description' => $attributes['description'] ?? null,
                    'sort' => $attributes['sort'] ?? 0,
                    'recipient_employee_id' => $attributes['recipient_employee_id'] ?? null,
                    'published' => isset($attributes['recipient_employee_id']) ? false : ($attributes['published'] ?? true),
                    'taskbook_published' => isset($attributes['recipient_employee_id']) && ($attributes['published'] ?? true),
                    'original_filename' => $this->safeFilename($original->filename),
                    'original_mime' => self::TYPES[$input['extension']][0],
                    'original_size' => (int) filesize($original->localPath),
                    'original_sha256' => $input['originalSha'],
                    'original_path' => $originalPath,
                    'preview_path' => $previewPath,
                    'preview_sha256' => $input['previewSha'],
                    'page_count' => $input['inspection']['pages'],
                    'search_text' => $input['inspection']['text'],
                ]);
                $document->uuid = $uuid;
                $document->save();
                $document->events()->create([
                    'event' => 'imported',
                    'actor_id' => $input['actorId'],
                    'metadata' => $this->metadata($input, $document),
                ]);

                return $document;
            });
        } catch (Throwable $exception) {
            $this->storage->deleteDirectory($uuid);

            throw $exception instanceof ResourceImportException
                ? $exception
                : new ResourceImportException('Could not import "'.$attributes['slug'].'": '.$exception->getMessage(), 0, $exception);
        }

        return new ResourceImportResult(ResourceImportResult::CREATED, $document, $warnings);
    }

    /**
     * New bytes go to a fresh versioned path; the file set being replaced becomes a version row and stays on
     * disk untouched.
     *
     * @param  array<string, mixed>  $input
     * @param  list<string>  $warnings
     */
    private function replace(ResourceDocument $existing, array $input, array $warnings): ResourceImportResult
    {
        /** @var ResourceFileInput $original */
        $original = $input['original'];
        /** @var ResourceFileInput|null $preview */
        $preview = $input['preview'];

        $supersededNumber = $existing->currentVersionNumber();
        $newNumber = $supersededNumber + 1;
        $previous = $existing->only(['original_filename', 'original_sha256', 'original_size', 'preview_sha256']) + ['version' => $supersededNumber];

        $originalChanged = $input['originalSha'] !== $existing->original_sha256;
        $previewChanged = $preview !== null && $input['previewSha'] !== $existing->preview_sha256;
        $changes = [];
        $staged = [];
        $promoted = [];

        try {
            if ($originalChanged) {
                $finalPath = $this->storage->versionedOriginalPath($existing->uuid, $newNumber, $input['extension']);
                $staged[] = $this->stage($finalPath, $original->localPath, $input['originalSha']);
                $changes += [
                    'original_filename' => $this->safeFilename($original->filename),
                    'original_mime' => self::TYPES[$input['extension']][0],
                    'original_size' => (int) filesize($original->localPath),
                    'original_sha256' => $input['originalSha'],
                    'original_path' => $finalPath,
                ];
            }

            if ($previewChanged) {
                $finalPreview = $this->storage->versionedPreviewPath($existing->uuid, $newNumber);
                $staged[] = $this->stage($finalPreview, $preview->localPath, $input['previewSha']);
                $changes += ['preview_path' => $finalPreview, 'preview_sha256' => $input['previewSha']];
            } elseif ($originalChanged && $preview === null && $existing->preview_path !== null) {
                // The old preview shows the old document; members must not be shown it. It stays in the version record.
                $changes += ['preview_path' => null, 'preview_sha256' => null];
                if ($input['extension'] !== 'pdf') {
                    $warnings[] = 'The previous preview PDF no longer applies to the new file, so members cannot open this document in the browser until you add a new preview. The old file and its preview are kept in the version history.';
                }
            }

            $changes += ['page_count' => $input['inspection']['pages'], 'search_text' => $input['inspection']['text']];

            $document = DB::transaction(function () use ($existing, $changes, $staged, $input, $previous, $supersededNumber, &$promoted): ResourceDocument {
                $existing->versions()->create([
                    'version' => $supersededNumber,
                    'original_filename' => $existing->original_filename,
                    'original_mime' => $existing->original_mime,
                    'original_size' => $existing->original_size,
                    'original_sha256' => $existing->original_sha256,
                    'original_path' => $existing->original_path,
                    'preview_path' => $existing->preview_path,
                    'preview_sha256' => $existing->preview_sha256,
                    'page_count' => $existing->page_count,
                    'replaced_by' => $input['actorId'],
                    'replaced_at' => now(),
                ]);
                $existing->update($changes);
                $existing->events()->create([
                    'event' => 'replaced',
                    'actor_id' => $input['actorId'],
                    'metadata' => $this->metadata($input, $existing) + ['previous' => $previous],
                ]);
                foreach ($staged as [$stagedPath, $finalPath, $sha]) {
                    $this->storage->promote($stagedPath, $finalPath, $sha);
                    $promoted[] = $finalPath;
                }

                return $existing;
            });
        } catch (Throwable $exception) {
            // Everything this call wrote is at a path nothing else references: staged copies and promoted copies.
            foreach ($staged as [$stagedPath, $finalPath]) {
                $this->storage->delete($stagedPath);
                if (in_array($finalPath, $promoted, true)) {
                    $this->storage->delete($finalPath);
                }
            }

            throw $exception instanceof ResourceImportException
                ? $exception
                : new ResourceImportException('Could not replace "'.$existing->slug.'": '.$exception->getMessage(), 0, $exception);
        }

        return new ResourceImportResult(ResourceImportResult::REPLACED, $document->refresh(), $warnings);
    }

    /**
     * @return array{0: string, 1: string, 2: string} staged path, final path, sha256
     *
     * @throws ResourceImportException
     */
    private function stage(string $finalPath, string $localPath, string $sha): array
    {
        if ($this->storage->disk()->exists($finalPath)) {
            // A file at a fresh version path that no document or version row points to was left by an
            // interrupted replacement: it was never committed, so it is removed and the path reused.
            if ($this->isReferenced($finalPath)) {
                throw new ResourceImportException('Another change to this document is in progress. Wait a moment and try again.');
            }
            $this->storage->delete($finalPath);
        }
        $stagedPath = $finalPath.ResourceLibraryStorage::INCOMING_SUFFIX;
        $this->storeProven($stagedPath, $localPath, $sha);

        return [$stagedPath, $finalPath, $sha];
    }

    private function storeProven(string $diskPath, string $localPath, string $sha): void
    {
        $this->storage->putFromLocal($diskPath, $localPath);
        if ($this->storage->sha256($diskPath) !== $sha) {
            $this->storage->delete($diskPath);
            throw new ResourceImportException('The stored copy of '.basename($localPath).' did not match its checksum. Nothing was imported.');
        }
    }

    /**
     * @return array{text: string|null, pages: int|null, warnings: list<string>}
     */
    private function inspect(ResourceFileInput $original, string $extension, ?ResourceFileInput $preview): array
    {
        $pdf = $extension === 'pdf' ? $original : $preview;
        if ($pdf === null) {
            return ['text' => null, 'pages' => null, 'warnings' => [
                '"'.$original->filename.'" has no preview PDF: members can download it but not open it in the browser, and its text is not searchable.',
            ]];
        }

        $result = $this->inspector->inspect($pdf->localPath);
        $warnings = $result['text'] === null
            ? ['No searchable text could be read from "'.$pdf->filename.'". It will only be found by title, category and description.']
            : [];

        return $result + ['warnings' => $warnings];
    }

    /** @throws ResourceImportException */
    private function assertChecksumIsFree(string $sha256, ?int $ownerId): void
    {
        $other = ResourceDocument::withTrashed()->where('original_sha256', $sha256)
            ->when($ownerId !== null, fn ($query) => $query->where('id', '!=', $ownerId))
            ->first();
        if ($other !== null) {
            throw new ResourceImportException('This exact file is already in the library as "'.$other->title.'" ('.$other->slug.').');
        }

        // Going back to one of the document's own earlier files is allowed; another document's history is not.
        $earlier = ResourceDocumentVersion::query()->where('original_sha256', $sha256)
            ->when($ownerId !== null, fn ($query) => $query->where('resource_document_id', '!=', $ownerId))
            ->first();
        if ($earlier !== null) {
            $owner = ResourceDocument::withTrashed()->select(['id', 'title', 'slug'])->find($earlier->resource_document_id);
            throw new ResourceImportException('This exact file is an earlier version of "'.($owner->title ?? 'another document').'" ('.($owner->slug ?? 'unknown').').');
        }
    }

    /**
     * Prove the file exists, is an accepted type, is within the size limit, looks like that type, and matches
     * the expected checksum.
     *
     * @throws ResourceImportException
     */
    private function verify(ResourceFileInput $file, string $expectedExtension): string
    {
        if (! is_file($file->localPath) || ! is_readable($file->localPath)) {
            throw new ResourceImportException('File not found or unreadable: '.$file->localPath);
        }
        $extension = $this->extensionOf($file->filename);
        if ($extension !== $expectedExtension) {
            throw new ResourceImportException('"'.$file->filename.'" must be a .'.$expectedExtension.' file.');
        }
        $size = filesize($file->localPath);
        if ($size === false) {
            throw new ResourceImportException('Could not read "'.$file->filename.'" to verify it.');
        }
        if ($size > self::MAX_BYTES) {
            throw new ResourceImportException('"'.$file->filename.'" is '.number_format($size / 1_048_576, 1).' MB. The limit is '.self::maxMegabytes().' MB.');
        }
        $handle = fopen($file->localPath, 'rb');
        $head = $handle === false ? '' : (string) fread($handle, 8);
        if ($handle !== false) {
            fclose($handle);
        }
        if (! str_starts_with($head, self::TYPES[$extension][1])) {
            throw new ResourceImportException('"'.$file->filename.'" does not look like a real .'.$extension.' file.');
        }
        if (isset(self::OOXML_PART_PREFIX[$extension]) && ! $this->hasOoxmlPart($file->localPath, self::OOXML_PART_PREFIX[$extension])) {
            throw new ResourceImportException('"'.$file->filename.'" does not look like a real .'.$extension.' file.');
        }

        $sha = hash_file('sha256', $file->localPath);
        if ($sha === false) {
            throw new ResourceImportException('Could not read "'.$file->filename.'" to verify it.');
        }
        if ($file->expectedSha256 !== null && strtolower($file->expectedSha256) !== $sha) {
            throw new ResourceImportException('Checksum mismatch for "'.$file->filename.'": expected '.strtolower($file->expectedSha256).' but found '.$sha.'. Nothing was imported.');
        }

        return $sha;
    }

    private function isReferenced(string $path): bool
    {
        return ResourceDocument::withTrashed()->where('original_path', $path)->orWhere('preview_path', $path)->exists()
            || ResourceDocumentVersion::query()->where('original_path', $path)->orWhere('preview_path', $path)->exists();
    }

    /** A genuine Office package is a zip with [Content_Types].xml and at least one part under its type's folder. */
    private function hasOoxmlPart(string $path, string $prefix): bool
    {
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            return false;
        }
        try {
            if ($zip->locateName('[Content_Types].xml') === false) {
                return false;
            }
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $name = $zip->getNameIndex($index);
                if (is_string($name) && str_starts_with(str_replace('\\', '/', $name), $prefix)) {
                    return true;
                }
            }

            return false;
        } finally {
            $zip->close();
        }
    }

    /** @throws ResourceImportException */
    private function extensionOf(string $filename): string
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (! isset(self::TYPES[$extension])) {
            throw new ResourceImportException('"'.$filename.'" is not an accepted type. Use '.implode(', ', array_map(fn (string $e): string => '.'.$e, self::allowedExtensions())).'.');
        }

        return $extension;
    }

    private function safeFilename(string $filename): string
    {
        $name = preg_replace('~[\x00-\x1f\x7f"\\\\/:*?<>|]+~', '_', basename(str_replace('\\', '/', $filename))) ?: 'document';

        return mb_substr($name, 0, 255);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function metadata(array $input, ResourceDocument $document): array
    {
        return [
            'source' => $input['source'],
            'filename' => $document->original_filename,
            'sha256' => $document->original_sha256,
            'size' => $document->original_size,
            'preview_sha256' => $document->preview_sha256,
            'pages' => $document->page_count,
        ] + ($document->recipient_employee_id === null ? [] : ['recipient_employee_id' => $document->recipient_employee_id]);
    }
}
