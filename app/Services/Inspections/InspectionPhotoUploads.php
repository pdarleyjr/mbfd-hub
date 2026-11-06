<?php

declare(strict_types=1);

namespace App\Services\Inspections;

use App\Jobs\Photos\ProcessInspectionPhotoUpload;
use App\Models\InspectionPhotoUpload;
use App\Services\Photos\PhotoNormalizer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Daily Checkout photos, one request per photo.
 *
 * The app shrinks each photo on the phone, keeps it in its offline queue and
 * uploads it on its own, with the phone's id for the photo as the idempotency
 * key. The checkout then names photos by id (`photo_id`) instead of carrying
 * them inside its JSON. A photo's stored path is fixed by its id, so a checkout
 * that reaches the server before one of its photos already records where the
 * photo will be: the issue, observation and concern rows show it as soon as the
 * phone delivers it, and nothing is ever blocked waiting for a photo.
 *
 * Photos stay on the disk and in the directory every Daily Checkout viewer reads
 * (public disk, defects/). A clean JPEG from the app is written once, as received;
 * anything else is held on the private disk and converted by a queued job.
 */
final class InspectionPhotoUploads
{
    /** Where every Daily Checkout photo viewer reads from today. */
    public const DISK = 'public';

    /** An original the phone could not shrink (HEIC from a desktop browser, for example). */
    public const MAX_UPLOAD_KB = 25600;

    /** Uploaded photos no checkout ever named are removed after this many days. */
    public const UNATTACHED_RETENTION_DAYS = 30;

    /** Originals awaiting conversion, on the private disk. */
    public const INCOMING_DIRECTORY = 'inspection-photo-uploads/incoming';

    public function __construct(private readonly PhotoNormalizer $photos) {}

    /** The stored path for a photo id. Never depends on time, so checkout and upload always agree. */
    public static function pathFor(string $uuid): string
    {
        $uuid = strtolower($uuid);

        return 'defects/uploads/'.substr($uuid, 0, 2).'/'.$uuid.'.jpg';
    }

    /** @return array{upload: InspectionPhotoUpload, created: bool} */
    public function store(UploadedFile $file, int $userId, string $uuid): array
    {
        $uuid = strtolower($uuid);
        $existing = $this->owned($uuid, $userId);
        if ($existing !== null && $existing->hasBytes()) {
            return ['upload' => $existing, 'created' => false];
        }

        $bytes = @file_get_contents($file->getRealPath() ?: '');
        if (! is_string($bytes) || $bytes === '') {
            throw $this->fail('The photo could not be read. Choose it again.');
        }
        $type = $this->photos->detectType($bytes);
        if ($type === null) {
            throw $this->fail('That file is not a photo the Hub can use. Use a JPEG, PNG, WebP, GIF or iPhone (HEIC) photo.');
        }
        $size = $type === 'heic' ? null : $this->photos->dimensions($bytes);
        if ($type !== 'heic' && $size === null) {
            throw $this->fail('The photo could not be read. Choose it again.');
        }
        if ($size !== null && ($size[0] * $size[1]) > PhotoNormalizer::MAX_SOURCE_PIXELS) {
            throw $this->fail('That photo is too large. Take it again with the camera.');
        }

        $path = self::pathFor($uuid);
        $fast = $this->photos->isFastPath($bytes, $type);
        $source = $fast ? null : self::INCOMING_DIRECTORY.'/'.$uuid.'.'.PhotoNormalizer::EXTENSIONS[$type];
        $written = $fast
            ? Storage::disk(self::DISK)->put($path, $bytes)
            : Storage::disk(self::privateDisk())->put((string) $source, $bytes, ['visibility' => 'private']);
        if (! $written) {
            throw $this->fail('The photo could not be saved. It stays on this device and will be sent again.');
        }

        $now = now();
        $attributes = [
            'status' => $fast ? InspectionPhotoUpload::STATUS_STORED : InspectionPhotoUpload::STATUS_PROCESSING,
            'disk' => self::DISK,
            'path' => $path,
            'source_path' => $source,
            'bytes' => strlen($bytes),
            'width' => $size[0] ?? null,
            'height' => $size[1] ?? null,
            'sha256' => hash('sha256', $bytes),
            'stored_at' => $fast ? $now : null,
            'failure' => null,
            'conversion_attempts' => 0,
            'updated_at' => $now,
        ];

        if (! $this->claim($uuid, $userId, $attributes, $now)) {
            // Somebody else filled this row first (a concurrent copy of the same retry).
            return ['upload' => $this->alreadyDelivered($uuid, $userId, $source), 'created' => false];
        }

        $previousSource = $existing?->source_path;
        if (is_string($previousSource) && $previousSource !== $source) {
            // An earlier failed copy held its original under another extension.
            Storage::disk(self::privateDisk())->delete($previousSource);
        }

        if (! $fast) {
            ProcessInspectionPhotoUpload::dispatch($uuid);
        }

        return ['upload' => InspectionPhotoUpload::query()->where('uuid', $uuid)->firstOrFail(), 'created' => true];
    }

