<?php

declare(strict_types=1);

namespace App\Services\CommandCommunications;

use App\Models\CommandCommunicationAttachment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Validates and stores 300 Command Communications attachments on the private
 * disk. Type is decided by the sniffed content MIME AND an extension
 * allowlist; the stored extension comes from the matched definition, never
 * from the client file name. SVG, HTML and executables never match.
 */
final class CommandCommunicationAttachmentStore
{
    private const OOXML_FALLBACK = ['application/zip', 'application/x-zip-compressed'];

    private const CSV_SNIFFED = ['text/plain', 'text/csv', 'application/csv'];

    /**
     * @return array<string, array{kind: string, mime: string, aliases: list<string>, sniffed: list<string>}>
     */
    private static function definitions(): array
    {
        $image = CommandCommunicationAttachment::KIND_IMAGE;
        $document = CommandCommunicationAttachment::KIND_DOCUMENT;

        return [
            'jpg' => ['kind' => $image, 'mime' => 'image/jpeg', 'aliases' => ['jpg', 'jpeg'], 'sniffed' => ['image/jpeg']],
            'png' => ['kind' => $image, 'mime' => 'image/png', 'aliases' => ['png'], 'sniffed' => ['image/png']],
            'webp' => ['kind' => $image, 'mime' => 'image/webp', 'aliases' => ['webp'], 'sniffed' => ['image/webp']],
            'gif' => ['kind' => $image, 'mime' => 'image/gif', 'aliases' => ['gif'], 'sniffed' => ['image/gif']],
            'pdf' => ['kind' => $document, 'mime' => 'application/pdf', 'aliases' => ['pdf'], 'sniffed' => ['application/pdf']],
            'docx' => ['kind' => $document, 'aliases' => ['docx'], 'mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'sniffed' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', ...self::OOXML_FALLBACK]],
            'xlsx' => ['kind' => $document, 'aliases' => ['xlsx'], 'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'sniffed' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', ...self::OOXML_FALLBACK]],
            'pptx' => ['kind' => $document, 'aliases' => ['pptx'], 'mime' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                'sniffed' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', ...self::OOXML_FALLBACK]],
            'txt' => ['kind' => $document, 'mime' => 'text/plain', 'aliases' => ['txt'], 'sniffed' => ['text/plain']],
            'csv' => ['kind' => $document, 'mime' => 'text/csv', 'aliases' => ['csv'], 'sniffed' => self::CSV_SNIFFED],
        ];
    }

    /**
     * Validate every file and describe it. Nothing is written here.
     *
     * @param  list<mixed>  $files
     * @return list<array{file: UploadedFile, extension: string, mime: string, kind: string, original: string, size: int}>
     */
    public function inspect(array $files): array
    {
        $max = (int) config('command_communications.max_attachments', 5);
        if (count($files) > $max) {
            throw ValidationException::withMessages(['attachments' => "Attach at most {$max} files to one post."]);
        }

        $limit = (int) config('command_communications.max_attachment_bytes', 15 * 1024 * 1024);
        $inspected = [];
        foreach (array_values($files) as $index => $file) {
            $key = 'attachments.'.$index;
            if (! $file instanceof UploadedFile || ! $file->isValid() || $file->getRealPath() === false) {
                throw ValidationException::withMessages([$key => 'A selected file could not be read. Please choose it again.']);
            }
            $size = (int) $file->getSize();
            if ($size <= 0 || $size > $limit) {
                throw ValidationException::withMessages([$key => 'Each file must be between 1 byte and '.(int) floor($limit / 1048576).' MB.']);
            }
            $definition = $this->match($file);
            if ($definition === null) {
                throw ValidationException::withMessages([$key => 'Only JPEG, PNG, WebP, GIF, PDF, DOCX, XLSX, PPTX, TXT, and CSV files are accepted.']);
            }
            $inspected[] = [
                'file' => $file,
                'extension' => $definition['extension'],
                'mime' => $definition['mime'],
                'kind' => $definition['kind'],
                'original' => $this->safeName($file->getClientOriginalName(), $definition['extension']),
                'size' => $size,
            ];
        }

        return $inspected;
    }

    /**
     * Write inspected files to the private disk and return row data. The caller
     * must pass the returned paths to delete() if its database work fails.
     *
     * @param  list<array{file: UploadedFile, extension: string, mime: string, kind: string, original: string, size: int}>  $inspected
     * @return list<array<string, mixed>>
     */
    public function write(array $inspected, int $uploaderId): array
    {
        $disk = (string) config('filesystems.private', 'local');
        $directory = trim((string) config('command_communications.storage_prefix', 'command-communications'), '/').'/'.now()->format('Y/m');
        $written = [];
        try {
            foreach ($inspected as $item) {
                $publicId = (string) Str::ulid();
                $generated = $publicId.'.'.$item['extension'];
                $path = $directory.'/'.$generated;
                $hash = hash_file('sha256', (string) $item['file']->getRealPath());
                if ($hash === false || Storage::disk($disk)->putFileAs($directory, $item['file'], $generated, ['visibility' => 'private']) === false) {
                    throw ValidationException::withMessages(['attachments' => 'A file could not be stored. Please try again.']);
                }
                $written[] = [
                    'public_id' => $publicId,
                    'disk' => $disk,
                    'storage_path' => $path,
                    'generated_filename' => $generated,
                    'original_filename' => $item['original'],
                    'mime_type' => $item['mime'],
                    'byte_size' => $item['size'],
                    'sha256' => $hash,
                    'uploaded_by_user_id' => $uploaderId,
                    'kind' => $item['kind'],
                ];
            }
        } catch (\Throwable $exception) {
            $this->delete($written);
            throw $exception;
        }

        return $written;
    }

    /** @param  list<array<string, mixed>>  $written */
    public function delete(array $written): void
    {
        foreach ($written as $row) {
            try {
                Storage::disk((string) $row['disk'])->delete((string) $row['storage_path']);
            } catch (\Throwable) {
                // Best-effort cleanup; an orphaned private file is not reachable by any route.
            }
        }
    }

    /** @return array{extension: string, mime: string, kind: string}|null */
    private function match(UploadedFile $file): ?array
    {
        $sniffed = $this->sniff((string) $file->getRealPath());
        $clientExtension = strtolower((string) pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION));
        foreach (self::definitions() as $extension => $definition) {
            if (in_array($clientExtension, $definition['aliases'], true) && in_array($sniffed, $definition['sniffed'], true)
                && $this->structureMatches($extension, (string) $file->getRealPath())) {
                return ['extension' => $extension, 'mime' => $definition['mime'], 'kind' => $definition['kind']];
            }
        }

        return null;
    }

    /**
     * Office documents are zip containers; a renamed archive must not pass as
     * one. Require the OOXML manifest and the document's own part directory,
     * and bound the uncompressed size against zip bombs.
     */
    private function structureMatches(string $extension, string $path): bool
    {
        $part = ['docx' => 'word/', 'xlsx' => 'xl/', 'pptx' => 'ppt/'][$extension] ?? null;
        if ($part === null) {
            return true;
        }
        if (! class_exists(\ZipArchive::class)) {
            return false;
        }
        $zip = new \ZipArchive;
        if ($zip->open($path, \ZipArchive::RDONLY) !== true) {
            return false;
        }
        try {
            $manifest = false;
            $hasPart = false;
            $expanded = 0;
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);
                if (! is_array($stat)) {
                    return false;
                }
                $manifest = $manifest || $stat['name'] === '[Content_Types].xml';
                $hasPart = $hasPart || str_starts_with($stat['name'], $part);
                $expanded += (int) $stat['size'];
            }

            return $manifest && $hasPart && $expanded <= 200 * 1024 * 1024;
        } finally {
            $zip->close();
        }
    }

    /** Content-based MIME type via finfo (never the client-declared type). Empty when it cannot be determined. */
    private function sniff(string $path): string
    {
        if (! class_exists(\finfo::class)) {
            return '';
        }
        try {
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        } catch (\Throwable) {
            return ''; // Unreadable or unrecognisable content fails closed.
        }

        return is_string($mime) ? strtolower($mime) : '';
    }

    private function safeName(string $name, string $extension): string
    {
        $clean = preg_replace('/[\x00-\x1F\x7F\\\\\/<>:"|?*]+/u', '_', basename(str_replace('\\', '/', $name)));
        $clean = trim((string) $clean, " ._\t");

        return Str::limit($clean !== '' ? $clean : 'attachment.'.$extension, 255, '');
    }
}
