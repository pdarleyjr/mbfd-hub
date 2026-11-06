<?php

declare(strict_types=1);

namespace App\Services\Personnel;

use App\Models\Employee;
use App\Models\PersonnelDocument;
use App\Models\PersonnelDocumentEvent;
use App\Models\User;
use App\Services\Fleet\VehicleDocumentLibrary;
use App\Support\Personnel\PersonnelAccess;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Mbfd\PolicyLibrary\Services\MalwareScanner;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The one way personnel files are stored and read. A file is written once to private storage, checked against
 * its declared type, scanned when the scanner is configured, hashed, and recorded with every later view,
 * download and visibility change. Screens link to the record; they never copy the file.
 */
final class PersonnelDocumentService
{
    /** Personnel uploads are documents and images, not video. */
    public const MAX_UPLOAD_BYTES = 25 * 1024 * 1024;

    private const UPLOAD_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png', 'heic', 'heif', 'doc', 'docx', 'xlsx', 'msg'];

    public function __construct(private readonly VehicleDocumentLibrary $inspector) {}

    /**
     * Stores an administrator's upload for an employee.
     *
     * @param  array{title?:string|null, category:string, description?:string|null, visible_to_employee?:bool, source?:Model|null}  $attributes
     */
    public function upload(UploadedFile|string $file, string $originalName, Employee $employee, User $actor, array $attributes): PersonnelDocument
    {
        abort_unless(PersonnelAccess::canManageDocuments($actor), 403);
        $data = Validator::make($attributes, [
            'title' => ['nullable', 'string', 'max:200'],
            'category' => ['required', Rule::in(array_keys(PersonnelDocument::CATEGORIES))],
            'description' => ['nullable', 'string', 'max:1000'],
            'visible_to_employee' => ['sometimes', 'boolean'],
        ])->validate();
        $path = $file instanceof UploadedFile ? (string) $file->getRealPath() : $file;
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (! in_array($extension, self::UPLOAD_EXTENSIONS, true) || (is_file($path) && filesize($path) > self::MAX_UPLOAD_BYTES)) {
            throw ValidationException::withMessages(['files' => 'Choose a PDF, image, Word, Excel or Outlook file of 25 MB or less.']);
        }
        $inspection = $this->inspector->inspectFile($path, $originalName);
        app(MalwareScanner::class)->scan($path);

        return $this->persist((string) file_get_contents($path), $inspection, [
            'employee_id' => $employee->getKey(),
            'title' => trim((string) ($data['title'] ?? '')) ?: pathinfo($originalName, PATHINFO_FILENAME),
            'category' => $data['category'],
            'description' => $data['description'] ?? null,
            'visible_to_employee' => (bool) ($data['visible_to_employee'] ?? false),
            'access_level' => $data['category'] === 'accident_report' ? PersonnelDocument::ACCESS_ACCIDENT : PersonnelDocument::ACCESS_PERSONNEL,
            'original_name' => $this->safeName($originalName),
        ], $actor, $attributes['source'] ?? null);
    }