    /**
     * Write this upload into its row in two race-free steps: insert the row when there is
     * none, otherwise fill it only while it is still waiting for bytes. A checkout's
     * attach() may insert the awaiting_upload placeholder at any moment between this
     * request's first read and its write; the guarded UPDATE then fills that placeholder
     * instead of losing the photo.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function claim(string $uuid, int $userId, array $attributes, \DateTimeInterface $now): bool
    {
        $inserted = InspectionPhotoUpload::query()->insertOrIgnore([
            'uuid' => $uuid,
            'user_id' => $userId,
            ...$attributes,
            'created_at' => $now,
        ]);
        if ($inserted > 0) {
            return true;
        }

        return InspectionPhotoUpload::query()
            ->where('uuid', $uuid)
            ->where('user_id', $userId)
            ->whereIn('status', [InspectionPhotoUpload::STATUS_AWAITING_UPLOAD, InspectionPhotoUpload::STATUS_FAILED])
            ->update($attributes) > 0;
    }

    /** The row another request filled. Reports "already stored" only when it really holds bytes. */
    private function alreadyDelivered(string $uuid, int $userId, ?string $source): InspectionPhotoUpload
    {
        $winner = $this->owned($uuid, $userId);
        if ($winner === null || ! $winner->hasBytes()) {
            throw $this->fail('The photo could not be saved. It stays on this device and will be sent again.');
        }
        if (is_string($source) && $winner->source_path !== $source) {
            // The winner already converted (or holds its original elsewhere): this copy is spare.
            Storage::disk(self::privateDisk())->delete($source);
        }

        return $winner;
    }

    /**
     * The stored path for each photo id a checkout names. An id another member owns
     * resolves to null (that photo is left out, never the checkout). Two ids of this
     * member whose stored bytes are identical resolve to one file.
     *
     * @param  list<string>  $uuids
     * @return array<string, string|null>
     */
    public function resolve(array $uuids, int $userId): array
    {
        $uuids = array_values(array_unique(array_map(static fn (string $uuid): string => strtolower($uuid), $uuids)));
        if ($uuids === []) {
            return [];
        }
        $rows = InspectionPhotoUpload::query()->whereIn('uuid', $uuids)->get()->keyBy('uuid');
        $byHash = [];
        $paths = [];
        foreach ($uuids as $uuid) {
            $row = $rows->get($uuid);
            if ($row === null) {
                $paths[$uuid] = self::pathFor($uuid);

                continue;
            }
            if ((int) $row->user_id !== $userId) {
                $paths[$uuid] = null;

                continue;
            }
            $paths[$uuid] = $row->hasBytes() && is_string($row->sha256)
                ? ($byHash[$row->sha256] ??= $row->path)
                : $row->path;
        }

        return $paths;
    }

    /**
     * Inside the checkout's transaction: link each photo the checkout uses to it. A photo the
     * phone has not delivered yet gets its row now, at the path the checkout recorded.
     *
     * @param  array<string, string|null>  $paths  resolve() output for the ids this checkout used
     */
    public function attach(array $paths, int $userId, int $inspectionId): void
    {
        $now = now();
        foreach ($paths as $uuid => $path) {
            if ($path === null || $path !== self::pathFor($uuid)) {
                continue; // Another member's id, or a duplicate whose bytes live in another photo's file.
            }
            InspectionPhotoUpload::query()->insertOrIgnore([
                'uuid' => $uuid,
                'user_id' => $userId,
                'apparatus_inspection_id' => $inspectionId,
                'status' => InspectionPhotoUpload::STATUS_AWAITING_UPLOAD,
                'disk' => self::DISK,
                'path' => $path,
                'attached_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            InspectionPhotoUpload::query()
                ->where('uuid', $uuid)
                ->where('user_id', $userId)
                ->whereNull('apparatus_inspection_id')
                ->update(['apparatus_inspection_id' => $inspectionId, 'attached_at' => $now, 'updated_at' => $now]);
        }
    }

    public static function privateDisk(): string
    {
        return (string) config('filesystems.private', 'local');
    }

    private function owned(string $uuid, int $userId): ?InspectionPhotoUpload
    {
        $row = InspectionPhotoUpload::query()->where('uuid', $uuid)->first();
        if ($row !== null && (int) $row->user_id !== $userId) {
            throw new ConflictHttpException('This photo id belongs to another member.');
        }

        return $row;
    }

    private function fail(string $message): ValidationException
    {
        return ValidationException::withMessages(['photo' => $message]);
    }
}
