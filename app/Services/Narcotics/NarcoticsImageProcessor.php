<?php

declare(strict_types=1);

namespace App\Services\Narcotics;

use App\Services\Ems\EmsException;
use GdImage;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Turns an uploaded file into three clean sizes of one photo (docs/narcotics-check.md section 4).
 *
 *  1. Size cap, then the file TYPE comes from its signature, never from its name or declared MIME.
 *  2. A file that carries script markers (an image with PHP/HTML appended: a polyglot) is refused.
 *  3. Pixel and memory caps are checked from the header before a single pixel is decoded.
 *  4. The image is decoded and re-encoded from pixels, so every byte that was not picture data
 *     (EXIF, GPS, thumbnails, trailing payloads) is gone. The EXIF orientation is applied first.
 *  5. Transparent areas become white; the long edge is bounded; the smallest of WebP and JPEG that
 *     the server can write is kept (quality high enough that labels and counts stay legible).
 *
 * Failures are EmsException (422, field "photo") with a message a member can act on.
 */
final class NarcoticsImageProcessor
{
    /** @var list<string> */
    private const HEIC_BRANDS = ['heic', 'heix', 'hevc', 'hevx', 'heim', 'heis', 'mif1', 'msf1'];

    /**
     * Long enough that they do not occur by chance in a multi-megabyte photo (a 3-byte marker such as
     * "<%@" appears in about 1 in 8 random 2 MB files). The re-encode from decoded pixels is what
     * actually removes any appended bytes; this only refuses the obvious polyglot early.
     */
    private const SCRIPT_MARKERS = ['<?php', '<script'];

    public function process(string $path): ProcessedImage
    {
        $limits = (array) config('narcotics_checks.image');
        $size = @filesize($path);
        if ($size === false || $size < 1) {
            throw $this->refuse('The photo could not be read. Please take it again.');
        }
        if ($size > ((int) $limits['max_upload_kb']) * 1024) {
            throw $this->refuse('That photo is too large to send. Use "Take photo", or choose a smaller photo.', 'photo_too_large');
        }
        $bytes = @file_get_contents($path);
        if (! is_string($bytes) || $bytes === '') {
            throw $this->refuse('The photo could not be read. Please take it again.');
        }

        $type = $this->detectType($bytes);
        if ($type === null) {
            throw $this->refuse('That file is not a supported photo. Use a JPEG, PNG, WebP or iPhone (HEIC) photo.', 'photo_type');
        }
        $this->refuseScriptContent($bytes);

        $image = $type === 'heic' ? $this->decodeHeic($bytes, $limits) : $this->decodeGd($bytes, $limits);
        try {
            if ($type === 'jpeg') {
                $image = $this->orient($image, ExifOrientation::fromJpeg($bytes));
            }
            unset($bytes);
            $image = $this->flatten($image);
            if (max(imagesx($image), imagesy($image)) < (int) $limits['min_edge']) {
                throw $this->refuse('That photo is too small to read. Please take it again closer to what you are checking.', 'photo_small');
            }
            $image = $this->bound($image, (int) $limits['full_edge']);

            return $this->encodeAll($image, $limits);
        } finally {
            unset($image);
        }
    }

    /** Whether this server can write WebP (otherwise everything is JPEG). */
    public static function webpAvailable(): bool
    {
        return function_exists('imagewebp') && (bool) (gd_info()['WebP Support'] ?? false);
    }

    /** The MIME types the form tells the browser it may send (browser-side encoding is WebP or JPEG). */
    public static function acceptedMimeTypes(): array
    {
        return array_values(array_filter([
            'image/jpeg', 'image/png',
            self::webpAvailable() ? 'image/webp' : null,
        ]));
    }

    private function detectType(string $bytes): ?string
    {
        $head = substr($bytes, 0, 32);

        return match (true) {
            str_starts_with($head, "\xFF\xD8\xFF") => 'jpeg',
            str_starts_with($head, "\x89PNG\r\n\x1A\n") => 'png',
            str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WEBP' => 'webp',
            substr($head, 4, 4) === 'ftyp' && in_array(substr($head, 8, 4), self::HEIC_BRANDS, true) => 'heic',
            default => null,
        };
    }

