<?php

declare(strict_types=1);

namespace App\Services\Fleet;

use App\Models\UnitMasterVehicle;
use App\Models\User;
use App\Models\VehicleDocument;
use App\Models\VehicleDocumentEvent;
use App\Models\VehicleDocumentSource;
use App\Support\Fleet\VehicleDocumentAccess;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Mbfd\PolicyLibrary\Services\MalwareScanner;
use RuntimeException;
use Throwable;
use ZipArchive;

/** One writer for immutable private originals, relationships and source provenance. */
final class VehicleDocumentLibrary
{
    public const MAX_FILE_BYTES = 134217728;

    public const MAX_UPLOAD_FILES = 20;

    public const ALLOWED_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png', 'heic', 'heif', 'doc', 'docx', 'msg', 'ods', 'xlsx', 'mp4'];

    public function upload(UploadedFile $file, UnitMasterVehicle $vehicle, User $actor, array $metadata = []): VehicleDocument
    {
        abort_unless(VehicleDocumentAccess::canManage($actor, $vehicle), 403);
        if (! $file->isValid()) {
            throw ValidationException::withMessages(['files' => 'The upload did not finish. Choose the file and try again.']);
        }

        return $this->ingest($file->getPathname(), array_merge($metadata, [
            'original_filename' => $file->getClientOriginalName(),
            'source_type' => 'manual_upload',
        ]), [$vehicle->getKey()], $actor);
    }

    /**
     * The CLI importer may use a null actor after validating its trusted manifest.
     * HTTP callers must provide the authenticated actor; file paths never come from a request.
     *
     * @param  list<int>  $vehicleIds
     */
    public function ingest(string $sourcePath, array $metadata, array $vehicleIds, ?User $actor = null): VehicleDocument
    {
        $filename = $this->filename((string) ($metadata['original_filename'] ?? basename($sourcePath)));
        $values = $this->metadata($metadata + [
            'title' => pathinfo($filename, PATHINFO_FILENAME),
            'category' => 'other',
            'review_status' => $vehicleIds === [] ? VehicleDocument::STATUS_UNMATCHED : VehicleDocument::STATUS_VERIFIED,
            'is_sensitive' => true,
        ]);
        $ids = $this->vehicleIds($vehicleIds);
        if ($values['review_status'] === VehicleDocument::STATUS_VERIFIED && $ids === []) {
            throw ValidationException::withMessages(['vehicle_ids' => 'Choose a verified vehicle before marking the document verified.']);
        }
        if ($actor !== null) {
            abort_unless(VehicleDocumentAccess::canManage($actor), 403);
            abort_if($values['is_sensitive'] && ! VehicleDocumentAccess::canSensitive($actor), 403);
        }

        $file = $this->inspectFile($sourcePath, $filename);
        app(MalwareScanner::class)->scan($sourcePath);
        $diskName = $this->privateDisk();
        $disk = Storage::disk($diskName);
        $stagedPath = null;

        try {
            $existing = VehicleDocument::query()->where('sha256', $file['sha256'])->first();
            if ($existing === null) {
                $stagedPath = 'vehicle-documents/'.substr($file['sha256'], 0, 2).'/'.Str::uuid().'.bin';
                $input = fopen($sourcePath, 'rb');
                if ($input === false) {
                    throw new RuntimeException('The source document could not be read.');
                }
                try {
                    if (! $disk->put($stagedPath, $input, ['visibility' => 'private'])) {
                        throw new RuntimeException('The document could not be saved. No document record was created.');
                    }
                } finally {
                    fclose($input);
                }
                $this->closeVerified(new VehicleDocument($file + ['disk' => $diskName, 'path' => $stagedPath]));
            }

            $document = DB::transaction(function () use ($existing, $file, $values, $metadata, $filename, $diskName, $stagedPath, $ids, $actor): VehicleDocument {
                $document = $existing ?? VehicleDocument::query()->firstOrCreate(['sha256' => $file['sha256']], $values + $file + [
                    'original_filename' => $filename,
                    'disk' => $diskName,
                    'path' => $stagedPath,
                    'uploaded_by' => $actor?->getKey(),
                    'source_type' => $metadata['source_type'] ?? 'historical_import',
                ]);
                if (! $document->wasRecentlyCreated) {
                    $document = VehicleDocument::query()->lockForUpdate()->findOrFail($document->getKey());
                }
                if (! $document->wasRecentlyCreated && $actor !== null) {
                    abort_unless(VehicleDocumentAccess::canManageDocument($actor, $document), 403);
                }
                $this->closeVerified($document);
                $before = $document->vehicles()->pluck('unit_master_vehicles.id')->all();
                $document->vehicles()->syncWithoutDetaching($ids);

                // A re-run may add reliable evidence; it may never lower protections.
                if (! $document->wasRecentlyCreated) {
                    $updates = [];
                    if ($values['is_sensitive'] && ! $document->is_sensitive) {
                        $updates['is_sensitive'] = true;
                    }
                    if (($values['legal_hold'] ?? false) && ! $document->legal_hold) {
                        $updates['legal_hold'] = true;
                    }
                    if ($values['review_status'] === VehicleDocument::STATUS_VERIFIED && $ids !== [] && $document->review_status !== VehicleDocument::STATUS_VERIFIED) {
                        $updates['review_status'] = VehicleDocument::STATUS_VERIFIED;
                        $updates['classification_evidence'] = $values['classification_evidence'] ?? [];
                    }
                    if ($updates !== []) {
                        $document->fill($updates)->save();
                    }
                }

                $relative = $metadata['source_relative_path'] ?? null;
                $newSource = false;
                if (is_string($relative) && $relative !== '') {
                    $source = VehicleDocumentSource::query()->firstOrCreate([
                        'document_id' => $document->getKey(),
                        'source_hash' => hash('sha256', str_replace('\\', '/', $relative)),
                    ], [
                        'source_relative_path' => $relative,
                        'import_batch' => $metadata['import_batch'] ?? null,
                        'status' => $values['review_status'],
                        'metadata' => [
                            'evidence' => $values['classification_evidence'] ?? [],
                            'candidate_vehicle_ids' => array_values($metadata['candidate_vehicle_ids'] ?? []),
                            'sha256' => $file['sha256'],
                        ],
                    ]);
                    $newSource = $source->wasRecentlyCreated;
                }
                if ($document->wasRecentlyCreated || $newSource || array_diff($ids, $before) !== []) {
                    $this->recordEvent($document, $actor, $document->wasRecentlyCreated ? 'created' : 'duplicate_linked', ['vehicle_ids' => $ids]);
                }

                return $document;
            });
        } catch (Throwable $exception) {
            if ($stagedPath !== null && ! VehicleDocument::query()->where('disk', $diskName)->where('path', $stagedPath)->exists()) {
                $disk->delete($stagedPath);
            }
            throw $exception;
        }

        // A concurrent identical upload may have won the hash uniqueness race.
        if ($stagedPath !== null && $document->path !== $stagedPath) {
            $disk->delete($stagedPath);
        }

        return $document->refresh();
    }

