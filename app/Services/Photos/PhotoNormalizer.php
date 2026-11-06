<?php

declare(strict_types=1);

namespace App\Services\Photos;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One rule set for member photos (Daily Checkout and Take Home inspections).
 *
 * The apps resize and re-encode on the phone, so the usual upload is a clean JPEG
 * of about half a megabyte that is stored exactly as received ("fast path"). Anything
 * else (an original camera file the phone could not convert: HEIC/HEIF, PNG, WebP,
 * a JPEG that still carries EXIF/GPS, or a large original) is converted later by a
 * queued job with toJpeg(): decoded by signature, oriented, bounded to MAX_EDGE and
 * re-encoded, which drops all metadata.
 */
final class PhotoNormalizer
{
    /** Long edge of a stored photo. */
    public const MAX_EDGE = 2048;

    /** Older cached app versions still resize to 2400 px; such a clean JPEG is kept as sent. */
    public const FAST_PATH_MAX_EDGE = 2400;

    /** A clean JPEG up to this size is stored as received; anything larger is re-encoded. */
    public const FAST_PATH_MAX_BYTES = 2_621_440;

    public const MAX_SOURCE_PIXELS = 60_000_000;

    /** Above this, a non-HEIC original is decoded (and shrunk) by Imagick instead of a full GD canvas. */
    private const GD_MAX_PIXELS = 24_000_000;

    public const JPEG_QUALITY = 82;

    /** @var array<string, string> */
    public const EXTENSIONS = ['jpeg' => 'jpg', 'png' => 'png', 'webp' => 'webp', 'gif' => 'gif', 'heic' => 'heic'];

    /** jpeg | png | webp | gif | heic, or null when the bytes are not a supported photo. */
    public function detectType(string $bytes): ?string
    {
        $head = substr($bytes, 0, 32);

        return match (true) {
            str_starts_with($head, "\xFF\xD8\xFF") => 'jpeg',
            str_starts_with($head, "\x89PNG\r\n\x1A\n") => 'png',
            str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WEBP' => 'webp',
            str_starts_with($head, 'GIF87a') || str_starts_with($head, 'GIF89a') => 'gif',
            substr($head, 4, 4) === 'ftyp' && in_array(substr($head, 8, 4), ['heic', 'heix', 'hevc', 'hevx', 'heim', 'heis', 'mif1', 'msf1'], true) => 'heic',
            default => null,
        };
    }

    /**
     * Pixel size read from the header only (no decode). HEIC returns null: it is only
     * measured by the queued conversion.
     *
     * @return array{0: int, 1: int}|null
     */
    public function dimensions(string $bytes): ?array
    {
        $size = @getimagesizefromstring($bytes);

        return is_array($size) && $size[0] > 0 && $size[1] > 0 ? [(int) $size[0], (int) $size[1]] : null;
    }

    /** True when the bytes may be stored exactly as received. */
    public function isFastPath(string $bytes, string $type): bool
    {
        if ($type !== 'jpeg' || strlen($bytes) > self::FAST_PATH_MAX_BYTES || $this->hasCameraMetadata($bytes)) {
            return false;
        }
        $size = $this->dimensions($bytes);

        return $size !== null && max($size) <= self::FAST_PATH_MAX_EDGE;
    }

    /**
     * True when a JPEG still carries camera metadata: location (GPS), camera make/model,
     * capture time, a rotation flag, or an XMP packet. A browser canvas re-encode writes
     * none of these (Safari may add a bare colour-space EXIF block, which is harmless).
     */
    public function hasCameraMetadata(string $bytes): bool
    {
        $segment = $this->firstApp1Segment($bytes);
        if ($segment === null) {
            return false;
        }
        if (! str_starts_with($segment, "Exif\0\0")) {
            return true; // XMP or unknown: may hold location, re-encode.
        }
        if (! function_exists('exif_read_data')) {
            return true;
        }
        $stream = fopen('php://memory', 'r+b');
        if ($stream === false) {
            return true;
        }
        fwrite($stream, $bytes);
        rewind($stream);
        $exif = @exif_read_data($stream);
        fclose($stream);
        if (! is_array($exif)) {
            return true;
        }
        if ((int) ($exif['Orientation'] ?? 1) > 1) {
            return true;
        }
        foreach (array_keys($exif) as $key) {
            if (str_starts_with((string) $key, 'GPS') || in_array($key, ['Make', 'Model', 'DateTimeOriginal', 'SerialNumber', 'BodySerialNumber'], true)) {
                return true;
            }
        }

        return false;
    }

    /** Payload of the first APP1 segment before the image data, or null when there is none. */
    private function firstApp1Segment(string $bytes): ?string
    {
        $length = strlen($bytes);
        $offset = 2;
        while ($offset + 4 <= $length) {
            if ($bytes[$offset] !== "\xFF") {
                return ''; // Malformed: treat as unknown metadata.
            }
            $marker = ord($bytes[$offset + 1]);
            if ($marker === 0xD9 || $marker === 0xDA) {
                return null; // End of image or start of scan: no metadata segment came first.
            }
            $segment = (ord($bytes[$offset + 2]) << 8) | ord($bytes[$offset + 3]);
            if ($segment < 2) {
                return '';
            }
            if ($marker === 0xE1) {
                return substr($bytes, $offset + 4, $segment - 2);
            }
            $offset += 2 + $segment;
        }

        return null;
    }

