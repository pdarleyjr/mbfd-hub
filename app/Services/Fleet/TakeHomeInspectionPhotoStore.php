<?php

declare(strict_types=1);

namespace App\Services\Fleet;

use App\Models\TakeHomeVehicleInspection;
use App\Models\TakeHomeVehicleInspectionPhoto;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Validates, re-encodes and privately stores one inspection photo.
 *
 * The browser usually sends an already-resized JPEG, but the original camera file
 * (JPEG, PNG, WebP, HEIC/HEIF) is accepted as a fallback. Every image is decoded
 * by signature, oriented, bounded to MAX_EDGE pixels and re-encoded as a fresh
 * JPEG, which drops all EXIF/GPS metadata. Files live on the private disk under
 * a random name and are only served through the authorized photo route.
 */
final class TakeHomeInspectionPhotoStore
{
    public const MAX_UPLOAD_KB = 30720;

    private const MAX_EDGE = 2400;

    private const MIN_EDGE = 240;

    private const MAX_SOURCE_PIXELS = 60_000_000;

    private const JPEG_QUALITY = 85;

    public function store(UploadedFile $file, User $user, string $angle, ?string $checkKey): TakeHomeVehicleInspectionPhoto
    {
        $bytes = @file_get_contents($file->getRealPath() ?: '');
        if (! is_string($bytes) || $bytes === '') {
            throw $this->fail('The photo could not be read. Please take it again.');
        }

        $type = $this->detectType($bytes);
        if ($type === null) {
            throw $this->fail('That file is not a supported photo. Use a JPEG, PNG, WebP or iPhone (HEIC) photo.');
        }

        $image = $type === 'heic' ? $this->decodeWithImagick($bytes) : $this->decodeWithGd($bytes, $type);
        $image = $this->orient($image, (string) $file->getRealPath(), $type);
        $width = imagesx($image);
        $height = imagesy($image);
        if (min($width, $height) < self::MIN_EDGE) {
            imagedestroy($image);
            throw $this->fail('That photo is too small to be useful. Please take it again.');
        }

        $image = $this->bound($image);
        ob_start();
        imageinterlace($image, true);
        $encoded = imagejpeg($image, null, self::JPEG_QUALITY);
        $jpeg = (string) ob_get_clean();
        $width = imagesx($image);
        $height = imagesy($image);
        imagedestroy($image);
        if (! $encoded || $jpeg === '') {
            throw $this->fail('The photo could not be processed. Please try again.');
        }

        $uuid = (string) Str::uuid();
        $disk = self::disk();
        $path = 'take-home-inspections/photos/'.now()->format('Y/m').'/'.$uuid.'.jpg';
        if (! Storage::disk($disk)->put($path, $jpeg, ['visibility' => 'private'])) {
            throw $this->fail('The photo could not be saved. Please try again.');
        }

        return TakeHomeVehicleInspectionPhoto::query()->create([
            'uuid' => $uuid,
            'uploaded_by_user_id' => $user->getKey(),
            'angle' => $angle,
            'check_key' => $angle === TakeHomeVehicleInspection::ISSUE_ANGLE ? $checkKey : null,
            'path' => $path,
            'width' => $width,
            'height' => $height,
            'bytes' => strlen($jpeg),
            'sha256' => hash('sha256', $jpeg),
        ]);
    }

    public static function disk(): string
    {
        return (string) config('filesystems.private', 'local');
    }

    private function detectType(string $bytes): ?string
    {
        $head = substr($bytes, 0, 32);

        return match (true) {
            str_starts_with($head, "\xFF\xD8\xFF") => 'jpeg',
            str_starts_with($head, "\x89PNG\r\n\x1A\n") => 'png',
            str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WEBP' => 'webp',
            substr($head, 4, 4) === 'ftyp' && in_array(substr($head, 8, 4), ['heic', 'heix', 'hevc', 'hevx', 'heim', 'heis', 'mif1', 'msf1'], true) => 'heic',
            default => null,
        };
    }

    /** @return \GdImage */
    private function decodeWithGd(string $bytes, string $type)
    {
        $size = @getimagesizefromstring($bytes);
        if ($size === false || ($size[0] * $size[1]) > self::MAX_SOURCE_PIXELS) {
            throw $this->fail('That photo could not be read or is too large. Please take it again.');
        }
        $image = @imagecreatefromstring($bytes);
        if ($image === false) {
            throw $this->fail('That photo could not be read. Please take it again.');
        }

        // JPEG has no transparency; avoid a second full-size canvas.
        return $type === 'jpeg' ? $image : $this->flatten($image);
    }

    /** iPhone HEIC/HEIF originals: convert with Imagick when the server supports it. */
    private function decodeWithImagick(string $bytes)
    {
        $heicMessage = 'This iPhone photo format (HEIC) could not be processed. Use "Take Photo", or choose the photo again.';
        if (! class_exists(\Imagick::class)) {
            throw $this->fail($heicMessage);
        }

        try {
            $imagick = new \Imagick;
            $imagick->pingImageBlob($bytes);
            if (($imagick->getImageWidth() * $imagick->getImageHeight()) > self::MAX_SOURCE_PIXELS) {
                throw $this->fail('That photo is too large. Please take it again.');
            }
            $imagick->clear();
            $imagick = new \Imagick;
            $imagick->readImageBlob($bytes);
            $imagick->autoOrient();
            $imagick->setImageFormat('png');
            $png = $imagick->getImageBlob();
            $imagick->clear();
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            Log::warning('Take-home inspection HEIC decode failed.', ['error' => $exception->getMessage()]);
            throw $this->fail($heicMessage);
        }

        $image = @imagecreatefromstring($png);
        if ($image === false) {
            throw $this->fail($heicMessage);
        }

        return $this->flatten($image);
    }

    /** Apply the camera's EXIF orientation to JPEG originals (HEIC is oriented by Imagick). */
    private function orient($image, string $path, string $type)
    {
        if ($type !== 'jpeg' || $path === '' || ! function_exists('exif_read_data')) {
            return $image;
        }
        $exif = @exif_read_data($path);
        $orientation = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;
        $rotated = match ($orientation) {
            3 => imagerotate($image, 180, 0),
            6 => imagerotate($image, -90, 0),
            8 => imagerotate($image, 90, 0),
            default => null,
        };
        if ($rotated instanceof \GdImage) {
            imagedestroy($image);

            return $rotated;
        }

        return $image;
    }

    private function bound($image)
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $longest = max($width, $height);
        if ($longest <= self::MAX_EDGE) {
            return $image;
        }
        $scale = self::MAX_EDGE / $longest;
        $resized = imagescale($image, max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale)), IMG_BICUBIC);
        if ($resized === false) {
            return $image;
        }
        imagedestroy($image);

        return $resized;
    }

    /** Paint transparent pixels (PNG/WebP) onto white before JPEG encoding. */
    private function flatten($image)
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $canvas = imagecreatetruecolor($width, $height);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        imagecopy($canvas, $image, 0, 0, 0, 0, $width, $height);
        imagedestroy($image);

        return $canvas;
    }

    private function fail(string $message): ValidationException
    {
        return ValidationException::withMessages(['photo' => $message]);
    }
}
