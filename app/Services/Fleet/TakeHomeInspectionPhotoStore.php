<?php

declare(strict_types=1);

namespace App\Services\Fleet;

use App\Jobs\Photos\ProcessTakeHomeInspectionPhoto;
use App\Models\TakeHomeVehicleInspection;
use App\Models\TakeHomeVehicleInspectionPhoto;
use App\Models\User;
use App\Services\Photos\PhotoNormalizer;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Validates and privately stores one inspection photo, quickly.
 *
 * The form resizes on the phone and sends a clean JPEG of about half a megabyte,
 * which is stored exactly as received. An original the phone could not convert
 * (HEIC/HEIF, PNG, WebP, a large or EXIF/GPS-carrying JPEG) is checked by its
 * signature, kept on the private disk and converted by a queued job, so a slow
 * decode never holds a web worker. Files are only served through the authorized
 * photo route.
 *
 * The phone's photo id is the idempotency key: a retried upload returns the photo
 * already stored instead of saving a second copy.
 */
final class TakeHomeInspectionPhotoStore
{
    public const MAX_UPLOAD_KB = 30720;

    /** Originals awaiting conversion, on the private disk. */
    public const INCOMING_DIRECTORY = 'take-home-inspections/incoming';

    private const MIN_EDGE = 240;

    public function __construct(private readonly PhotoNormalizer $photos) {}

    /** @return array{photo: TakeHomeVehicleInspectionPhoto, created: bool} */
    public function store(UploadedFile $file, User $user, string $angle, ?string $checkKey, ?string $clientPhotoId = null): array
    {
        $checkKey = $angle === TakeHomeVehicleInspection::ISSUE_ANGLE ? $checkKey : null;
        if ($clientPhotoId !== null) {
            $existing = $this->existing($clientPhotoId, $user, $angle, $checkKey);
            if ($existing !== null) {
                return ['photo' => $existing, 'created' => false];
            }
        }

        $bytes = @file_get_contents($file->getRealPath() ?: '');
        if (! is_string($bytes) || $bytes === '') {
            throw $this->fail('The photo could not be read. Please take it again.');
        }

        $type = $this->photos->detectType($bytes);
        if ($type === null || $type === 'gif') {
            throw $this->fail('That file is not a supported photo. Use a JPEG, PNG, WebP or iPhone (HEIC) photo.');
        }
        $size = $type === 'heic' ? null : $this->photos->dimensions($bytes);
        if ($type !== 'heic' && $size === null) {
            throw $this->fail('That photo could not be read. Please take it again.');
        }
        if ($size !== null && min($size) < self::MIN_EDGE) {
            throw $this->fail('That photo is too small to be useful. Please take it again.');
        }
        if ($size !== null && ($size[0] * $size[1]) > PhotoNormalizer::MAX_SOURCE_PIXELS) {
            throw $this->fail('That photo is too large. Please take it again.');
        }

        $uuid = $clientPhotoId ?? (string) Str::uuid();
        $disk = Storage::disk(self::disk());
        $path = 'take-home-inspections/photos/'.now()->format('Y/m').'/'.$uuid.'.jpg';
        $fast = $this->photos->isFastPath($bytes, $type);
        $written = $fast ? $path : self::INCOMING_DIRECTORY.'/'.$uuid.'.'.PhotoNormalizer::EXTENSIONS[$type];
        if (! $disk->put($written, $bytes, ['visibility' => 'private'])) {
            throw $this->fail('The photo could not be saved. Please try again.');
        }

        try {
            $photo = TakeHomeVehicleInspectionPhoto::query()->create([
                'uuid' => $uuid,
                'uploaded_by_user_id' => $user->getKey(),
                'angle' => $angle,
                'check_key' => $checkKey,
                'path' => $path,
                'source_path' => $fast ? null : $written,
                'width' => $size[0] ?? 0,
                'height' => $size[1] ?? 0,
                'bytes' => strlen($bytes),
                'sha256' => hash('sha256', $bytes),
            ]);
        } catch (UniqueConstraintViolationException) {
            // Two copies of the same retry raced; both wrote the same bytes to the same place.
            $existing = $this->existing($uuid, $user, $angle, $checkKey);
            if ($existing === null) {
                throw $this->fail('The photo could not be saved. Please try again.');
            }

            return ['photo' => $existing, 'created' => false];
        }

        if (! $fast) {
            ProcessTakeHomeInspectionPhoto::dispatch($uuid);
        }

        return ['photo' => $photo, 'created' => true];
    }

    public static function disk(): string
    {
        return (string) config('filesystems.private', 'local');
    }

    /** The photo a retry of this upload already stored, or null when there is none yet. */
    private function existing(string $uuid, User $user, string $angle, ?string $checkKey): ?TakeHomeVehicleInspectionPhoto
    {
        $photo = TakeHomeVehicleInspectionPhoto::query()->where('uuid', $uuid)->first();
        if ($photo === null) {
            return null;
        }
        if ((int) $photo->uploaded_by_user_id !== (int) $user->getKey() || $photo->angle !== $angle || $photo->check_key !== $checkKey) {
            throw new ConflictHttpException('This photo id was already used. Take or choose the photo again.');
        }

        return $photo;
    }

    private function fail(string $message): ValidationException
    {
        return ValidationException::withMessages(['photo' => $message]);
    }
}