    /**
     * Stores a PDF the Hub generated (finished accident report, memorandum). When $previous is given the new row
     * is the next version of it; the earlier file is kept.
     *
     * @param  array{employee_id:int|null, title:string, category:string, visible_to_employee:bool, access_level:string, original_name:string, description?:string|null}  $attributes
     */
    public function storeGeneratedPdf(string $bytes, array $attributes, ?User $actor, ?Model $source = null, ?PersonnelDocument $previous = null): PersonnelDocument
    {
        if (! str_starts_with($bytes, '%PDF-')) {
            throw new RuntimeException('A generated personnel document must be a PDF.');
        }
        $inspection = ['mime_type' => 'application/pdf', 'size_bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes)];

        return $this->persist($bytes, $inspection, $attributes + [
            'version' => $previous ? $previous->version + 1 : 1,
            'supersedes_id' => $previous?->getKey(),
        ], $actor, $source);
    }

    public function setVisibility(PersonnelDocument $document, bool $visible, User $actor): PersonnelDocument
    {
        abort_unless(PersonnelAccess::canManageDocuments($actor) && $document->access_level === PersonnelDocument::ACCESS_PERSONNEL, 403);
        if ($document->visible_to_employee !== $visible) {
            $document->forceFill(['visible_to_employee' => $visible])->save();
            $this->record($document, $actor, $visible ? 'shared_with_employee' : 'hidden_from_employee');
        }

        return $document;
    }

    /** Streams a file after an authorization check, recording who opened it. */
    public function stream(PersonnelDocument $document, ?User $actor, bool $download): StreamedResponse
    {
        abort_unless(PersonnelAccess::canViewDocument($actor, $document), 404);

        return $this->respond($document, $actor, $download);
    }

    /** For a caller that has already authorized the reader by another rule (a Support Services thread). */
    public function streamAuthorized(PersonnelDocument $document, ?User $actor, bool $download): StreamedResponse
    {
        return $this->respond($document, $actor, $download);
    }

    public function contents(PersonnelDocument $document): string
    {
        $bytes = Storage::disk($document->storage_disk)->get($document->storage_path);
        if (! is_string($bytes) || ! hash_equals($document->sha256, hash('sha256', $bytes))) {
            throw new RuntimeException('A stored personnel document failed its integrity check.');
        }

        return $bytes;
    }

    public function record(PersonnelDocument $document, ?User $actor, string $action, array $metadata = []): void
    {
        PersonnelDocumentEvent::query()->create([
            'personnel_document_id' => $document->getKey(),
            'actor_user_id' => $actor?->getKey(),
            'action' => $action,
            'metadata' => $metadata,
        ]);
    }

    private function respond(PersonnelDocument $document, ?User $actor, bool $download): StreamedResponse
    {
        $bytes = $this->contents($document);
        $this->record($document, $actor, $download ? 'downloaded' : 'viewed');
        $name = $this->safeName($document->original_name);
        $inline = ! $download && ($document->isPdf() || str_starts_with($document->mime_type, 'image/'));

        $headers = [
            'Content-Type' => $document->mime_type,
            'Content-Length' => (string) strlen($bytes),
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
            'Cross-Origin-Resource-Policy' => 'same-origin',
            'Referrer-Policy' => 'no-referrer',
        ];
        // No `sandbox` for an inline PDF: Chrome refuses to open its PDF viewer inside a sandboxed document.
        if (! ($inline && $document->isPdf())) {
            $headers['Content-Security-Policy'] = "sandbox; default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'";
        }

        return response()->streamDownload(function () use ($bytes): void {
            echo $bytes;
        }, $name, $headers, $inline ? 'inline' : 'attachment');
    }

    private function persist(string $bytes, array $inspection, array $attributes, ?User $actor, ?Model $source): PersonnelDocument
    {
        $disk = (string) config('filesystems.private', 'private');
        $ulid = (string) Str::ulid();
        $extension = strtolower(pathinfo((string) $attributes['original_name'], PATHINFO_EXTENSION)) ?: 'bin';
        $path = 'personnel-documents/'.now()->format('Y/m').'/'.$ulid.'.'.$extension;
        if (! Storage::disk($disk)->put($path, $bytes)) {
            throw new RuntimeException('The personnel document could not be stored.');
        }

        try {
            return DB::transaction(function () use ($attributes, $inspection, $disk, $path, $actor, $source): PersonnelDocument {
                $document = new PersonnelDocument($attributes + [
                    'storage_disk' => $disk,
                    'storage_path' => $path,
                    'mime_type' => $inspection['mime_type'],
                    'size_bytes' => $inspection['size_bytes'],
                    'sha256' => $inspection['sha256'],
                    'uploaded_by_user_id' => $actor?->getKey(),
                ]);
                if ($source !== null) {
                    $document->source()->associate($source);
                }
                $document->save();
                $this->record($document, $actor, $document->supersedes_id ? 'new_version' : 'stored', [
                    'category' => $document->category,
                    'visible_to_employee' => $document->visible_to_employee,
                    'sha256' => $document->sha256,
                ]);

                return $document;
            });
        } catch (\Throwable $exception) {
            // Never leave an orphaned file behind a failed record.
            Storage::disk($disk)->delete($path);
            throw $exception;
        }
    }

    private function safeName(string $name): string
    {
        $clean = preg_replace('/[^A-Za-z0-9 ._()\-]+/', '', basename(str_replace('\\', '/', $name))) ?? 'document';
        $clean = trim(preg_replace('/\s+/', ' ', $clean) ?? '');

        return Str::limit($clean !== '' ? $clean : 'document', 180, '');
    }
}