    /** @param list<int> $vehicleIds */
    public function updateMetadata(VehicleDocument $document, User $actor, array $metadata, array $vehicleIds): VehicleDocument
    {
        abort_unless(VehicleDocumentAccess::canManageDocument($actor, $document), 403);
        $values = $this->metadata($metadata);
        abort_if(array_key_exists('is_sensitive', $values) && $values['is_sensitive'] !== $document->is_sensitive && ! VehicleDocumentAccess::canSensitive($actor), 403);
        $ids = $this->vehicleIds($vehicleIds);
        if (($values['review_status'] ?? $document->review_status) === VehicleDocument::STATUS_VERIFIED && $ids === []) {
            throw ValidationException::withMessages(['vehicle_ids' => 'Keep a verified vehicle association or mark this document for review.']);
        }

        return DB::transaction(function () use ($document, $actor, $values, $ids): VehicleDocument {
            $locked = VehicleDocument::query()->lockForUpdate()->findOrFail($document->getKey());
            abort_unless(VehicleDocumentAccess::canManageDocument($actor, $locked), 403);
            if ($locked->legal_hold && array_key_exists('legal_hold', $values) && ! $values['legal_hold']) {
                throw ValidationException::withMessages(['legal_hold' => 'This document is on legal hold. Releasing a hold requires the City records process.']);
            }
            if (($values['review_status'] ?? $locked->review_status) === VehicleDocument::STATUS_VERIFIED && $ids === []) {
                throw ValidationException::withMessages(['vehicle_ids' => 'Keep a verified vehicle association or mark this document for review.']);
            }
            $locked->fill($values)->save();
            $locked->vehicles()->sync($ids);
            $this->recordEvent($locked, $actor, 'metadata_updated', ['vehicle_ids' => $ids]);

            return $locked->refresh();
        });
    }