    private function refuseScriptContent(string $bytes): void
    {
        foreach (self::SCRIPT_MARKERS as $marker) {
            if (stripos($bytes, $marker) !== false) {
                throw $this->refuse('That file is not a plain photo. Take a new photo with the camera and send that.', 'photo_unsafe');
            }
        }
    }

    /** @param array<string, mixed> $limits */
    private function decodeGd(string $bytes, array $limits): GdImage
    {
        $info = @getimagesizefromstring($bytes);
        if ($info === false || $info[0] < 1 || $info[1] < 1) {
            throw $this->refuse('That photo could not be read. Please take it again.');
        }
        $this->guardDecode((int) $info[0], (int) $info[1], $limits);
        $image = @imagecreatefromstring($bytes);
        if (! $image instanceof GdImage) {
            throw $this->refuse('That photo could not be read. Please take it again.');
        }

        return $image;
    }

    /** iPhone originals: converted with Imagick when the server has it. */
    private function decodeHeic(string $bytes, array $limits): GdImage
    {
        $message = 'This iPhone photo format (HEIC) could not be processed. Use "Take photo", or choose the photo again.';
        if (! class_exists(\Imagick::class)) {
            throw $this->refuse($message, 'photo_type');
        }
        try {
            $probe = new \Imagick;
            $probe->pingImageBlob($bytes);
            $this->guardDecode((int) $probe->getImageWidth(), (int) $probe->getImageHeight(), $limits);
            $probe->clear();
            $imagick = new \Imagick;
            $imagick->readImageBlob($bytes);
            $imagick->autoOrient();
            $imagick->setImageFormat('png');
            $png = $imagick->getImageBlob();
            $imagick->clear();
        } catch (EmsException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            Log::warning('Narcotics check HEIC decode failed.', ['error' => $exception->getMessage()]);
            throw $this->refuse($message, 'photo_type');
        }
        $image = @imagecreatefromstring($png);
        if (! $image instanceof GdImage) {
            throw $this->refuse($message, 'photo_type');
        }

        return $image;
    }

    /** @param array<string, mixed> $limits */
    private function guardDecode(int $width, int $height, array $limits): void
    {
        if ($width * $height > (int) $limits['max_source_pixels']) {
            throw $this->refuse('That photo is too large to process. Use "Take photo", or choose a smaller photo.', 'photo_too_large');
        }
        $memory = $this->memoryLimitBytes();
        // GD holds ~4 bytes per pixel, plus a second copy while it rotates or flattens.
        if ($memory !== null && ($width * $height * 8) + memory_get_usage() > $memory) {
            throw $this->refuse('That photo is too large to process. Use "Take photo", or choose a smaller photo.', 'photo_too_large');
        }
    }

