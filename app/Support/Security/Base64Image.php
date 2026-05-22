<?php

declare(strict_types=1);

namespace App\Support\Security;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class Base64Image
{
    public const DEFAULT_MAX_BYTES = 5_242_880;

    /**
     * Decode, magic-byte validate, and store a base64 image. Returns null when
     * the payload is absent or invalid.
     */
    public static function store(
        string $payload,
        string $directory,
        string $prefix,
        string $disk = 'public',
        int $maxBytes = self::DEFAULT_MAX_BYTES
    ): ?string {
        $image = self::decode($payload, $maxBytes);
        if ($image === null) {
            return null;
        }

        $safePrefix = trim(Str::slug($prefix), '-');
        $safePrefix = $safePrefix !== '' ? $safePrefix : 'image';
        $filename = sprintf(
            '%s_%s_%s.%s',
            $safePrefix,
            now()->format('Ymd_His'),
            Str::random(8),
            $image['extension']
        );

        $path = trim($directory, '/') . '/' . $filename;
        Storage::disk($disk)->put($path, $image['bytes']);

        return $path;
    }

    /**
     * @return array{bytes: string, extension: string, mime: string}|null
     */
    public static function decode(string $payload, int $maxBytes = self::DEFAULT_MAX_BYTES): ?array
    {
        $payload = trim($payload);
        if ($payload === '') {
            return null;
        }

        $declaredSubtype = null;
        if (preg_match('/^data:image\/([a-z0-9.+-]+);base64,(.*)$/is', $payload, $matches)) {
            $declaredSubtype = strtolower($matches[1]);
            $payload = $matches[2];
        } elseif (str_starts_with(strtolower($payload), 'data:')) {
            return null;
        }

        $encoded = preg_replace('/\s+/', '', $payload) ?? '';
        if ($encoded === '' || ! preg_match('/^[A-Za-z0-9+\/]+={0,2}$/', $encoded)) {
            return null;
        }

        $bytes = base64_decode($encoded, true);
        if ($bytes === false || strlen($bytes) < 12 || strlen($bytes) > $maxBytes) {
            return null;
        }

        $detected = self::detect($bytes);
        if ($detected === null) {
            return null;
        }

        if ($declaredSubtype !== null && ! self::declaredSubtypeMatches($declaredSubtype, $detected['extension'])) {
            return null;
        }

        return [
            'bytes' => $bytes,
            'extension' => $detected['extension'],
            'mime' => $detected['mime'],
        ];
    }

    /**
     * @return array{extension: string, mime: string}|null
     */
    private static function detect(string $bytes): ?array
    {
        if (str_starts_with($bytes, "\xFF\xD8\xFF")) {
            return ['extension' => 'jpg', 'mime' => 'image/jpeg'];
        }

        if (str_starts_with($bytes, "\x89PNG\r\n\x1A\n")) {
            return ['extension' => 'png', 'mime' => 'image/png'];
        }

        if (substr($bytes, 0, 4) === 'RIFF' && substr($bytes, 8, 4) === 'WEBP') {
            return ['extension' => 'webp', 'mime' => 'image/webp'];
        }

        if (str_starts_with($bytes, 'GIF87a') || str_starts_with($bytes, 'GIF89a')) {
            return ['extension' => 'gif', 'mime' => 'image/gif'];
        }

        return null;
    }

    private static function declaredSubtypeMatches(string $declaredSubtype, string $extension): bool
    {
        return match ($declaredSubtype) {
            'jpeg', 'jpg', 'pjpeg' => $extension === 'jpg',
            'png', 'x-png' => $extension === 'png',
            'webp' => $extension === 'webp',
            'gif' => $extension === 'gif',
            default => false,
        };
    }
}