    /** @return resource A verified, seekable copy; the caller must close it. */
    public function verifiedStream(VehicleDocument $document)
    {
        if ($document->size_bytes < 1 || $document->size_bytes > self::MAX_FILE_BYTES || ! preg_match('/^[a-f0-9]{64}$/', $document->sha256)) {
            throw new RuntimeException('The stored document has invalid integrity metadata.');
        }
        if (! preg_match('#^vehicle-documents/[a-f0-9]{2}/[a-f0-9-]+\.bin$#', $document->path) || $document->disk === 'public') {
            throw new RuntimeException('The stored document location is not an approved private location.');
        }
        $input = Storage::disk($this->privateDisk($document->disk))->readStream($document->path);
        if (! is_resource($input)) {
            throw new RuntimeException('The stored document is unavailable.');
        }
        $copy = fopen('php://temp/maxmemory:2097152', 'w+b');
        if ($copy === false) {
            fclose($input);
            throw new RuntimeException('Unable to prepare the document download.');
        }
        try {
            $bytes = stream_copy_to_stream($input, $copy, $document->size_bytes + 1);
            if ($bytes !== $document->size_bytes || ! rewind($copy)) {
                throw new RuntimeException('The stored document failed its integrity check.');
            }
            $hash = hash_init('sha256');
            if (hash_update_stream($hash, $copy) !== $document->size_bytes || ! hash_equals($document->sha256, hash_final($hash)) || ! rewind($copy)) {
                throw new RuntimeException('The stored document failed its integrity check.');
            }
        } catch (Throwable $exception) {
            fclose($copy);
            throw $exception;
        } finally {
            fclose($input);
        }

        return $copy;
    }

    public function recordEvent(VehicleDocument $document, ?User $actor, string $action, array $safeMetadata = []): VehicleDocumentEvent
    {
        return VehicleDocumentEvent::query()->create([
            'document_id' => $document->getKey(),
            'user_id' => $actor?->getKey(),
            'action' => $action,
            'metadata' => array_intersect_key($safeMetadata, array_flip(['vehicle_id', 'vehicle_ids', 'document_ids', 'format', 'count', 'batch_id'])),
        ]);
    }