    /**
     * Decode any supported photo and return a fresh, oriented, bounded JPEG without metadata.
     *
     * @return array{bytes: string, width: int, height: int}
     *
     * @throws PhotoUnreadable
     */
    public function toJpeg(string $bytes): array
    {
        $type = $this->detectType($bytes);
        if ($type === null) {
            throw new PhotoUnreadable('That file is not a supported photo.');
        }

        $image = $type === 'heic' ? $this->decodeWithImagick($bytes) : $this->decodeWithGd($bytes, $type);
        if ($type === 'jpeg') {
            $image = $this->orient($image, $bytes);
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
            throw new PhotoUnreadable('The photo could not be encoded.');
        }

        return ['bytes' => $jpeg, 'width' => $width, 'height' => $height];
    }

    private function decodeWithGd(string $bytes, string $type): \GdImage
    {
        $size = $this->dimensions($bytes);
        if ($size === null || ($size[0] * $size[1]) > self::MAX_SOURCE_PIXELS) {
            throw new PhotoUnreadable('The photo could not be read or is too large.');
        }
        if (($size[0] * $size[1]) > self::GD_MAX_PIXELS && class_exists(\Imagick::class)) {
            // A 50-200 MP original would need ~0.2-0.8 GB as a GD canvas; Imagick shrinks it while decoding.
            return $this->decodeWithImagick($bytes);
        }
        $image = @imagecreatefromstring($bytes);
        if (! $image instanceof \GdImage) {
            throw new PhotoUnreadable('The photo could not be read.');
        }

        // JPEG has no transparency; avoid a second full-size canvas.
        return $type === 'jpeg' ? $image : $this->flatten($image);
    }

    /** iPhone HEIC/HEIF originals, converted with Imagick (libheif) when the server has it. */
    private function decodeWithImagick(string $bytes): \GdImage
    {
        if (! class_exists(\Imagick::class)) {
            throw new PhotoUnreadable('HEIC photos cannot be converted on this server.');
        }

        try {
            $imagick = new \Imagick;
            $imagick->pingImageBlob($bytes);
            if (($imagick->getImageWidth() * $imagick->getImageHeight()) > self::MAX_SOURCE_PIXELS) {
                throw new PhotoUnreadable('The photo is too large.');
            }
            $imagick->clear();
            $imagick = new \Imagick;
            $imagick->readImageBlob($bytes);
            $imagick->autoOrient();
            $longest = max($imagick->getImageWidth(), $imagick->getImageHeight());
            if ($longest > self::MAX_EDGE) {
                // Shrink before handing the pixels to GD: a 48 MP original would otherwise need ~200 MB.
                $imagick->thumbnailImage(self::MAX_EDGE, self::MAX_EDGE, true);
            }
            $imagick->setImageFormat('png');
            $png = $imagick->getImageBlob();
            $imagick->clear();
        } catch (PhotoUnreadable $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            Log::warning('A HEIC photo could not be converted.', ['error' => $exception->getMessage()]);
            throw new PhotoUnreadable('The HEIC photo could not be converted.');
        }

        $image = @imagecreatefromstring($png);
        if (! $image instanceof \GdImage) {
            throw new PhotoUnreadable('The HEIC photo could not be converted.');
        }

        return $this->flatten($image);
    }

    /** Apply the camera's EXIF orientation (HEIC is oriented by Imagick). */
    private function orient(\GdImage $image, string $bytes): \GdImage
    {
        if (! function_exists('exif_read_data')) {
            return $image;
        }
        $stream = fopen('php://memory', 'r+b');
        if ($stream === false) {
            return $image;
        }
        fwrite($stream, $bytes);
        rewind($stream);
        $exif = @exif_read_data($stream);
        fclose($stream);
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

    private function bound(\GdImage $image): \GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $longest = max($width, $height);
        if ($longest <= self::MAX_EDGE) {
            return $image;
        }
        $scale = self::MAX_EDGE / $longest;
        $resized = imagescale($image, max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale)), IMG_BICUBIC);
        if (! $resized instanceof \GdImage) {
            return $image;
        }
        imagedestroy($image);

        return $resized;
    }

    /** Paint transparent pixels (PNG/WebP/GIF) onto white before JPEG encoding. */
    private function flatten(\GdImage $image): \GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $canvas = imagecreatetruecolor($width, $height);
        imagefill($canvas, 0, 0, (int) imagecolorallocate($canvas, 255, 255, 255));
        imagecopy($canvas, $image, 0, 0, 0, 0, $width, $height);
        imagedestroy($image);

        return $canvas;
    }
}