    private function memoryLimitBytes(): ?int
    {
        $raw = trim((string) ini_get('memory_limit'));
        if ($raw === '' || $raw === '-1') {
            return null;
        }
        $number = (int) $raw;

        return match (strtolower(substr($raw, -1))) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => $number,
        };
    }

    /** Stand the picture upright. EXIF values: 2 mirror, 3 half turn, 4 flip, 5 transpose, 6 right, 7 transverse, 8 left. */
    private function orient(GdImage $image, int $orientation): GdImage
    {
        if ($orientation <= 1) {
            return $image;
        }
        if (in_array($orientation, [2, 5, 7], true)) {
            imageflip($image, IMG_FLIP_HORIZONTAL);
        }
        if ($orientation === 4) {
            imageflip($image, IMG_FLIP_VERTICAL);
        }
        $degrees = match ($orientation) {
            3 => 180,
            6, 7 => -90,
            5, 8 => 90,
            default => 0,
        };
        if ($degrees === 0) {
            return $image;
        }
        $rotated = imagerotate($image, $degrees, 0);
        if (! $rotated instanceof GdImage) {
            return $image;
        }

        return $rotated;
    }

    /** Paint transparency onto white and make sure the canvas is true colour. */
    private function flatten(GdImage $image): GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $canvas = imagecreatetruecolor($width, $height);
        imagefill($canvas, 0, 0, (int) imagecolorallocate($canvas, 255, 255, 255));
        imagecopy($canvas, $image, 0, 0, 0, 0, $width, $height);

        return $canvas;
    }

    private function bound(GdImage $image, int $edge): GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $longest = max($width, $height);
        if ($longest <= $edge) {
            return $image;
        }
        $scale = $edge / $longest;
        $resized = imagescale($image, max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale)), IMG_BICUBIC);
        if (! $resized instanceof GdImage) {
            return $image;
        }

        return $resized;
    }

    /** @param array<string, mixed> $limits */
    private function encodeAll(GdImage $full, array $limits): ProcessedImage
    {
        $width = imagesx($full);
        $height = imagesy($full);
        [$mime, $extension, $fullBytes] = $this->encodeSmallest($full, $limits);

        $preview = $this->encodeAs($this->scaledCopy($full, (int) $limits['preview_edge']), $mime, $limits);
        $thumb = $this->encodeAs($this->scaledCopy($full, (int) $limits['thumb_edge']), $mime, $limits);

        return new ProcessedImage($mime, $extension, $fullBytes, $width, $height, $preview, $thumb);
    }

    /** A bounded copy that is destroyed by encodeAs(); when no scaling is needed the same pixels are copied. */
    private function scaledCopy(GdImage $image, int $edge): GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $longest = max($width, $height);
        $scale = min(1.0, $edge / $longest);
        $copy = imagescale($image, max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale)), IMG_BICUBIC);
        if ($copy instanceof GdImage) {
            return $copy;
        }
        $fallback = imagecreatetruecolor($width, $height);
        imagecopy($fallback, $image, 0, 0, 0, 0, $width, $height);

        return $fallback;
    }

    /**
     * @param  array<string, mixed>  $limits
     * @return array{0: string, 1: string, 2: string} mime, extension, bytes
     */
    private function encodeSmallest(GdImage $image, array $limits): array
    {
        $jpeg = $this->jpegBytes($image, (int) $limits['jpeg_quality']);
        if (self::webpAvailable()) {
            $webp = $this->webpBytes($image, (int) $limits['webp_quality']);
            if ($webp !== '' && ($jpeg === '' || strlen($webp) < strlen($jpeg))) {
                return ['image/webp', 'webp', $webp];
            }
        }
        if ($jpeg === '') {
            throw $this->refuse('The photo could not be processed. Please try again.');
        }

        return ['image/jpeg', 'jpg', $jpeg];
    }

    /** @param array<string, mixed> $limits */
    private function encodeAs(GdImage $image, string $mime, array $limits): string
    {
        $bytes = $mime === 'image/webp'
            ? $this->webpBytes($image, (int) $limits['webp_quality'])
            : $this->jpegBytes($image, (int) $limits['jpeg_quality']);
        unset($image);
        if ($bytes === '') {
            throw $this->refuse('The photo could not be processed. Please try again.');
        }

        return $bytes;
    }

    private function jpegBytes(GdImage $image, int $quality): string
    {
        ob_start();
        imageinterlace($image, true);
        $ok = imagejpeg($image, null, $quality);
        $bytes = (string) ob_get_clean();

        return $ok ? $bytes : '';
    }

    private function webpBytes(GdImage $image, int $quality): string
    {
        ob_start();
        $ok = @imagewebp($image, null, $quality);
        $bytes = (string) ob_get_clean();

        return $ok ? $bytes : '';
    }

    private function refuse(string $message, string $code = 'photo_invalid'): EmsException
    {
        return new EmsException($message, $code, 422, ['photo' => [$message]]);
    }
}