    /** @return array{mime_type: string, size_bytes: int, sha256: string} */
    public function inspectFile(string $path, string $originalFilename): array
    {
        $extension = strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION));
        $size = is_file($path) && ! is_link($path) ? filesize($path) : false;
        if ($size === false || $size < 1 || $size > self::MAX_FILE_BYTES || ! in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            throw ValidationException::withMessages(['files' => 'Choose a supported, nonempty document of 128 MB or less.']);
        }
        $header = file_get_contents($path, false, null, 0, 64);
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        $valid = false;
        $canonicalMime = match ($extension) {
            'pdf' => 'application/pdf',
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'heic', 'heif' => 'image/heic',
            'doc' => 'application/msword',
            'msg' => 'application/vnd.ms-outlook',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'ods' => 'application/vnd.oasis.opendocument.spreadsheet',
            'mp4' => 'video/mp4',
        };
        if ($extension === 'pdf') {
            $tail = file_get_contents($path, false, null, max(0, $size - 2048));
            $valid = $mime === 'application/pdf' && str_starts_with((string) $header, '%PDF-') && str_contains((string) $tail, '%%EOF');
        } elseif (in_array($extension, ['jpg', 'jpeg', 'png'], true)) {
            $dimensions = @getimagesize($path);
            $valid = is_array($dimensions) && $dimensions['mime'] === $canonicalMime
                && $dimensions[0] > 0 && $dimensions[1] > 0 && $dimensions[0] * $dimensions[1] <= 80000000;
        } elseif (in_array($extension, ['doc', 'msg'], true)) {
            $valid = str_starts_with((string) $header, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1")
                && in_array($mime, ['application/msword', 'application/vnd.ms-outlook', 'application/CDFV2', 'application/x-ole-storage', 'application/octet-stream'], true);
        } elseif (in_array($extension, ['docx', 'xlsx', 'ods'], true)) {
            $valid = $this->validOfficeArchive($path, $extension);
        } elseif (in_array($extension, ['heic', 'heif', 'mp4'], true)) {
            $brands = substr((string) $header, 8);
            $valid = substr((string) $header, 4, 4) === 'ftyp' && preg_match(
                $extension === 'mp4' ? '/isom|iso[2-9]|mp4[12]|avc1|M4V |dash/' : '/heic|heix|hevc|hevx|mif1|msf1/',
                $brands,
            ) === 1;
        }
        if (! $valid) {
            throw ValidationException::withMessages(['files' => 'The document contents do not match a supported file format, or exceed safe processing limits.']);
        }
        $sha256 = hash_file('sha256', $path);
        if (! is_string($sha256)) {
            throw new RuntimeException('The source document could not be verified.');
        }

        return ['mime_type' => $canonicalMime, 'size_bytes' => $size, 'sha256' => $sha256];
    }

    private function validOfficeArchive(string $path, string $extension): bool
    {
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            return false;
        }
        try {
            if ($zip->numFiles > 2000) {
                return false;
            }
            $total = 0;
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $entry = $zip->statIndex($index);
                if (! is_array($entry)) {
                    return false;
                }
                $name = str_replace('\\', '/', $entry['name']);
                $total += $entry['size'];
                if (str_starts_with($name, '/') || preg_match('#(^|/)\.\.(/|$)|:|vbaproject|activex|embeddings/#i', $name)
                    || $total > 209715200 || ($entry['size'] > 1048576 && $entry['size'] > max(1, $entry['comp_size']) * 500)
                    || $entry['encryption_method'] !== 0) {
                    return false;
                }
            }

            return match ($extension) {
                'docx' => $zip->locateName('[Content_Types].xml') !== false && $zip->locateName('word/document.xml') !== false,
                'xlsx' => $zip->locateName('[Content_Types].xml') !== false && $zip->locateName('xl/workbook.xml') !== false,
                'ods' => $zip->getFromName('mimetype', 128) === 'application/vnd.oasis.opendocument.spreadsheet' && $zip->locateName('content.xml') !== false,
                default => false,
            };
        } finally {
            $zip->close();
        }
    }

    private function metadata(array $metadata): array
    {
        foreach (['title', 'category', 'description', 'retention_series', 'report_number'] as $field) {
            if (is_string($metadata[$field] ?? null)) {
                $metadata[$field] = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $metadata[$field]) ?? '');
            }
        }
        $data = Validator::make($metadata, [
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'category' => ['sometimes', 'required', 'string', 'max:48', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'description' => ['nullable', 'string', 'max:10000'],
            'document_date' => ['nullable', 'date_format:Y-m-d'],
            'report_number' => ['nullable', 'string', 'max:100'],
            'review_status' => ['sometimes', Rule::in([VehicleDocument::STATUS_VERIFIED, VehicleDocument::STATUS_NEEDS_REVIEW, VehicleDocument::STATUS_UNMATCHED])],
            'is_sensitive' => ['sometimes', 'boolean'],
            'classification_evidence' => ['nullable', 'array', 'max:200'],
            'retention_series' => ['nullable', 'string', 'max:128'],
            'legal_hold' => ['sometimes', 'boolean'],
            'source_relative_path' => ['nullable', 'string', 'max:4096'],
            'import_batch' => ['nullable', 'string', 'max:128'],
            'source_type' => ['sometimes', Rule::in(['manual_upload', 'historical_import'])],
            'candidate_vehicle_ids' => ['sometimes', 'array', 'max:100'],
            'candidate_vehicle_ids.*' => ['integer', 'min:1'],
        ])->validate();
        if (strlen((string) json_encode($data['classification_evidence'] ?? [])) > 65536) {
            throw ValidationException::withMessages(['classification_evidence' => 'The identifying evidence is too large. Keep only the relevant references.']);
        }
        unset($data['source_relative_path'], $data['import_batch'], $data['source_type'], $data['candidate_vehicle_ids']);
        foreach (['is_sensitive', 'legal_hold'] as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = (bool) $data[$field];
            }
        }

        return $data;
    }

    private function filename(string $filename): string
    {
        $filename = basename(str_replace('\\', '/', $filename));
        if ($filename === '' || mb_strlen($filename) > 255 || preg_match('/[\x00-\x1F\x7F:]/', $filename)) {
            throw ValidationException::withMessages(['files' => 'The original filename is invalid. Use a readable filename without control characters.']);
        }

        return $filename;
    }

    /** @return list<int> */
    private function vehicleIds(array $vehicleIds): array
    {
        Validator::make(['vehicle_ids' => $vehicleIds], [
            'vehicle_ids' => ['array', 'max:100'],
            'vehicle_ids.*' => ['required', 'integer', 'min:1'],
        ])->validate();
        $ids = array_values(array_unique(array_map('intval', $vehicleIds)));
        if (UnitMasterVehicle::query()->whereKey($ids)->count() !== count($ids)) {
            throw ValidationException::withMessages(['vehicle_ids' => 'One of the selected vehicles is no longer available.']);
        }

        return $ids;
    }

    private function privateDisk(?string $name = null): string
    {
        $name ??= (string) config('filesystems.private', 'local');
        $config = config('filesystems.disks.'.$name);
        if ($name === 'public' || ! is_array($config) || ($config['visibility'] ?? 'private') === 'public') {
            throw new RuntimeException('Vehicle documents require approved private storage.');
        }
        if (($config['driver'] ?? null) === 'local') {
            $configuredRoot = (string) ($config['root'] ?? '');
            $root = strtolower(str_replace('\\', '/', realpath($configuredRoot) ?: $configuredRoot));
            foreach ([public_path(), (string) config('filesystems.disks.public.root', storage_path('app/public'))] as $publicRoot) {
                $public = strtolower(str_replace('\\', '/', realpath($publicRoot) ?: $publicRoot));
                if ($root === '' || str_starts_with(rtrim($root, '/').'/', rtrim($public, '/').'/')) {
                    throw new RuntimeException('Vehicle documents cannot be stored inside a publicly served directory.');
                }
            }
        }

        return $name;
    }

    private function closeVerified(VehicleDocument $document): void
    {
        fclose($this->verifiedStream($document));
    }
}
